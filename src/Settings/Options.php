<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Typed read access to the plugin's settings.
 *
 * All settings live in a single 'spa_settings' option array. This class is
 * the only place that knows the option's shape and defaults, so every other
 * subsystem reads configuration through the typed getters below instead of
 * touching get_option() directly.
 *
 * Writing is handled exclusively by {@see \SitePulseAnalytics\Admin\SettingsPage},
 * whose sanitize callback normalizes user input back into this shape.
 */
final class Options
{
    /** @var string The wp_options key holding all plugin settings. */
    public const OPTION_KEY = 'spa_settings';

    /** @var string[] Event types the frontend tracker can record. */
    public const EVENT_TYPES = ['pageview', 'click', 'form_submit', 'form_success', 'hover', 'scroll_depth'];

    /** @var string[] Cron recurrences selectable for webhook dispatch. */
    public const INTERVALS = ['hourly', 'twicedaily', 'daily', 'weekly'];

    /**
     * Returns the default value for every setting.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'track_pageview'     => true,
            'track_click'        => true,
            'track_form_submit'  => true,
            'track_form_success' => true,
            'track_hover'        => true,
            'track_scroll_depth' => true,
            'exclude_logged_in'  => true,
            'respect_dnt'        => false,
            'retention_days'     => 90,
            'hover_dwell_ms'     => 800,
            'webhook_active'     => true,
            'webhooks'           => [],
            'webhook_interval'   => 'daily',
            'webhook_secret'     => '',
            'webhook_backfill'   => false,
            'client_first_name'  => '',
            'client_last_name'   => '',
            'client_id'          => '',
            'website_id'         => '',
        ];
    }

    /**
     * Returns all settings merged over the defaults.
     *
     * Unknown keys stored in the option (e.g. from an older version) are
     * preserved so upgrades never silently drop data.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION_KEY, []);

        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    /**
     * Whether a given event type should be recorded.
     *
     * Unknown types (custom events recorded via spa_track_event()) are always
     * allowed; only the built-in tracker types can be toggled off.
     *
     * @param string $type Event type key (e.g. "pageview").
     * @return bool
     */
    public static function isTypeEnabled(string $type): bool
    {
        if (!in_array($type, self::EVENT_TYPES, true)) {
            return true;
        }

        return !empty(self::all()['track_' . $type]);
    }

    /**
     * Returns the built-in event types currently enabled for tracking.
     *
     * @return string[]
     */
    public static function enabledTypes(): array
    {
        return array_values(array_filter(
            self::EVENT_TYPES,
            static fn(string $type): bool => self::isTypeEnabled($type)
        ));
    }

    /**
     * Whether logged-in users should be excluded from tracking.
     *
     * @return bool
     */
    public static function excludeLoggedIn(): bool
    {
        return !empty(self::all()['exclude_logged_in']);
    }

    /**
     * Whether visitors sending Do Not Track / Global Privacy Control signals
     * should be excluded from tracking. Off by default — enabling it is a
     * site-owner choice that typically reduces recorded traffic.
     *
     * @return bool
     */
    public static function respectDnt(): bool
    {
        return !empty(self::all()['respect_dnt']);
    }

