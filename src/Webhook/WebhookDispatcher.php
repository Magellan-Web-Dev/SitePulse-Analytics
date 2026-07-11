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
 *  reporting window that failed is FROZEN and retried up to 5 more times over
 *  about 24 hours — after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16
 *  hours — via single-event crons on the spa_retry_webhook hook. Every
 *  attempt at a frozen window re-sends the identical payload under the same
 *  delivery_id, and scheduled runs skip an endpoint while its chain is
 *  pending — so an idempotent receiver that processed one attempt (but whose
 *  response was lost) can safely answer later attempts from cache without any
 *  events going missing. On success the endpoint's last-sent marker advances
 *  exactly to the frozen window's end; everything newer is picked up by the
 *  next scheduled run. If the chain is exhausted the endpoint waits for the
 *  next scheduled run, whose failure starts a fresh chain — the undelivered
 *  window keeps accumulating until a delivery succeeds (bounded by the
 *  retention window).
 *
 * Delivery semantics are at-least-once, not exactly-once: if a receiver
 * processes a request whose response is lost AND the entire retry chain also
 * fails to reach it, the next chain re-sends a window that overlaps the
 * processed one under a new delivery_id. Receivers that must never
 * double-count should deduplicate by delivery_id (also sent as an
 * Idempotency-Key header) and treat overlapping periods defensively.
 *
 * Delivery scatter:
 *  The recurring cron is anchored at a random offset within the send interval
 *  (capped at 24 hours) rather than at the moment of scheduling. When many
 *  sites running this plugin share one endpoint and the same interval, their
 *  deliveries land at different, stable times of day instead of stampeding
 *  the endpoint simultaneously.
 *
 * Delivery outcomes are recorded in a rolling log (shown on the settings
 * page) so admins can verify their endpoints are receiving data.
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

    /** @var string Option key holding the rolling delivery log. */
    private const LOG_OPTION = 'spa_webhook_log';

    /** @var int Maximum entries kept in the delivery log. */
    private const LOG_MAX = 20;

    /** @var int HTTP timeout for webhook requests, in seconds. */
    private const TIMEOUT = 15;

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
     * Endpoints with a pending retry chain are skipped — the chain owns its
     * frozen window, and sending a fresh, larger window under a different
     * delivery_id while the receiver may have processed (but not acknowledged)
     * the frozen one would fork the bookkeeping. A failed delivery freezes the
     * attempted window and starts a retry chain. Bookkeeping for endpoints
     * that were removed from settings is pruned first.
     *
     * @return void
     */
    public static function dispatch(): void
    {
        $urls = Options::webhookUrls();
        if ($urls === []) {
            return;
        }

        self::pruneStaleState($urls);

        $now = time();

        foreach ($urls as $url) {
            if (self::hasPendingRetry($url)) {
                continue;
            }

            $startTs = self::windowStart($url, $now);

            if (!self::deliverWindow($url, $startTs, $now, 'scheduled', 0)) {
                self::scheduleRetry($url, 1, $startTs, $now);
            }
        }
    }

    /**
     * Cron callback for a single retry attempt against one endpoint.
     *
     * Re-sends the chain's frozen window — the identical payload and
     * delivery_id as the attempt that failed. On another failure the next
     * retry in the chain is scheduled; after the final attempt the chain is
     * cleared and the endpoint waits for the next scheduled dispatch (which
     * will start a fresh chain if it fails too).
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

        $state   = self::getRetryStates()[md5($url)] ?? [];
        $attempt = (int) ($state['attempt'] ?? 1);

        // Fall back to a freshly computed window if the frozen one is missing
        // (state written by a pre-1.0 development build); normal chains always
        // carry both timestamps.
        $now     = time();
        $startTs = (int) ($state['window_start'] ?? self::windowStart($url, $now));
        $endTs   = (int) ($state['window_end'] ?? $now);

        if (self::deliverWindow($url, $startTs, $endTs, 'retry', $attempt)) {
            return; // Success already cleared the pending retry state.
        }

        if ($attempt < count(self::RETRY_DELAYS)) {
            self::scheduleRetry($url, $attempt + 1, $startTs, $endTs);
        } else {
            self::clearRetry($url);
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

            $result = self::sendToEndpoint($url, $body);
            self::log($url, $result['ok'], $result['code'], $result['message'], 'test');
        }
    }

    /**
     * Deterministic delivery id for one endpoint + exact reporting window.
     *
     * A retry chain freezes its window, so every attempt in the chain
     * produces the same id for a byte-identical payload; any different
     * window — including the fresh chain started after an exhausted one —
     * gets a new id. Receivers can therefore treat the id as "same payload,
     * seen before?" with no edge cases.
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
     * Returns the rolling delivery log, newest entry first.
     *
     * @return array<int, array{time: int, url: string, ok: bool, code: int, message: string, kind: string, attempt: int}>
     */
    public static function getLog(): array
    {
        $log = get_option(self::LOG_OPTION, []);

        return is_array($log) ? $log : [];
    }

    /**
     * Returns every pending retry, for display on the settings page.
     *
     * @return array<int, array{url: string, attempt: int, scheduled_for: int, window_start: int, window_end: int}>
     */
    public static function getPendingRetries(): array
    {
        return array_values(self::getRetryStates());
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
     * Cancels every pending retry (cron events and stored state).
     *
     * Called on plugin deactivation. Discarded retries are not restored on
     * reactivation, but nothing is lost: last-sent timestamps were never
     * advanced for the failed deliveries, so the next scheduled dispatch
     * covers the full undelivered window.
     *
     * @return void
     */
    public static function clearAllRetries(): void
    {
        wp_unschedule_hook(self::RETRY_HOOK);
        delete_option(self::RETRY_STATE_OPTION);
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
     * Builds and sends the payload for one exact window to one endpoint, and
     * updates bookkeeping.
     *
     * The window is supplied by the caller — computed fresh for scheduled
     * runs, frozen for retries — so a retry chain re-sends a byte-identical
     * payload under the same delivery_id. On success the last-sent timestamp
     * advances exactly to $endTs (never past what the receiver acknowledged)
     * and any pending retry chain is cancelled.
     *
     * @param string $url     Absolute endpoint URL.
     * @param int    $startTs Window start as a unix timestamp (inclusive).
     * @param int    $endTs   Window end as a unix timestamp (exclusive).
     * @param string $kind    Delivery kind for the log: 'scheduled' or 'retry'.
     * @param int    $attempt Retry attempt number (0 for scheduled runs).
     * @return bool True when the endpoint returned a 2xx response.
     */
    private static function deliverWindow(string $url, int $startTs, int $endTs, string $kind, int $attempt): bool
    {
        $payload = self::buildPayload($startTs, $endTs);

        $payload['delivery_id'] = self::deliveryId($url, $startTs, $endTs);

        $result = self::sendToEndpoint($url, $payload);

        self::log($url, $result['ok'], $result['code'], $result['message'], $kind, $attempt);

        if ($result['ok']) {
            $lastSent = get_option(self::LAST_SENT_OPTION, []);
            $lastSent = is_array($lastSent) ? $lastSent : [];

            $key = md5($url);
            $lastSent[$key] = max((int) ($lastSent[$key] ?? 0), $endTs);

            update_option(self::LAST_SENT_OPTION, $lastSent, false);
            self::clearRetry($url);
        }

        return $result['ok'];
    }

    /**
     * Schedules retry attempt $attempt for an endpoint, carrying the frozen
     * window every attempt in the chain must re-send.
     *
     * The pending state is only stored when the cron event was actually
     * scheduled, so a scheduling failure can never leave a phantom chain
     * that blocks future retries.
     *
     * @param string $url         Endpoint URL to retry.
     * @param int    $attempt     Attempt number being scheduled (1-based).
     * @param int    $windowStart Frozen window start (unix timestamp).
     * @param int    $windowEnd   Frozen window end (unix timestamp).
     * @return void
     */
    private static function scheduleRetry(string $url, int $attempt, int $windowStart, int $windowEnd): void
    {
        if ($attempt < 1 || $attempt > count(self::RETRY_DELAYS)) {
            return;
        }

        $when = time() + self::RETRY_DELAYS[$attempt - 1];

        if (wp_schedule_single_event($when, self::RETRY_HOOK, [$url]) === false) {
            return;
        }

        $states = self::getRetryStates();

        $states[md5($url)] = [
            'url'           => $url,
            'attempt'       => $attempt,
            'scheduled_for' => $when,
            'window_start'  => $windowStart,
            'window_end'    => $windowEnd,
        ];

        update_option(self::RETRY_STATE_OPTION, $states, false);
    }

    /**
     * Whether an endpoint currently has a retry chain pending.
     *
     * @param string $url Endpoint URL.
     * @return bool
     */
    private static function hasPendingRetry(string $url): bool
    {
        return isset(self::getRetryStates()[md5($url)]);
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
     * @return array<string, array{url: string, attempt: int, scheduled_for: int, window_start: int, window_end: int}>
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
            'site_url'       => home_url(),
            'site_name'      => get_bloginfo('name'),
            'generated_at'   => gmdate('c', $endTs),
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
     * POSTs a payload to one endpoint as JSON.
     *
     * Uses wp_safe_remote_post() so the URL is re-validated at request time
     * (blocking loopback/private hosts even if DNS changed after the URL was
     * saved), and disables redirects — a redirect would re-target the
     * validated URL, and HTTP clients drop POST bodies on redirect anyway.
     *
     * @param string               $url     Absolute endpoint URL.
     * @param array<string, mixed> $payload Payload to encode and send;
     *                                      its delivery_id is echoed as the
     *                                      Idempotency-Key header.
     * @return array{ok: bool, code: int, message: string}
     */
    private static function sendToEndpoint(string $url, array $payload): array
    {
        $response = wp_safe_remote_post($url, [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'    => 'application/json; charset=utf-8',
                'User-Agent'      => 'WordPress/SitePulse-Analytics ' . SPA_VERSION,
                'Idempotency-Key' => (string) ($payload['delivery_id'] ?? ''),
            ],
            'body'        => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 0, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $ok   = $code >= 200 && $code < 300;

        return [
            'ok'      => $ok,
            'code'    => $code,
            'message' => $ok ? 'Delivered' : wp_remote_retrieve_response_message($response),
        ];
    }

    /**
     * Prepends an entry to the rolling delivery log.
     *
     * @param string $url     Endpoint URL the payload was sent to.
     * @param bool   $ok      Whether the endpoint returned a 2xx response.
     * @param int    $code    HTTP status code (0 on a transport error).
     * @param string $message Short human-readable outcome.
     * @param string $kind    Delivery kind: 'scheduled', 'retry', or 'test'.
     * @param int    $attempt Retry attempt number (0 when not a retry).
     * @return void
     */
    private static function log(string $url, bool $ok, int $code, string $message, string $kind, int $attempt = 0): void
    {
        $log = self::getLog();

        array_unshift($log, [
            'time'    => time(),
            'url'     => $url,
            'ok'      => $ok,
            'code'    => $code,
            'message' => function_exists('mb_substr') ? mb_substr($message, 0, 200) : substr($message, 0, 200),
            'kind'    => $kind,
            'attempt' => $attempt,
        ]);

        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_MAX), false);
    }
}
