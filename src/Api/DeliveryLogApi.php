<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Api;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;
use SitePulseAnalytics\Webhook\DeliveryLog;

/**
 * Registers and handles the read-only webhook delivery log REST API endpoint.
 *
 * Route: GET /wp-json/sitepulse/v1/deliveries
 *
 * Query parameters:
 *   page     - 1-based page number (default: 1)
 *   per_page - results per page, max 100 (default: 25)
 *   status   - 'success' or 'error' to filter, omit for all deliveries
 *
 * Authentication: pass the API key as the Authorization request header.
 *
 *   Authorization: <api-key>
 *
 * The endpoint is off by default; it is enabled (and its key managed) on the
 * SitePulse → Delivery Log admin page.
 *
 * Responses identify each delivery's endpoint by its label and a REDACTED
 * URL (scheme://host only) — full webhook URLs can embed bearer tokens and
 * never leave the site through this read-only API (see formatEntry()).
 *
 * Cross-origin requests are permitted from any origin. The following CORS
 * headers are added to every response (including OPTIONS preflight):
 *   Access-Control-Allow-Origin:   *
 *   Access-Control-Allow-Methods:  GET, OPTIONS
 *   Access-Control-Allow-Headers:  Authorization, Content-Type
 *   Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, X-SPA-Page
 *   Access-Control-Max-Age:        86400
 *
 * Pagination metadata is returned in response headers:
 *   X-WP-Total      - total number of log entries matching the query
 *   X-WP-TotalPages - total number of pages
 *   X-SPA-Page      - the current page number
 */
final class DeliveryLogApi
{
    /** @var string Option key storing whether the API is enabled. */
    private const ACTIVE_OPTION = 'spa_delivery_api_active';

    /** @var string Option key storing the SHA-256 hash of the API key. */
    private const KEY_HASH_OPTION = 'spa_delivery_api_key_hash';

    /** @var string Pre-1.1.0-final option key that stored the key in plaintext (migrated on read). */
    private const LEGACY_KEY_OPTION = 'spa_delivery_api_key';

    /** @var int Failed authentications per IP allowed within the throttle window. */
    private const AUTH_FAILURE_MAX = 10;

    /** @var int Authentication-failure throttle window, in seconds. */
    private const AUTH_FAILURE_WINDOW = 5 * MINUTE_IN_SECONDS;

    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE     = 100;

    /**
     * Registers the REST route and the CORS filter.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);

        // rest_post_dispatch fires for every dispatched REST request — including
        // OPTIONS preflight — so one filter handles CORS for both cases.
        add_filter('rest_post_dispatch', [self::class, 'addCorsHeaders'], 10, 1);
    }

    /**
     * Whether the read-only deliveries API is currently enabled.
     *
     * @return bool
     */
    public static function isActive(): bool
    {
        return (bool) get_option(self::ACTIVE_OPTION, false);
    }

    /**
     * Persists the API active state.
     *
     * @param bool $active
     * @return void
     */
    public static function setActive(bool $active): void
    {
        update_option(self::ACTIVE_OPTION, $active);
    }

    /**
     * Whether an API key has been generated.
     *
     * @return bool
     */
    public static function hasKey(): bool
    {
        return self::keyHash() !== '';
    }

    /**
     * Generates a new 40-character alphanumeric API key, persists only its
     * SHA-256 hash, and returns the raw key — the ONE time it is ever
     * available. Any previous key stops working immediately.
     *
     * SHA-256 (rather than a slow password hash) is appropriate here: the
     * key is 40 characters of cryptographic randomness, so offline
     * brute-forcing the hash is infeasible regardless of hash speed, and
     * verification runs on every API request.
     *
     * @return string The raw key, to be shown to the admin exactly once.
     */
    public static function generateKey(): string
    {
        $key = wp_generate_password(40, false);
        update_option(self::KEY_HASH_OPTION, hash('sha256', $key));
        delete_option(self::LEGACY_KEY_OPTION);

        return $key;
    }

    /**
     * Whether a presented key matches the stored hash (constant-time).
     *
     * @param string $key Raw key from the Authorization header.
     * @return bool
     */
    public static function verifyKey(string $key): bool
    {
        $hash = self::keyHash();

        return $hash !== '' && $key !== '' && hash_equals($hash, hash('sha256', $key));
    }

    /**
     * Returns the stored key hash, transparently migrating a legacy
     * plaintext key (written by earlier 1.1.0 development builds) into
     * hashed storage on first read. The legacy key keeps working — only its
     * storage form changes.
     *
     * @return string SHA-256 hex hash, or '' when no key exists.
     */
    private static function keyHash(): string
    {
        $hash = (string) get_option(self::KEY_HASH_OPTION, '');
        if ($hash !== '') {
            return $hash;
        }

        $legacy = (string) get_option(self::LEGACY_KEY_OPTION, '');
        if ($legacy === '') {
            return '';
        }

        $hash = hash('sha256', $legacy);
        update_option(self::KEY_HASH_OPTION, $hash);
        delete_option(self::LEGACY_KEY_OPTION);

        return $hash;
    }

