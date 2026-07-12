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
 * Delivery scatter:
 *  The recurring cron is anchored at a random offset within the send interval
 *  (capped at 24 hours) rather than at the moment of scheduling. When many
 *  sites running this plugin share one endpoint and the same interval, their
 *  deliveries land at different, stable times of day instead of stampeding
 *  the endpoint simultaneously.
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

        self::pruneStaleState($urls);

        foreach ($urls as $url) {
            $state = self::getRetryStates()[md5($url)] ?? null;

            if ($state !== null) {
                if (empty($state['exhausted'])) {
                    continue; // An active retry chain owns this endpoint.
                }

                // Resume the exhausted chain: same frozen bytes, same id.
                $delivery = self::deliveryFromState($url, $state);

                if (!self::attemptDelivery($url, $delivery, 'scheduled', 0)) {
                    self::scheduleRetry($url, 1, $delivery);
                    continue;
                }
                // Acknowledged — last-sent now sits at the frozen window's
                // end, so the fresh window below picks up exactly there.
            }

            // freezeDelivery() shrinks a conversion-heavy window to the
            // per-delivery conversion cap, so a backlog is worked off in
            // consecutive non-overlapping windows within this run (bounded,
            // so one endpoint can never pin the cron indefinitely).
            for ($round = 0; $round < self::MAX_WINDOWS_PER_RUN; $round++) {
                $now     = time();
                $startTs = self::windowStart($url, $now);

                if ($startTs >= $now) {
                    break;
                }

                $delivery = self::freezeDelivery($url, $startTs, $now);

                if (!self::attemptDelivery($url, $delivery, 'scheduled', 0)) {
                    self::scheduleRetry($url, 1, $delivery);
                    break;
                }

                if ($delivery['window_end'] >= $now) {
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
     * @param string $url The endpoint URL this retry targets.
     * @return void
     */
    public static function retry(string $url): void
    {
        // The endpoint was removed from settings after this retry was scheduled.
        if (!in_array($url, Options::webhookUrls(), true)) {
            self::clearRetry($url);
            return;
        }

        $state    = self::getRetryStates()[md5($url)] ?? [];
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

            $encoded = (string) wp_json_encode($body);
            $result  = self::sendToEndpoint($url, $encoded, $body['delivery_id']);

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
     * @return void
     */
    public static function suspendAllRetries(): void
    {
        wp_unschedule_hook(self::RETRY_HOOK);

        $states = self::getRetryStates();
        if ($states === []) {
            return;
        }

        foreach ($states as $key => $state) {
            $states[$key]['scheduled_for'] = 0;
            $states[$key]['exhausted']     = true;
        }

        update_option(self::RETRY_STATE_OPTION, $states, false);
    }

    /**
     * The start of the next reporting window for an endpoint: its last
     * successful delivery, one full interval ago for a first send, and never
     * older than the data the retention window still holds.
     *
     * @param string $url Endpoint URL.
     * @param int    $now Current unix timestamp.
     * @return int Window start as a unix timestamp.
     */
    private static function windowStart(string $url, int $now): int
    {
        $fallback = $now - Options::intervalSeconds(Options::webhookInterval());
        $maxAge   = $now - Options::retentionDays() * DAY_IN_SECONDS;

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

        return [
            'window_start' => $startTs,
            'window_end'   => $endTs,
            'delivery_id'  => $payload['delivery_id'],
            'body'         => (string) wp_json_encode($payload),
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
        $result = self::sendToEndpoint($url, $delivery['body'], $delivery['delivery_id']);

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

        $states[md5($url)] = [
            'url'           => $url,
            'attempt'       => $attempt,
            'scheduled_for' => $scheduledFor,
            'window_start'  => $delivery['window_start'],
            'window_end'    => $delivery['window_end'],
            'delivery_id'   => $delivery['delivery_id'],
            'body'          => $delivery['body'],
            'exhausted'     => $exhausted,
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
     * that are no longer in settings.
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

        foreach (self::getRetryStates() as $key => $state) {
            if (!in_array($key, $activeKeys, true)) {
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
            'analytics'      => Reports::buildSummary($start, $end),
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
     * @param string $url        Absolute endpoint URL.
     * @param string $body       JSON-encoded request body, sent byte-for-byte.
     * @param string $deliveryId Delivery id echoed as the Idempotency-Key header.
     * @return array{ok: bool, code: int, message: string, body: string}
     */
    private static function sendToEndpoint(string $url, string $body, string $deliveryId): array
    {
        $response = wp_safe_remote_post($url, [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => DeliveryLog::MAX_BODY_BYTES,
            'headers'             => [
                'Content-Type'    => 'application/json; charset=utf-8',
                'User-Agent'      => 'WordPress/SitePulse-Analytics ' . SPA_VERSION,
                'Idempotency-Key' => $deliveryId,
            ],
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
