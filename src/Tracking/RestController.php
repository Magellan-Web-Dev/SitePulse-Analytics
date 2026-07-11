<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Tracking;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Database\DatabaseManager;
use SitePulseAnalytics\Settings\Options;

/**
 * Public REST endpoint that receives batched events from the frontend tracker.
 *
 * Route: POST /wp-json/sitepulse/v1/track
 * Body:  {"events": [{"type": "pageview", "page_url": "...", ...}, ...]}
 *
 * The endpoint is intentionally unauthenticated — anonymous visitors are the
 * whole point — so it defends itself instead:
 *  - request bodies over a size cap are rejected before JSON parsing,
 *  - an Origin (or Referer) header naming a foreign host is rejected,
 *  - requests from empty or known-bot user agents are silently ignored,
 *  - only whitelisted event types with tracking enabled are accepted,
 *  - every event's page_url must belong to this site's host and is
 *    canonicalized to scheme://host/path (campaign parameters are extracted
 *    into dedicated utm_* fields; all other query data is discarded),
 *  - click/form target URLs are stripped of query strings and fragments,
 *  - every field must be scalar and is sanitized and truncated before storage,
 *  - batches are capped per request, and rate limits are charged per *event*
 *    (not per request), both per-IP and site-wide (see 'spa_rate_limits').
 *
 * None of this makes fabrication impossible — a determined sender can forge
 * any header — but it raises the bar well above drive-by pollution while
 * keeping the endpoint dependency- and cookie-free.
 *
 * The visitor's IP address is used only as a transient rate-limit key (hashed)
 * and is never stored with the analytics data.
 */
final class RestController
{
    /** @var string REST namespace for all plugin routes. */
    private const ROUTE_NAMESPACE = 'sitepulse/v1';

    /** @var int Maximum events accepted in a single request. */
    private const MAX_EVENTS_PER_REQUEST = 25;

    /** @var int Maximum request body size in bytes (64 KB). */
    private const MAX_BODY_BYTES = 65536;

    /** @var int Default maximum events accepted per IP per rate-limit window. */
    private const RATE_LIMIT_MAX = 300;

    /** @var int Default maximum events accepted site-wide per rate-limit window. */
    private const RATE_LIMIT_GLOBAL_MAX = 3000;

    /** @var int Rate-limit window length in seconds. */
    private const RATE_LIMIT_WINDOW = 60;

    /** @var string Object-cache group for rate-limit counters. */
    private const CACHE_GROUP = 'spa_rate_limit';

    /** @var string[] Campaign parameters extracted from pageview events into dedicated columns. */
    private const CAMPAIGN_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign'];

    /**
     * Registers the rest_api_init hook.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    /**
     * Registers the /track collection route.
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/track', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handleTrack'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Validates, sanitizes, and stores a batch of tracked events.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return \WP_REST_Response 202 with {"stored": n}, 400 on a malformed
     *                           body, 403 on a foreign Origin/Referer, 413 on
     *                           an oversized body, or 429 when rate-limited.
     */
    public static function handleTrack(\WP_REST_Request $request): \WP_REST_Response
    {
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return new \WP_REST_Response(['error' => 'payload_too_large'], 413);
        }

        if (self::isForeignRequest($request)) {
            return new \WP_REST_Response(['error' => 'foreign_origin'], 403);
        }

        // Belt-and-braces: the tracker script is never enqueued for logged-in
        // users when exclusion is on, but drop any stray authenticated batch too.
        if (Options::excludeLoggedIn() && is_user_logged_in()) {
            return new \WP_REST_Response(['stored' => 0], 202);
        }

        // Crawlers that execute JS would otherwise pollute every metric.
        // Accept-and-discard so bots see nothing worth probing.
        if (self::isBot()) {
            return new \WP_REST_Response(['stored' => 0], 202);
        }

        $body   = $request->get_json_params();
        $events = is_array($body) && isset($body['events']) ? $body['events'] : null;

        if (!is_array($events) || $events === []) {
            return new \WP_REST_Response(['error' => 'invalid_payload'], 400);
        }

        $events = array_slice($events, 0, self::MAX_EVENTS_PER_REQUEST);

        if (!self::chargeRateLimit(count($events))) {
            return new \WP_REST_Response(['error' => 'rate_limited'], 429);
        }

        $device = wp_is_mobile() ? 'mobile' : 'desktop';
        $stored = 0;

