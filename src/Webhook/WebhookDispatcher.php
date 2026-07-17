<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Webhook;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Database\Reports;
use SitePulseAnalytics\Settings\Options;

/**
 * Sends aggregated analytics to the configured webhook endpoints as JSON.
 *
 * A single cron event (spa_dispatch_webhooks) fires at the interval chosen in
 * settings. On each run, every configured endpoint receives a POST whose
 * payload covers the window since that endpoint's last successful delivery —
 * last-sent timestamps are tracked per endpoint, so adding a new endpoint or
 * a transient failure at one endpoint never skews the data another receives.
 * A gap materially longer than one interval (downtime, a paused toggle, or a
 * backfilled first send — see Options::webhookBackfill()) is delivered as
 * consecutive interval-sized windows rather than one coarse catch-all window.
 * When a signing secret is configured, every request is HMAC-signed (see
 * sendToEndpoint()).
 *
 * Retry handling:
 *  When a delivery fails (transport error or a non-2xx status), the exact
 *  JSON body that failed is FROZEN — serialized and stored with the retry
 *  state — and retried up to 5 more times over about 24 hours — after
 *  5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours — via single-event
 *  crons on the spa_retry_webhook hook. Every attempt re-sends the stored
 *  bytes under the same delivery_id (retention cleanup, settings changes, or
 *  plugin updates between attempts can never alter the payload), and
 *  scheduled runs skip an endpoint while its chain is actively pending.
 *
 *  If the chain is exhausted — or a retry cron could not be scheduled — the
 *  frozen delivery is NOT discarded: it is kept in an "exhausted" state and
 *  the next scheduled dispatch re-sends that exact body under the same
 *  delivery_id (restarting the chain on another failure). Only after the
 *  frozen delivery is acknowledged does the endpoint advance to newer events,
 *  so consecutive deliveries never cover overlapping windows and any true
 *  duplicate always carries the delivery_id the receiver has already seen.
 *  Delivery is therefore at-least-once, and deduplicating by delivery_id
 *  (also sent as an Idempotency-Key header) is sufficient to never
 *  double-count.
 *
 *  Frozen deliveries do not live forever: a chain whose delivery was frozen
 *  longer ago than the data retention window is dropped by the next dispatch
 *  run (the site no longer holds the underlying events either), and the
 *  settings page offers an explicit Discard action per pending retry.
 *
 *  Every mutation of the shared retry-state option happens while holding the
 *  dispatch mutex. A retry that cannot acquire the lock only re-schedules its
 *  cron event and touches nothing else; if even that fails, the chain is
 *  detected as ORPHANED by the next dispatch run (state pending, but no cron
 *  event scheduled and well past due) and resumed exactly like an exhausted
 *  one — under the same frozen bytes and delivery_id.
 *
 * Delivery scatter:
 *  The recurring cron is anchored at a random offset within the send interval
 *  (capped at 24 hours) rather than at the moment of scheduling. When many
 *  sites running this plugin share one endpoint and the same interval, their
 *  deliveries land at different, stable times of day instead of stampeding
 *  the endpoint simultaneously.
 *
 * Concurrency:
 *  A dispatch run can perform up to ten report windows per endpoint, each
 *  involving many aggregate queries and an HTTP request with a 15-second
 *  timeout — long enough to overlap the next cron trigger. Every dispatch
 *  (and every retry) therefore runs under a site-wide mutex (see
 *  {@see acquireLock()}): overlapping cron executions can never read the
 *  same last-sent marker and build overlapping windows under different
 *  delivery IDs. A run that cannot get the lock simply yields — the run
 *  holding it is already doing the work.
 *
 * Every delivery attempt — scheduled, retry, or test — is recorded in the
 * {@see DeliveryLog} table with the exact payload sent and the response
 * received, powering the Delivery Log admin page and the read-only
 * deliveries REST API.
 */
final class WebhookDispatcher
{
    /** @var string Cron hook name for scheduled dispatch. */
    public const CRON_HOOK = 'spa_dispatch_webhooks';

    /** @var string Cron hook name for single-event delivery retries. */
    public const RETRY_HOOK = 'spa_retry_webhook';

    /**
     * Delay before each retry attempt, in seconds:
     * 5 minutes, 30 minutes, 2 hours, 6 hours, 16 hours (~24.6 hours total).
     *
     * @var int[]
     */
    private const RETRY_DELAYS = [300, 1800, 7200, 21600, 57600];

    /** @var string Option key mapping md5(endpoint URL) → last-success unix timestamp. */
    private const LAST_SENT_OPTION = 'spa_webhook_last_sent';

    /** @var string Option key mapping md5(endpoint URL) → pending retry state. */
    private const RETRY_STATE_OPTION = 'spa_webhook_retry_state';

    /** @var string Option key for the fallback dispatch mutex (when GET_LOCK is unavailable). */
    private const LOCK_OPTION = 'spa_webhook_dispatch_lock';

    /**
     * Seconds after which a fallback option lock's LEASE is considered stale
     * and the lock may be stolen. Healthy runs renew their lease between
     * delivery windows (see {@see renewLock()}), so even a run that legally
     * exceeds this duration is never mistaken for dead — only a lock whose
     * holder stopped renewing (fatal error, killed process) goes stale, and
     * then dispatch is wedged for at most this long.
     *
     * @var int
     */
    private const LOCK_TIMEOUT = 15 * MINUTE_IN_SECONDS;

    /** @var int HTTP timeout for webhook requests, in seconds. */
    private const TIMEOUT = 15;

    /**
     * Maximum individual conversions per delivery. A window holding more is
     * SHRUNK to end at the overflowing conversion (see freezeDelivery()), so
     * the overflow becomes the next delivery's window instead of being
     * silently dropped — conversion delivery is lossless.
     *
     * @var int
     */
    private const MAX_CONVERSIONS_PER_DELIVERY = 100;