    /**
     * Hostnames that tracked page URLs (and Origin/Referer checks) may use,
     * and that referrer reports treat as internal.
     *
     * @return string[] Lowercase hostnames; by default the home and site URL
     *                  hosts. Extend via the 'spa_allowed_hosts' filter for
     *                  multi-domain setups.
     */
    public static function allowedHosts(): array
    {
        // Memoized: the REST endpoint consults this once per event in a
        // batch, and the URLs it derives from cannot change mid-request.
        static $allowed = null;

        if ($allowed !== null) {
            return $allowed;
        }

        $hosts = array_values(array_unique(array_filter([
            strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)),
            strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST)),
        ])));

        /**
         * Filters the hostnames accepted in tracked page URLs and
         * Origin/Referer checks, and treated as internal in referrer reports.
         *
         * @param string[] $hosts Lowercase hostnames.
         */
        return $allowed = (array) apply_filters('spa_allowed_hosts', $hosts);
    }

    /**
     * Number of days analytics rows are retained before the daily cleanup
     * cron deletes them. Clamped to a sane 7–365 range.
     *
     * @return int
     */
    public static function retentionDays(): int
    {
        return min(365, max(7, (int) self::all()['retention_days']));
    }

    /**
     * Milliseconds the pointer must rest on an element before a hover event
     * is recorded. Clamped to 200–10000 so a typo can't flood the table.
     *
     * @return int
     */
    public static function hoverDwellMs(): int
    {
        return min(10000, max(200, (int) self::all()['hover_dwell_ms']));
    }

    /**
     * Whether webhook delivery is currently active.
     *
     * Toggling this off pauses new scheduled deliveries without discarding
     * any saved endpoint configuration — matching the "Webhook Status"
     * toggle's own description on the Settings page. Already-scheduled
     * retry attempts (started before the toggle was flipped off) are left
     * to run their course rather than being torn down mid-chain.
     *
     * @return bool
     */
    public static function webhooksActive(): bool
    {
        return !empty(self::all()['webhook_active']);
    }

    /**
     * Returns the configured webhooks, each with its URL, optional label,
     * and optional per-endpoint signing secret.
     *
     * Pre-1.1.0 installs stored a flat list of URLs under 'webhook_urls'
     * (no labels); when no 'webhooks' entries are saved yet, those legacy
     * URLs are synthesized into unlabeled entries so upgrading never loses
     * a configured endpoint.
     *
     * @return array<int, array{url: string, label: string, secret: string}>
     */
    public static function webhooks(): array
    {
        $all = self::all();
        $raw = $all['webhooks'] ?? null;

        if (!is_array($raw) || $raw === []) {
            $legacy = is_array($all['webhook_urls'] ?? null) ? $all['webhook_urls'] : [];
            $raw    = array_map(static fn(mixed $url): array => ['url' => (string) $url, 'label' => ''], $legacy);
        }

        $out = [];
        foreach ($raw as $entry) {
            $url = trim(is_array($entry) ? (string) ($entry['url'] ?? '') : (string) $entry);
            if ($url === '') {
                continue;
            }

            $out[] = [
                'url'    => $url,
                'label'  => is_array($entry) ? trim((string) ($entry['label'] ?? '')) : '',
                'secret' => is_array($entry) ? trim((string) ($entry['secret'] ?? '')) : '',
            ];
        }

        return $out;
    }

    /**
     * Returns the configured webhook endpoint URLs.
     *
     * @return string[] Zero or more absolute http(s) URLs.
     */
    public static function webhookUrls(): array
    {
        return array_values(array_unique(array_column(self::webhooks(), 'url')));
    }

    /**
     * The human-readable label configured for an endpoint, or '' when none
     * was set. Used to badge Delivery Log entries so a specific endpoint is
     * easy to spot at a glance.
     *
     * @param string $url Endpoint URL (exact match against saved webhooks).
     * @return string
     */
    public static function webhookLabel(string $url): string
    {
        foreach (self::webhooks() as $webhook) {
            if ($webhook['url'] === $url) {
                return $webhook['label'];
            }
        }

        return '';
    }

    /**
     * Returns the cron recurrence used for webhook dispatch.
     *
     * @return string One of {@see self::INTERVALS}.
     */
    public static function webhookInterval(): string
    {
        $interval = (string) self::all()['webhook_interval'];

        return in_array($interval, self::INTERVALS, true) ? $interval : 'daily';
    }

    /**
     * The shared secret used to sign webhook request bodies, or '' when
     * signing is not configured.
     *
     * When set, every webhook request carries an X-SPA-Signature header of
     * the form 'sha256=<hex>' — the HMAC-SHA256 of the exact raw JSON body,
     * keyed with this secret — so a receiver can verify the payload came
     * from this installation and was not altered in transit. The signature
     * is computed at send time from the frozen body, so rotating the secret
     * mid-retry simply signs the identical bytes with the new key.
     *
     * @return string
     */
    public static function webhookSecret(): string
    {
        return (string) self::all()['webhook_secret'];
    }

    /**
     * The signing secret effective for one endpoint: the per-endpoint secret
     * saved on its webhook block when set, otherwise the shared secret.
     * Per-endpoint secrets mean one compromised receiver never learns the
     * key that authenticates payloads to every other receiver.
     *
     * @param string $url Endpoint URL (exact match against saved webhooks).
     * @return string Secret to sign with, or '' when signing is not configured.
     */
    public static function webhookSecretFor(string $url): string
    {
        foreach (self::webhooks() as $webhook) {
            if ($webhook['url'] === $url && $webhook['secret'] !== '') {
                return $webhook['secret'];
            }
        }

        return self::webhookSecret();
    }

    /**
     * Whether a newly added webhook endpoint should be backfilled with the
     * full retained history (in interval-sized windows) instead of starting
     * from one send interval ago.
     *
     * Applies to any endpoint that has never had a successful delivery at
     * the moment its first window is computed — enabling this after an
     * endpoint has already received data changes nothing for that endpoint.
     *
     * @return bool
     */
    public static function webhookBackfill(): bool
    {
        return !empty(self::all()['webhook_backfill']);
    }

    /**
     * The client's first name, sent as 'website_info.client.first_name' in
     * every webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function clientFirstName(): string
    {
        return (string) self::all()['client_first_name'];
    }

    /**
     * The client's last name, sent as 'website_info.client.last_name' in
     * every webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function clientLastName(): string
    {
        return (string) self::all()['client_last_name'];
    }

    /**
     * Optional client identifier, sent as 'website_info.client.id' in every
     * webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function clientId(): string
    {
        return (string) self::all()['client_id'];
    }

    /**
     * Optional website identifier, sent as 'website_info.id' in every
     * webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function websiteId(): string
    {
        return (string) self::all()['website_id'];
    }

    /**
     * Converts a cron recurrence name to its length in seconds.
     *
     * Used to derive the reporting window for an endpoint that has never
     * received a payload (its first window is exactly one interval long).
     *
     * @param string $interval One of {@see self::INTERVALS}.
     * @return int
     */
    public static function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
            default      => DAY_IN_SECONDS,
        };
    }
}