    /**
     * Registers the /deliveries collection route.
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        register_rest_route('sitepulse/v1', '/deliveries', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handleRequest'],
            'permission_callback' => '__return_true',
            'args'                => [
                'page' => [
                    'default'           => 1,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default'           => self::DEFAULT_PER_PAGE,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => self::MAX_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ],
                'status' => [
                    'default'           => '',
                    'type'              => 'string',
                    'enum'              => ['', 'success', 'error'],
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /**
     * Appends CORS headers to any response destined for the deliveries route.
     * Scoped via a REQUEST_URI check so other REST endpoints are unaffected.
     *
     * The parameter is intentionally untyped: rest_post_dispatch can hand this
     * filter a WP_Error for any REST request that errors out. We only act on
     * WP_REST_Response objects and pass everything else through untouched, so
     * the filter can never fatal on a non-response.
     *
     * @param mixed $response Usually a WP_REST_Response, but may be a WP_Error
     *                        or other value for non-route or errored requests.
     * @return mixed The response unmodified, or with CORS headers added when
     *               it is a WP_REST_Response for the deliveries route.
     */
    public static function addCorsHeaders($response)
    {
        if (!$response instanceof \WP_REST_Response) {
            return $response;
        }

        if (!str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/sitepulse/v1/deliveries')) {
            return $response;
        }

        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->header('Access-Control-Expose-Headers', 'X-WP-Total, X-WP-TotalPages, X-SPA-Page');
        $response->header('Access-Control-Max-Age', '86400');

        return $response;
    }

    /**
     * Authenticates the request and returns a paginated page of delivery
     * log entries.

     * @param \WP_REST_Request $request The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handleRequest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!self::isActive()) {
            return new \WP_Error(
                'api_disabled',
                'The deliveries API is not enabled.',
                ['status' => 403]
            );
        }

        if (self::isThrottled()) {
            return new \WP_Error(
                'too_many_failures',
                'Too many failed authentication attempts. Try again later.',
                ['status' => 429]
            );
        }

        if (!self::verifyKey((string) $request->get_header('authorization'))) {
            self::recordAuthFailure();

            return new \WP_Error(
                'unauthorized',
                'Invalid or missing API key.',
                ['status' => 401]
            );
        }

        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));
        $status  = (string) $request->get_param('status');

        $total      = DeliveryLog::getLogCount($status);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Clamp page to valid range.
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $logs   = DeliveryLog::getLogsPaginated($page, $perPage, $status);
        $output = array_map([self::class, 'formatEntry'], $logs);

        $response = new \WP_REST_Response($output, 200);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $totalPages);
        $response->header('X-SPA-Page', (string) $page);

        return $response;
    }

    /**
     * Whether the requesting IP has exceeded the authentication-failure
     * budget for the current window.
     *
     * @return bool
     */
    private static function isThrottled(): bool
    {
        return (int) get_transient(self::failureKey()) >= self::AUTH_FAILURE_MAX;
    }

    /**
     * Charges one failed authentication against the requesting IP.
     *
     * Best-effort transient counter — enough to blunt online key guessing
     * without a persistent object cache; the 40-character random key is the
     * real defense.
     *
     * @return void
     */
    private static function recordAuthFailure(): void
    {
        $key = self::failureKey();

        set_transient($key, (int) get_transient($key) + 1, self::AUTH_FAILURE_WINDOW);
    }

    /**
     * The per-IP transient key for authentication-failure counting.
     *
     * @return string
     */
    private static function failureKey(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        return 'spa_api_fail_' . md5($ip);
    }

    /**
     * Shapes one database row into the public API representation, decoding
     * the stored JSON bodies where possible.
     *
     * endpoint_url is REDACTED to scheme://host(:port). Webhook URLs
     * routinely carry bearer tokens in their path or query string
     * (hooks.example.com/ingest/<token>), and this API authenticates with a
     * read-only key — a leak of that key must not also hand out write
     * credentials for every downstream endpoint. The label identifies the
     * endpoint for legitimate consumers; the full URL stays visible to
     * admins on the Delivery Log page.
     *
     * @param array<string, mixed> $entry A single DeliveryLog row.
     * @return array<string, mixed>
     */
    public static function formatEntry(array $entry): array
    {
        $requestDecoded  = json_decode((string) ($entry['request_data'] ?? '{}'), true);
        $responseDecoded = json_decode((string) ($entry['response_data'] ?? ''), true);
        $endpoint        = (string) ($entry['endpoint_url'] ?? '');

        return [
            'id'            => (int) ($entry['id'] ?? 0),
            'created_at'    => (string) ($entry['created_at'] ?? ''),
            'success'       => (int) ($entry['success'] ?? 0) === 1,
            'endpoint_url'  => self::redactEndpointUrl($endpoint),
            'webhook_label' => $endpoint !== '' ? Options::webhookLabel($endpoint) : '',
            'delivery_id'   => (string) ($entry['delivery_id'] ?? ''),
            'kind'          => (string) ($entry['kind'] ?? ''),
            'attempt'       => (int) ($entry['attempt'] ?? 0),
            'response_code' => (int) ($entry['response_code'] ?? 0),
            'request_data'  => is_array($requestDecoded) ? $requestDecoded : [],
            'response_data' => is_array($responseDecoded) ? $responseDecoded : (string) ($entry['response_data'] ?? ''),
        ];
    }

    /**
     * Reduces a stored endpoint URL to scheme://host(:port) — everything
     * that can carry a credential (path, query, fragment, userinfo) is
     * dropped before the URL leaves the site through this API.
     *
     * @param string $url Full stored endpoint URL.
     * @return string Redacted URL, or '' when the URL cannot be parsed.
     */
    private static function redactEndpointUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