    /** @var int Maximum consecutive windows one endpoint may send per dispatch run, bounding catch-up work. */
    private const MAX_WINDOWS_PER_RUN = 10;

    /**
     * Maximum rows per "top_*" list in the webhook payload (filterable via
     * 'spa_webhook_report_limit'). Deliberately much deeper than the
     * dashboard's top 10: a receiver aggregating deliveries long-term needs
     * (near-)complete dimension rankings — an item missing from one window's
     * list can never be reconstructed later. 200 keeps payloads bounded
     * while being effectively complete for a typical site's daily window.
     *
     * @var int
     */
    private const REPORT_ROW_LIMIT = 200;

    /**
     * Registers the cron callbacks and the settings-change listener.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'dispatch']);
        add_action(self::RETRY_HOOK, [self::class, 'retry'], 10, 1);
        add_action('update_option_' . Options::OPTION_KEY, [self::class, 'onSettingsSaved'], 10, 2);
    }

    /**
     * Schedules the dispatch cron event if it is not already scheduled.
     *
     * Called on activation and as a safety net on every load. The first run
     * is offset by {@see scatterOffset()}; WordPress anchors every subsequent
     * recurrence to that timestamp, so the scattered send time is stable for
     * the lifetime of the schedule.
     *
     * @return void
     */
    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + self::scatterOffset(), Options::webhookInterval(), self::CRON_HOOK);
        }
    }

    /**
     * Re-schedules the cron event when the admin changes the send interval.
     *
     * Hooked to update_option_spa_settings, which only fires when the stored
     * value actually changed. The new schedule gets a fresh random anchor so
     * fleets reconfigured together do not end up synchronized.
     *
     * @param mixed $oldValue Previous settings array.
     * @param mixed $newValue New settings array.
     * @return void
     */
    public static function onSettingsSaved(mixed $oldValue, mixed $newValue): void
    {
        $oldInterval = is_array($oldValue) ? ($oldValue['webhook_interval'] ?? 'daily') : 'daily';
        $newInterval = is_array($newValue) ? ($newValue['webhook_interval'] ?? 'daily') : 'daily';

        if ($oldInterval === $newInterval) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time() + self::scatterOffset(), Options::webhookInterval(), self::CRON_HOOK);
    }

    /**
     * Random offset applied to the schedule anchor, in seconds.
     *
     * Many independent sites with the same interval and endpoint would
     * otherwise deliver in near-synchrony — bulk-provisioned fleets share an
     * activation moment, and sites that trigger WP-Cron from a system cron
     * all fire on hour/quarter-hour boundaries. Anchoring each site's
     * schedule at a random point within the interval (at least one minute
     * out, capped at 24 hours so a weekly report is not delayed most of a
     * week) breaks the herd while keeping each site's send time stable.
     *
     * @return int Seconds between 60 and min(interval, 24h).
     */
    private static function scatterOffset(): int
    {
        $window = min(Options::intervalSeconds(Options::webhookInterval()), DAY_IN_SECONDS);

        return wp_rand(MINUTE_IN_SECONDS, max(2 * MINUTE_IN_SECONDS, $window));
    }

    /**
     * Cron callback: sends analytics to every configured endpoint.
     *
     * Endpoints with an actively pending retry chain are skipped — the chain
     * owns its frozen delivery, and sending a fresh, larger window under a
     * different delivery_id while the receiver may have processed (but not
     * acknowledged) the frozen one would fork the bookkeeping. An endpoint
     * whose chain was exhausted resumes here: the frozen body is re-sent
     * under its original delivery_id first, and only once it is acknowledged
     * does a fresh window (starting exactly at the frozen window's end) go
     * out. Bookkeeping for endpoints that were removed from settings is
     * pruned first.
     *
     * Skipped entirely while the "Webhook Status" setting is inactive — no
     * new or resumed deliveries are attempted, though a retry chain already
     * scheduled before the toggle was flipped off is left to run its course
     * (see {@see Options::webhooksActive()}).
     *
     * @return void
     */
    public static function dispatch(): void
    {
        if (!Options::webhooksActive()) {
            return;
        }

        $urls = Options::webhookUrls();
        if ($urls === []) {
            return;
        }

        // Only one dispatch may run at a time: two overlapping runs would
        // read the same last-sent marker and deliver overlapping windows
        // under different delivery IDs, breaking the dedup guarantee. The
        // run already holding the lock is doing this exact work.
        $lock = self::acquireLock();
        if ($lock === null) {
            return;
        }

        try {
            self::dispatchLocked($urls, $lock);
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * The body of {@see dispatch()}, run while holding the dispatch mutex.
     *
     * The delivery horizon — the wall-clock moment this run stops reporting
     * at — is captured ONCE, before the endpoint loop. Re-reading time()
     * per endpoint would give each caught-up endpoint a slightly different
     * window end (slow HTTP responses shift it by seconds), producing
     * distinct summary-cache keys and re-running every aggregate query per
     * endpoint; with one horizon, caught-up endpoints share identical
     * windows and one cached summary. Events arriving during the run fall
     * past the horizon and go out with the next run.
     *
     * @param string[] $urls Configured endpoint URLs.
     * @param string   $lock The held dispatch lock, renewed between windows.
     * @return void
     */
    private static function dispatchLocked(array $urls, string $lock): void
    {
        self::pruneStaleState($urls);

        $horizon = time();

        foreach ($urls as $url) {
            $state = self::getRetryStates()[md5($url)] ?? null;

            if ($state !== null) {
                if (self::retryChainActive($url, $state)) {
                    continue; // An active retry chain owns this endpoint.
                }

                // Resume the exhausted (or orphaned) chain: same frozen
                // bytes, same id.
                self::renewLock($lock);
                $delivery = self::deliveryFromState($url, $state);

                if (!self::attemptDelivery($url, $delivery, 'scheduled', 0)) {
                    self::scheduleRetry($url, 1, $delivery);
                    continue;
                }
                // Acknowledged — last-sent now sits at the frozen window's
                // end, so the fresh window below picks up exactly there.
            }

            // A gap longer than ~1.5 send intervals — a backfilled first
            // send, downtime, or a paused toggle — is worked off in
            // interval-sized windows, so history arrives at the same
            // granularity as live deliveries instead of one coarse
            // catch-all window. The 50% slack matters: WP-Cron always
            // drifts a little past the interval, and capping strictly at
            // one interval would make every routine run emit a full window
            // plus a sliver covering the drift. freezeDelivery()
            // additionally shrinks a conversion-heavy window to the
            // per-delivery conversion cap. Either way the backlog is worked
            // off in consecutive non-overlapping windows within this run
            // (bounded, so one endpoint can never pin the cron
            // indefinitely; the remainder resumes next run).
            for ($round = 0; $round < self::MAX_WINDOWS_PER_RUN; $round++) {
                self::renewLock($lock);

                $startTs = self::windowStart($url, $horizon);

                if ($startTs >= $horizon) {
                    break;
                }

                $interval = Options::intervalSeconds(Options::webhookInterval());
                $endTs    = ($horizon - $startTs > $interval + intdiv($interval, 2)) ? $startTs + $interval : $horizon;
                $delivery = self::freezeDelivery($url, $startTs, $endTs);

                if (!self::attemptDelivery($url, $delivery, 'scheduled', 0)) {
                    self::scheduleRetry($url, 1, $delivery);
                    break;
                }

                if ($delivery['window_end'] >= $horizon) {
                    break; // Caught up — the window was not shrunk.
                }
            }
        }
    }

    /**
     * Cron callback for a single retry attempt against one endpoint.
     *
     * Re-sends the chain's frozen delivery — the identical bytes and
     * delivery_id as the attempt that failed. On another failure the next
     * retry in the chain is scheduled; after the final attempt the frozen
     * delivery is kept in an exhausted state so the next scheduled dispatch
     * resumes it instead of moving on to an overlapping window.
     *
     * Every retry-state mutation happens while holding the dispatch mutex.
     * A retry that finds the lock taken only re-schedules its own cron event
     * and returns — writing the shared state option here would race the lock
     * holder, which may be clearing this very chain (delivery acknowledged)
     * or replacing it, and the unlocked write could resurrect or erase it.
     * If the re-schedule fails too, nothing is lost: the chain still says
     * "pending" with no cron event behind it, which the next dispatch run
     * detects as orphaned (see {@see retryChainActive()}) and resumes under
     * the same frozen bytes and delivery_id.
     *
     * @param string $url The endpoint URL this retry targets.
     * @return void
     */
    public static function retry(string $url): void
    {
        $lock = self::acquireLock();
        if ($lock === null) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::RETRY_HOOK, [$url]);
            return;
        }

        try {
            // The endpoint was removed from settings after this retry was
            // scheduled. Checked under the lock, like every state mutation.
            if (!in_array($url, Options::webhookUrls(), true)) {
                self::clearRetry($url);
                return;
            }

            $state = self::getRetryStates()[md5($url)] ?? null;

            // No stored state means the frozen delivery was already
            // acknowledged (or explicitly discarded) while this cron event
            // was in flight. Building a fresh delivery here would violate
            // the same-bytes/same-delivery-id guarantee — stop instead.
            if ($state === null) {
                return;
            }

            $attempt  = (int) ($state['attempt'] ?? 1);
            $delivery = self::deliveryFromState($url, $state);

            if (self::attemptDelivery($url, $delivery, 'retry', $attempt)) {
                return; // Success already cleared the pending retry state.
            }

            if ($attempt < count(self::RETRY_DELAYS)) {
                self::scheduleRetry($url, $attempt + 1, $delivery);
            } else {
                self::storeRetryState($url, $attempt, 0, $delivery, true);
            }
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Whether an endpoint's retry chain still has a live cron attempt coming.
     *
     * Exhausted chains never do — dispatch resumes them. A chain that claims
     * to be pending but has no retry cron event scheduled and whose due time
     * has clearly passed is ORPHANED: its wp_schedule_single_event() call
     * failed (typically while re-scheduling around a held lock, where state
     * is deliberately never written). Orphaned chains are resumed by dispatch
     * exactly like exhausted ones. The grace period covers ordinary WP-Cron
     * lag, so a merely-late retry is not mistaken for a dead one.
     *
     * @param string               $url   Endpoint URL.
     * @param array<string, mixed> $state The endpoint's stored retry state.
     * @return bool
     */
    private static function retryChainActive(string $url, array $state): bool
    {
        if (!empty($state['exhausted'])) {
            return false;
        }

        if (wp_next_scheduled(self::RETRY_HOOK, [$url]) !== false) {
            return true;
        }

        return (int) ($state['scheduled_for'] ?? 0) > time() - 15 * MINUTE_IN_SECONDS;
    }

    /**
     * Sends an immediate test payload (last 7 days) to every configured
     * endpoint without advancing any last-sent timestamps.
     *
     * Test sends never trigger retries — they exist to answer "does this
     * endpoint work right now?", so a failure is simply logged.
     *
     * @return void
     */
    public static function sendTest(): void
    {
        $urls = Options::webhookUrls();
        if ($urls === []) {
            return;
        }

        $now     = time();
        $payload = self::buildPayload($now - 7 * DAY_IN_SECONDS, $now);

        $payload['test'] = true;

        foreach ($urls as $url) {
            $body = $payload;

            // Unique per test run — tests are never retried, so there is no
            // chain to keep the id stable across.
            $body['delivery_id'] = md5($url . '|test|' . $now . '|' . wp_rand());

            $encoded = wp_json_encode($body);

            // Never POST an empty body when encoding fails (e.g. a filter
            // introduced an unencodable value) — log the failure instead.
            if (!is_string($encoded) || $encoded === '') {
                $encoded = '';
                $result  = ['ok' => false, 'code' => 0, 'message' => 'Payload could not be JSON-encoded', 'body' => ''];
            } else {
                $result = self::sendToEndpoint($url, $encoded, $body['delivery_id']);
            }

            DeliveryLog::log(
                $url,
                $result['ok'],
                $result['code'],
                $result['message'],
                'test',
                0,
                $body['delivery_id'],
                $encoded,
                $result['body']
            );
        }
    }

    /**
     * Deterministic delivery id for one endpoint + exact reporting window.
     *
     * A chain freezes its serialized body, so every attempt — including the
     * resumed attempts after an exhausted chain — re-sends the same bytes
     * under the same id; any different window gets a new id. Receivers can
     * therefore treat the id as "same payload, seen before?" with no edge
     * cases.
     *
     * @param string $url     Endpoint URL.
     * @param int    $startTs Window start as a unix timestamp.
     * @param int    $endTs   Window end as a unix timestamp.
     * @return string 32-character hex id.
     */
    private static function deliveryId(string $url, int $startTs, int $endTs): string
    {
        return md5(home_url() . '|' . $url . '|' . $startTs . '|' . $endTs);
    }

    /**
     * Returns every pending retry, for display on the settings page.
     *
     * The stored payload body is stripped — it can be tens of kilobytes and
     * no UI needs it.
     *
     * @return array<int, array{url: string, attempt: int, scheduled_for: int, window_start: int, window_end: int, exhausted: bool}>
     */
    public static function getPendingRetries(): array
    {
        return array_values(array_map(
            static fn(array $state): array => array_diff_key($state, ['body' => 0, 'delivery_id' => 0]),
            self::getRetryStates()
        ));
    }

    /**
     * Total number of retry attempts made per failed delivery.
     *
     * @return int
     */
    public static function maxRetries(): int
    {
        return count(self::RETRY_DELAYS);
    }

    /**
     * Discards one endpoint's pending retry chain — the cron event and the
     * stored frozen delivery — on explicit admin request from the settings
     * page.
     *
     * The underlying analytics rows are untouched: the endpoint's last-sent
     * marker does not advance, so its next scheduled delivery covers the
     * discarded window's data again, just under a NEW delivery_id. If the
     * receiver actually processed the frozen delivery (only its response was
     * lost), that data can be double-counted — the settings page says so
     * next to the action. Runs under the dispatch mutex like every other
     * retry-state mutation.
     *
     * @param string $urlKey md5 of the endpoint URL (as keyed in the state map).
     * @return bool True when done; false when the dispatch lock was busy —
     *              the caller should ask the admin to try again shortly.
     */
    public static function discardRetry(string $urlKey): bool
    {
        $lock = self::acquireLock();
        if ($lock === null) {
            return false;
        }

        try {
            foreach (self::getRetryStates() as $key => $state) {
                if ($key === $urlKey) {
                    self::clearRetry((string) ($state['url'] ?? ''));
                    break;
                }
            }

            return true;
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Unschedules every pending retry cron event but KEEPS the frozen
     * delivery state, marking each chain exhausted.
     *
     * Called on plugin deactivation. Discarding the state instead would
     * break the delivery_id guarantee: if a receiver processed a delivery
     * whose response was lost and the plugin was then deactivated before an
     * acknowledged attempt, reactivation would build a fresh, overlapping
     * window under a NEW delivery_id. Kept as exhausted, the first
     * scheduled dispatch after reactivation re-sends the frozen bytes under
     * the original id, so duplicates remain deduplicable.
     *
     * The exhausted mark is written under the dispatch mutex like every
     * other retry-state mutation — an unlocked whole-map write here could
     * erase a chain the lock holder froze (or resurrect one it cleared)
     * between this function's read and write. When the mutex is busy (a
     * dispatch run is mid-flight during deactivation), the mark is simply
     * skipped: it is an accelerant, not a requirement — a chain whose cron
     * events vanished is detected as ORPHANED by the first dispatch after
     * reactivation (see {@see retryChainActive()}) and resumed under the
     * same frozen bytes and delivery_id anyway.
     *
     * @return void
     */
    public static function suspendAllRetries(): void
    {
        wp_unschedule_hook(self::RETRY_HOOK);

        $lock = self::acquireLock();
        if ($lock === null) {
            return;
        }

        try {
            $states = self::getRetryStates();
            if ($states === []) {
                return;
            }

            foreach (array_keys($states) as $key) {
                $states[$key]['scheduled_for'] = 0;
                $states[$key]['exhausted']     = true;
            }

            update_option(self::RETRY_STATE_OPTION, $states, false);
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * The start of the next reporting window for an endpoint: its last
     * successful delivery, one full interval ago for a first send (or the
     * start of the retention window when history backfill is enabled), and
     * never older than the data the retention window still holds.
     *
     * @param string $url Endpoint URL.
     * @param int    $now Current unix timestamp.
     * @return int Window start as a unix timestamp.
     */
    private static function windowStart(string $url, int $now): int
    {
        $maxAge   = $now - Options::retentionDays() * DAY_IN_SECONDS;
        $fallback = Options::webhookBackfill()
            ? $maxAge
            : $now - Options::intervalSeconds(Options::webhookInterval());

        $lastSent = get_option(self::LAST_SENT_OPTION, []);
        $lastSent = is_array($lastSent) ? $lastSent : [];

        $startTs = isset($lastSent[md5($url)]) ? (int) $lastSent[md5($url)] : $fallback;

        return max($startTs, $maxAge);
    }

    /**
     * Builds and serializes the payload for one exact window, producing the
     * immutable delivery a chain re-sends verbatim.
     *
     * Serializing once, up front, is what makes the byte-identical promise
     * real: retention cleanup, settings or site-name changes, filters with
     * dynamic output, and even plugin updates between attempts can no longer
     * alter what a given delivery_id accompanies. It also means retries skip
     * the aggregate queries entirely.
     *
     * The requested window is first bounded to at most
     * {@see MAX_CONVERSIONS_PER_DELIVERY} individual conversions: the payload
     * lists every conversion in its window, and a listing cap that let the
     * window advance past unlisted conversions would lose those leads
     * forever. A shrunk window's overflow becomes the next delivery's window
     * (see the catch-up loop in {@see dispatch()}).
     *
     * @param string $url     Absolute endpoint URL.
     * @param int    $startTs Window start as a unix timestamp (inclusive).
     * @param int    $endTs   Requested window end as a unix timestamp (exclusive); may be shrunk.
     * @return array{window_start: int, window_end: int, delivery_id: string, body: string}
     */
    private static function freezeDelivery(string $url, int $startTs, int $endTs): array
    {
        $boundary = Reports::conversionWindowEnd(
            gmdate('Y-m-d H:i:s', $startTs),
            gmdate('Y-m-d H:i:s', $endTs),
            self::MAX_CONVERSIONS_PER_DELIVERY
        );

        $endTs = min($endTs, (int) strtotime($boundary . ' UTC'));

        $payload = self::buildPayload($startTs, $endTs);

        $payload['delivery_id'] = self::deliveryId($url, $startTs, $endTs);

        // wp_json_encode() returns false on failure — casting that to ''
        // would silently deliver an empty body, and a 2xx from the endpoint
        // would then advance the marker past a window that was never really
        // sent. A filter can introduce an unencodable value (a resource, a
        // recursive structure, invalid UTF-8); when that happens the empty
        // body below is treated as a FAILED attempt by attemptDelivery() and
        // enters the normal retry chain. Deliberately NOT rebuilt without
        // the 'spa_webhook_payload' filter: the filter may exist to redact
        // sensitive data, and quietly sending the unfiltered payload would
        // bypass that.
        $body = wp_json_encode($payload);

        return [
            'window_start' => $startTs,
            'window_end'   => $endTs,
            'delivery_id'  => $payload['delivery_id'],
            'body'         => is_string($body) ? $body : '',
        ];
    }

    /**
     * Reconstructs a frozen delivery from stored retry state.
     *
     * State written by an older plugin version may lack the serialized body;
     * the payload is then rebuilt from the frozen window (or, failing even
     * that, a freshly computed one) — the best still-possible approximation.
     *
     * @param string               $url   Endpoint URL the state belongs to.
     * @param array<string, mixed> $state Stored retry state (possibly empty).
     * @return array{window_start: int, window_end: int, delivery_id: string, body: string}
     */
    private static function deliveryFromState(string $url, array $state): array
    {
        $now     = time();
        $startTs = (int) ($state['window_start'] ?? self::windowStart($url, $now));
        $endTs   = (int) ($state['window_end'] ?? $now);

        if (!empty($state['body']) && is_string($state['body'])) {
            return [
                'window_start' => $startTs,
                'window_end'   => $endTs,
                'delivery_id'  => (string) ($state['delivery_id'] ?? self::deliveryId($url, $startTs, $endTs)),
                'body'         => $state['body'],
            ];
        }

        return self::freezeDelivery($url, $startTs, $endTs);
    }

    /**
     * Sends one frozen delivery to one endpoint and updates bookkeeping.
     *
     * On success the last-sent timestamp advances exactly to the delivery's
     * window end (never past what the receiver acknowledged) and any pending
     * retry chain is cancelled.
     *
     * @param string                                                                  $url      Absolute endpoint URL.
     * @param array{window_start: int, window_end: int, delivery_id: string, body: string} $delivery Frozen delivery.
     * @param string                                                                  $kind     Delivery kind for the log: 'scheduled' or 'retry'.
     * @param int                                                                     $attempt  Retry attempt number (0 for scheduled runs).
     * @return bool True when the endpoint returned a 2xx response.
     */
    private static function attemptDelivery(string $url, array $delivery, string $kind, int $attempt): bool
    {
        // An empty body means payload encoding failed (see freezeDelivery()).
        // Sending it could earn a 2xx from a lenient endpoint and advance the
        // marker past a window whose data was never delivered — log the
        // failure instead so the normal retry chain (which rebuilds the
        // payload from the frozen window) takes over.
        if ($delivery['body'] === '') {
            $result = ['ok' => false, 'code' => 0, 'message' => 'Payload could not be JSON-encoded', 'body' => ''];
        } else {
            $result = self::sendToEndpoint($url, $delivery['body'], $delivery['delivery_id']);
        }

        DeliveryLog::log(
            $url,
            $result['ok'],
            $result['code'],
            $result['message'],
            $kind,
            $attempt,
            $delivery['delivery_id'],
            $delivery['body'],
            $result['body']
        );

        if ($result['ok']) {
            $lastSent = get_option(self::LAST_SENT_OPTION, []);
            $lastSent = is_array($lastSent) ? $lastSent : [];

            $key = md5($url);
            $lastSent[$key] = max((int) ($lastSent[$key] ?? 0), $delivery['window_end']);

            update_option(self::LAST_SENT_OPTION, $lastSent, false);
            self::clearRetry($url);
        }

        return $result['ok'];
    }

    /**
     * Schedules retry attempt $attempt for an endpoint, storing the frozen
     * delivery every attempt in the chain must re-send.
     *
     * When the cron event cannot be scheduled, the frozen delivery is stored
     * in the exhausted state instead of being dropped — the next scheduled
     * dispatch then resumes it under the same delivery_id, so a scheduling
     * failure can never cause a different, overlapping window to be built.
     *
     * @param string                                                                  $url      Endpoint URL to retry.
     * @param int                                                                     $attempt  Attempt number being scheduled (1-based).
     * @param array{window_start: int, window_end: int, delivery_id: string, body: string} $delivery Frozen delivery to re-send.
     * @return void
     */
    private static function scheduleRetry(string $url, int $attempt, array $delivery): void
    {
        if ($attempt < 1 || $attempt > count(self::RETRY_DELAYS)) {
            return;
        }

        $when = time() + self::RETRY_DELAYS[$attempt - 1];

        if (wp_schedule_single_event($when, self::RETRY_HOOK, [$url]) === false) {
            self::storeRetryState($url, max(1, $attempt - 1), 0, $delivery, true);
            return;
        }

        self::storeRetryState($url, $attempt, $when, $delivery, false);
    }

    /**
     * Persists one endpoint's retry state.
     *
     * @param string                                                                  $url          Endpoint URL.
     * @param int                                                                     $attempt      Attempt number (next to run, or last made when exhausted).
     * @param int                                                                     $scheduledFor Unix timestamp of the pending cron event (0 when exhausted).
     * @param array{window_start: int, window_end: int, delivery_id: string, body: string} $delivery     Frozen delivery.
     * @param bool                                                                    $exhausted    True when no cron is pending and the next scheduled dispatch must resume the delivery.
     * @return void
     */
    private static function storeRetryState(string $url, int $attempt, int $scheduledFor, array $delivery, bool $exhausted): void
    {
        $states = self::getRetryStates();
        $key    = md5($url);

        // frozen_at marks when this DELIVERY first froze, not when the state
        // row was last rewritten — later attempts in the same chain must not
        // refresh it, or the retention-window expiry (see pruneStaleState())
        // would never trigger for a persistently failing endpoint.
        $existing = $states[$key] ?? null;
        $frozenAt = ($existing !== null && (string) ($existing['delivery_id'] ?? '') === $delivery['delivery_id'])
            ? (int) ($existing['frozen_at'] ?? time())
            : time();

        $states[$key] = [
            'url'           => $url,
            'attempt'       => $attempt,
            'scheduled_for' => $scheduledFor,
            'window_start'  => $delivery['window_start'],
            'window_end'    => $delivery['window_end'],
            'delivery_id'   => $delivery['delivery_id'],
            'body'          => $delivery['body'],
            'exhausted'     => $exhausted,
            'frozen_at'     => $frozenAt,
        ];

        update_option(self::RETRY_STATE_OPTION, $states, false);
    }

    /**
     * Cancels an endpoint's pending retry (cron event and stored state).
     *
     * Safe to call when no retry is pending, or after the retry event has
     * already fired (unscheduling is then a no-op).
     *
     * @param string $url Endpoint URL.
     * @return void
     */
    private static function clearRetry(string $url): void
    {
        $states = self::getRetryStates();
        $key    = md5($url);

        if (!isset($states[$key])) {
            return;
        }

        $timestamp = (int) ($states[$key]['scheduled_for'] ?? 0);
        if ($timestamp > 0) {
            wp_unschedule_event($timestamp, self::RETRY_HOOK, [$url]);
        }

        unset($states[$key]);
        update_option(self::RETRY_STATE_OPTION, $states, false);
    }

    /**
     * Acquires the site-wide dispatch mutex without blocking.
     *
     * Prefers a MySQL named lock (GET_LOCK) — truly atomic, scoped to this
     * install (see {@see lockName()}), and released automatically if the PHP
     * process dies, so a fatal mid-dispatch can never wedge future runs.
     *
     * When the server does not support named locks, falls back to an
     * option-row lock carrying an OWNERSHIP TOKEN and a lease timestamp
     * ("token|timestamp"). The token is what makes release and steal safe:
     * release is a compare-and-delete on the holder's own token (see
     * {@see releaseLock()}), so a run whose lock was stolen after its lease
     * expired can never delete the new holder's lock — the failure mode
     * where A's unconditional release freed B's lock and let C run
     * concurrently with B. Holders renew their lease while working (see
     * {@see renewLock()}), so a healthy long run is never mistaken for a
     * dead one; only a lock whose lease is verifiably stale (its process
     * died without renewing) is stolen, and the steal itself is a
     * compare-and-delete on the exact stale value, so concurrent stealers
     * can't double-free.
     *
     * All fallback reads/writes go straight to the options table —
     * WordPress's option caches could serve this process a value another
     * process changed minutes ago.
     *
     * @return string|null 'mysql' or 'option:<token>' (pass to releaseLock()
     *                     and renewLock()), or null when another process
     *                     holds the lock.
     */
    private static function acquireLock(): ?string
    {
        global $wpdb;

        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lockName()));
        if ($acquired !== null) {
            return ((int) $acquired === 1) ? 'mysql' : null;
        }

        // Named locks unavailable — token-bearing option-row fallback.
        $token = md5(wp_generate_uuid4() . wp_rand());
        $value = $token . '|' . time();

        if (self::insertLockRow($value)) {
            return 'option:' . $token;
        }

        // Occupied. Steal only when the lease is verifiably stale, and only
        // via compare-and-delete on the exact held value, so two stealers
        // can never both think they freed it.
        $held = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LOCK_OPTION
        ));

        $heldTs = (int) substr((string) strrchr($held, '|'), 1);
        if ($held === '' || time() - $heldTs < self::LOCK_TIMEOUT) {
            return null;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::LOCK_OPTION,
            $held
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return self::insertLockRow($value) ? 'option:' . $token : null;
    }

    /**
     * Atomically creates the fallback lock row.
     *
     * INSERT IGNORE relies on the options table's unique key on option_name:
     * exactly one concurrent caller inserts (affected rows = 1); everyone
     * else is ignored — no read-then-write gap.
     *
     * @param string $value Lock value ("token|timestamp").
     * @return bool True when this call created the row.
     */
    private static function insertLockRow(string $value): bool
    {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            self::LOCK_OPTION,
            $value
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return $inserted === 1;
    }

    /**
     * Extends the fallback lock's lease while its holder is still working.
     *
     * Called between delivery windows: a multi-endpoint catch-up run can
     * legitimately exceed {@see LOCK_TIMEOUT} (10 windows × endpoints × a
     * 15-second HTTP timeout each), and without renewal another process
     * would mistake the healthy run for a dead one and steal its lock. The
     * UPDATE matches on the ownership token, so a lock that WAS stolen (this
     * holder's lease had already lapsed) is left alone — the holder simply
     * finishes its current window without a lock rather than overwriting the
     * new holder's row. MySQL named locks need no lease; they die with the
     * connection.
     *
     * @param string $lock The value acquireLock() returned.
     * @return void
     */
    private static function renewLock(string $lock): void
    {
        if (!str_starts_with($lock, 'option:')) {
            return;
        }

        global $wpdb;

        $token = substr($lock, strlen('option:'));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
            $token . '|' . time(),
            self::LOCK_OPTION,
            $wpdb->esc_like($token) . '|%'
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');
    }

    /**
     * Releases the dispatch mutex acquired by {@see acquireLock()}.
     *
     * The fallback release is a compare-and-delete on the ownership token:
     * if this run's lease lapsed and another process stole the lock, the
     * DELETE matches nothing and the new holder keeps its mutex.
     *
     * @param string $lock The value acquireLock() returned ('mysql' or 'option:<token>').
     * @return void
     */
    private static function releaseLock(string $lock): void
    {
        global $wpdb;

        if ($lock === 'mysql') {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName()));
            return;
        }

        $token = substr($lock, strlen('option:'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            self::LOCK_OPTION,
            $wpdb->esc_like($token) . '|%'
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');
    }

    /**
     * The MySQL named-lock name. Named locks are SERVER-wide, not
     * per-database — two unrelated WordPress installs both using the default
     * wp_ prefix on one database server would contend on a prefix-only name —
     * so the name hashes the database name, table prefix, and site URL
     * together to make it unique per install.
     *
     * @return string 45 characters, well under MySQL's 64-character limit.
     */
    private static function lockName(): string
    {
        global $wpdb;

        $db = defined('DB_NAME') ? DB_NAME : '';

        return 'spa_dispatch_' . md5($db . '|' . $wpdb->prefix . '|' . home_url());
    }

    /**
     * Returns the stored retry state map (md5(url) → state).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function getRetryStates(): array
    {
        $states = get_option(self::RETRY_STATE_OPTION, []);

        return is_array($states) ? $states : [];
    }

    /**
     * Drops bookkeeping (last-sent timestamps and retry chains) for endpoints
     * that are no longer in settings, and expires frozen deliveries older
     * than the data retention window — the analytics rows they were built
     * from are gone by then, and a chain kept forever would pin its stored
     * body in wp_options indefinitely for an endpoint that never recovers.
     * Runs under the dispatch mutex.
     *
     * @param string[] $activeUrls Currently configured endpoint URLs.
     * @return void
     */
    private static function pruneStaleState(array $activeUrls): void
    {
        $activeKeys = array_map('md5', $activeUrls);

        $lastSent = get_option(self::LAST_SENT_OPTION, []);
        if (is_array($lastSent)) {
            update_option(self::LAST_SENT_OPTION, array_intersect_key($lastSent, array_flip($activeKeys)), false);
        }

        $expiry = time() - Options::retentionDays() * DAY_IN_SECONDS;

        foreach (self::getRetryStates() as $key => $state) {
            if (!in_array($key, $activeKeys, true)) {
                self::clearRetry((string) ($state['url'] ?? ''));
                continue;
            }

            // States written before frozen_at existed fall back to the frozen
            // window's end — the moment the delivery could first have frozen.
            $frozenAt = (int) ($state['frozen_at'] ?? $state['window_end'] ?? 0);
            if ($frozenAt > 0 && $frozenAt < $expiry) {
                self::clearRetry((string) ($state['url'] ?? ''));
            }
        }
    }

    /**
     * Builds the JSON payload for a reporting window.
     *
     * The 'analytics' section is produced by {@see Reports::buildSummary()},
     * so webhook consumers see exactly the same aggregates as the dashboard.
     * The 'website_info' block identifies the sending site and, optionally,
     * the client/website it belongs to — every field there is always present
     * (empty string when not configured in Settings) so consumers never have
     * to check for its existence.
     *
     * Both 'generated_at' and 'period' are derived from $endTs rather than
     * the wall-clock time a retry actually runs at — a retry chain re-sends
     * a byte-identical body under the same delivery_id, which would break if
     * any field in it moved between attempts.
     *
     * The 'delivery_id' key is a placeholder here — deliverWindow()/sendTest()
     * fill in the real value afterward. It is declared in this position (not
     * simply appended) so the field lands where the docs show it in the
     * JSON-encoded wire format: PHP preserves array key order, and assigning
     * to an existing key updates it in place rather than moving it to the end.
     *
     * @param int $startTs Window start as a unix timestamp (inclusive).
     * @param int $endTs   Window end as a unix timestamp (exclusive).
     * @return array<string, mixed>
     */
    private static function buildPayload(int $startTs, int $endTs): array
    {
        $start = gmdate('Y-m-d H:i:s', $startTs);
        $end   = gmdate('Y-m-d H:i:s', $endTs);

        $payload = [
            'source'         => 'sitepulse-analytics',
            'plugin_version' => SPA_VERSION,
            'website_info'   => [
                'name'   => get_bloginfo('name'),
                'url'    => home_url(),
                'id'     => Options::websiteId(),
                'client' => [
                    'first_name' => Options::clientFirstName(),
                    'last_name'  => Options::clientLastName(),
                    'id'         => Options::clientId(),
                ],
            ],
            'generated_at'   => gmdate('c', $endTs),
            'delivery_id'    => '',
            'period'         => [
                'start' => gmdate('c', $startTs),
                'end'   => gmdate('c', $endTs),
            ],
            'analytics'      => self::summaryFor($start, $end),
        ];

        /**
         * Filters the webhook payload before it is JSON-encoded and sent.
         *
         * @param array<string, mixed> $payload The payload about to be sent.
         * @param int                  $startTs Window start (unix timestamp).
         * @param int                  $endTs   Window end (unix timestamp).
         */
        return (array) apply_filters('spa_webhook_payload', $payload, $startTs, $endTs);
    }

    /** @var array<string, array<string, mixed>> Per-request memo of window summaries (see summaryFor()). */
    private static array $summaryCache = [];

    /**
     * The analytics summary for one window, memoized for this request.
     *
     * A dispatch run freezes one delivery per endpoint, and endpoints that
     * are caught up share identical windows — recomputing the ~17 aggregate
     * queries behind {@see Reports::buildSummary()} once per endpoint made
     * multi-endpoint runs needlessly heavy and long (which in turn raised
     * the odds of overlapping cron executions). The memo lives only for this
     * PHP request, so it can never serve stale data across runs.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<string, mixed>
     */
    private static function summaryFor(string $start, string $end): array
    {
        $limit = self::reportRowLimit();
        $key   = $start . '|' . $end . '|' . $limit;

        if (!isset(self::$summaryCache[$key])) {
            // A backfill catch-up can touch many distinct windows; keep the
            // memo small — it only needs to span one round of endpoints.
            if (count(self::$summaryCache) >= 12) {
                self::$summaryCache = [];
            }

            self::$summaryCache[$key] = Reports::buildSummary($start, $end, $limit);
        }

        return self::$summaryCache[$key];
    }

    /**
     * Maximum rows per "top_*" list in the webhook payload.
     *
     * @return int At least 1; default {@see REPORT_ROW_LIMIT}.
     */
    private static function reportRowLimit(): int
    {
        /**
         * Filters how many rows each "top_*" list in the webhook payload may
         * hold. Raise it if your traffic spreads across more than 200
         * distinct pages/clicks/campaigns per delivery window and you need
         * complete rankings downstream; lower it to shrink payloads.
         *
         * @param int $limit Maximum rows per list.
         */
        return max(1, (int) apply_filters('spa_webhook_report_limit', self::REPORT_ROW_LIMIT));
    }

    /**
     * POSTs a pre-serialized JSON body to one endpoint.
     *
     * Uses wp_safe_remote_post() so the URL is re-validated at request time
     * (blocking loopback/private hosts even if DNS changed after the URL was
     * saved), and disables redirects — a redirect would re-target the
     * validated URL, and HTTP clients drop POST bodies on redirect anyway.
     * The response download is capped at the transport layer to the delivery
     * log's storage limit — a malfunctioning or hostile endpoint returning
     * hundreds of megabytes must not be buffered into PHP memory just to be
     * truncated afterwards.
     *
     * When a signing secret is configured, the request also carries an
     * X-SPA-Signature header: 'sha256=' followed by the HMAC-SHA256 (hex) of
     * the exact body bytes, keyed with the secret — the endpoint's own secret
     * when one is set on its webhook block, otherwise the shared secret (see
     * {@see Options::webhookSecretFor()}). Computed at send time, so
     * a retry re-signs the identical frozen bytes — the signature only
     * changes if the secret itself is rotated between attempts, which is
     * exactly what a receiver validating against its current secret expects.
     *
     * @param string $url        Absolute endpoint URL.
     * @param string $body       JSON-encoded request body, sent byte-for-byte.
     * @param string $deliveryId Delivery id echoed as the Idempotency-Key header.
     * @return array{ok: bool, code: int, message: string, body: string}
     */
    private static function sendToEndpoint(string $url, string $body, string $deliveryId): array
    {
        $headers = [
            'Content-Type'    => 'application/json; charset=utf-8',
            'User-Agent'      => 'WordPress/SitePulse-Analytics ' . SPA_VERSION,
            'Idempotency-Key' => $deliveryId,
        ];

        $secret = Options::webhookSecretFor($url);
        if ($secret !== '') {
            $headers['X-SPA-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $response = wp_safe_remote_post($url, [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => DeliveryLog::MAX_BODY_BYTES,
            'headers'             => $headers,
            'body'                => $body,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 0, 'message' => $response->get_error_message(), 'body' => ''];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $ok   = $code >= 200 && $code < 300;

        return [
            'ok'      => $ok,
            'code'    => $code,
            'message' => $ok ? 'Delivered' : wp_remote_retrieve_response_message($response),
            'body'    => (string) wp_remote_retrieve_body($response),
        ];
    }
}