        foreach ($events as $event) {
            $sanitized = self::sanitizeEvent($event, $device);
            if ($sanitized === null) {
                continue;
            }

            if (DatabaseManager::insertEvent($sanitized['type'], $sanitized['data'])) {
                $stored++;
            }
        }

        return new \WP_REST_Response(['stored' => $stored], 202);
    }

    /**
     * Validates a single raw event from the request body.
     *
     * @param mixed  $event  Raw event entry from the JSON body.
     * @param string $device Device bucket derived from the request's user agent.
     * @return array{type: string, data: array<string, string>}|null Null when the
     *         event is malformed, of an unknown or disabled type, or claims a
     *         page_url that does not belong to this site.
     */
    private static function sanitizeEvent(mixed $event, string $device): ?array
    {
        if (!is_array($event)) {
            return null;
        }

        $type = sanitize_key(self::scalarString($event['type'] ?? ''));

        if (!in_array($type, Options::EVENT_TYPES, true) || !Options::isTypeEnabled($type)) {
            return null;
        }

        $pageUrl = self::normalizePageUrl(self::scalarString($event['page_url'] ?? ''));
        if ($pageUrl === '') {
            return null;
        }

        $data = [
            'page_url'      => $pageUrl,
            'page_title'    => self::scalarString($event['page_title'] ?? ''),
            'element_tag'   => self::scalarString($event['element_tag'] ?? ''),
            'element_label' => self::scalarString($event['element_label'] ?? ''),
            'target_url'    => self::normalizeTargetUrl(self::scalarString($event['target_url'] ?? '')),
            'event_value'   => self::scalarString($event['event_value'] ?? ''),
            'referrer'      => self::normalizeReferrer(self::scalarString($event['referrer'] ?? '')),
            'session_id'    => self::scalarString($event['session_id'] ?? ''),
            'device'        => $device,
        ];

        // Campaign attribution is a property of the page view that landed the
        // visitor; other event types never carry it.
        if ($type === 'pageview') {
            foreach (self::CAMPAIGN_PARAMS as $param) {
                $data[$param] = self::scalarString($event[$param] ?? '');
            }
        }

        return ['type' => $type, 'data' => $data];
    }

    /**
     * Returns a scalar value as a string, and anything else as ''.
     *
     * A crafted request can put arrays or objects where strings belong;
     * casting those directly would emit "Array to string conversion" warnings
     * into the server log on every hit.
     *
     * @param mixed $value Raw value from the request body.
     * @return string
     */
    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Normalizes a tracked page URL: the host must belong to this site, and
     * the URL is canonicalized to scheme://host/path — the entire query
     * string is discarded (campaign parameters travel as separate utm_*
     * fields), so tokens and PII never reach the database and one page is
     * never fragmented across many report rows.
     *
     * The tracker performs the same normalization client-side; repeating it
     * here means a hand-crafted request cannot smuggle foreign URLs or
     * sensitive query strings into the reports.
     *
     * @param string $url Raw page URL from the event.
     * @return string Canonical URL, or '' when the URL is invalid or foreign.
     */
    private static function normalizePageUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        if (!in_array(strtolower((string) $parts['host']), Options::allowedHosts(), true)) {
            return '';
        }

        return (($parts['scheme'] ?? 'https')) . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . ($parts['path'] ?? '/');
    }

    /**
     * Normalizes a click/form destination URL.
     *
     * mailto: and tel: destinations are kept whole (the address *is* the
     * destination), script/data URIs are dropped, and every http(s) or
     * relative URL loses its query string and fragment — link and form-action
     * URLs can carry reset tokens, emails, or order ids that must never be
     * stored.
     *
     * @param string $url Raw destination from the event.
     * @return string Normalized destination, or '' when empty or unsafe.
     */
    private static function normalizeTargetUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (preg_match('~^(mailto|tel):~i', $url)) {
            return $url;
        }

        if (preg_match('~^(javascript|data|vbscript):~i', $url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (is_array($parts) && !empty($parts['host'])) {
            return (($parts['scheme'] ?? 'https')) . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                . ($parts['path'] ?? '/');
        }

        // Relative URL (e.g. a form action of "/contact") — keep the path only.
        return (string) preg_replace('~[?#].*$~', '', $url);
    }

    /**
     * Normalizes a referrer URL to scheme://host/path — query strings and
     * fragments can carry search terms, emails, or tokens, so they are never
     * stored. Any host is allowed (external referrers are the interesting ones).
     *
     * @param string $url Raw referrer from the event.
     * @return string Normalized referrer, or '' when unparsable.
     */
    private static function normalizeReferrer(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return (($parts['scheme'] ?? 'https')) . '://' . $parts['host'] . ($parts['path'] ?? '/');
    }

    /**
     * Whether the request carries an Origin (or, failing that, Referer)
     * header naming a foreign host.
     *
     * Browsers always send Origin with the tracker's POSTs, so a mismatch is
     * a strong foreign signal. Requests with neither header (e.g. some
     * privacy tools) are allowed through — the per-event host check and rate
     * limits still apply.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return bool True when the request should be rejected.
     */
    private static function isForeignRequest(\WP_REST_Request $request): bool
    {
        foreach (['origin', 'referer'] as $header) {
            $value = (string) $request->get_header($header);
            if ($value === '') {
                continue;
            }

            $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));

            return $host === '' || !in_array($host, Options::allowedHosts(), true);
        }

        return false;
    }

    /**
     * Whether the request's user agent is empty or a known crawler.
     *
     * @return bool
     */
    private static function isBot(): bool
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        if ($ua === '') {
            return true;
        }

        return (bool) preg_match('~bot|crawl|spider|slurp|preview|headless|curl|wget|python-requests~i', $ua);
    }

    /**
     * Charges $events against both the per-IP and the site-wide rate limit.
     *
     * Charging by event count (not request count) closes the gap where a
     * sender packs the maximum batch into every request; the site-wide bucket
     * bounds distributed floods and, with it, table growth. The trade-off of
     * a site-wide cap is that a flood can crowd out legitimate events for the
     * rest of its window — tune both limits (or effectively disable one with
     * a huge value) via the 'spa_rate_limits' filter, and put edge/WAF
     * protection in front of very-high-traffic sites.
     *
     * @param int $events Number of events in this request.
     * @return bool True when the request is within both limits.
     */
    private static function chargeRateLimit(int $events): bool
    {
        $limits = self::rateLimits();
        $ip     = self::clientIp();

        $ipAllowed = $ip === ''
            || self::chargeBucket('spa_rl_' . md5($ip), $events, $limits['per_ip']);

        $siteAllowed = self::chargeBucket('spa_rl_site', $events, $limits['site_wide']);

        return $ipAllowed && $siteAllowed;
    }

    /**
     * Returns the effective rate limits (events per minute).
     *
     * @return array{per_ip: int, site_wide: int}
     */
    private static function rateLimits(): array
    {
        $defaults = [
            'per_ip'    => self::RATE_LIMIT_MAX,
            'site_wide' => self::RATE_LIMIT_GLOBAL_MAX,
        ];

        /**
         * Filters the ingestion rate limits, in events per minute.
         *
         * @param array{per_ip: int, site_wide: int} $defaults The default limits.
         */
        $limits = apply_filters('spa_rate_limits', $defaults);
        $limits = is_array($limits) ? $limits : $defaults;

        return [
            'per_ip'    => max(1, (int) ($limits['per_ip'] ?? $defaults['per_ip'])),
            'site_wide' => max(1, (int) ($limits['site_wide'] ?? $defaults['site_wide'])),
        ];
    }

    /**
     * Adds $events to one rolling counter and reports whether it is under $max.
     *
     * Uses an atomic object-cache increment when a persistent object cache is
     * available; otherwise falls back to a transient read-modify-write, which
     * is best-effort under concurrency but always within a constant factor of
     * the cap.
     *
     * @param string $key    Counter key.
     * @param int    $events Amount to charge.
     * @param int    $max    Maximum events per window.
     * @return bool True when the counter (including this charge) is within $max.
     */
    private static function chargeBucket(string $key, int $events, int $max): bool
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::CACHE_GROUP, self::RATE_LIMIT_WINDOW);
            $count = wp_cache_incr($key, $events, self::CACHE_GROUP);

            return $count === false ? true : $count <= $max;
        }

        $count = (int) get_transient($key);
        if ($count + $events > $max) {
            return false;
        }

        set_transient($key, $count + $events, self::RATE_LIMIT_WINDOW);

        return true;
    }

    /**
     * The client IP used for rate limiting.
     *
     * REMOTE_ADDR is the only value that cannot be spoofed by the sender, but
     * behind a reverse proxy it is the proxy's address, which would make all
     * visitors share one bucket. Sites that trust a proxy header can map it
     * via the 'spa_client_ip' filter.
     *
     * @return string
     */
    private static function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        /**
         * Filters the client IP used as the rate-limit key.
         *
         * @param string $ip The REMOTE_ADDR value.
         */
        return (string) apply_filters('spa_client_ip', $ip);
    }
}
