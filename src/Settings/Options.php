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
    public const EVENT_TYPES = ['pageview', 'click', 'form_submit', 'hover', 'scroll_depth'];

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
            'track_hover'        => true,
            'track_scroll_depth' => true,
            'exclude_logged_in'  => true,
            'respect_dnt'        => false,
            'retention_days'     => 90,
            'hover_dwell_ms'     => 800,
            'webhook_urls'       => [],
            'webhook_interval'   => 'daily',
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
        return (array) apply_filters('spa_allowed_hosts', $hosts);
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
     * Returns the configured webhook endpoint URLs.
     *
     * @return string[] Zero or more absolute http(s) URLs.
     */
    public static function webhookUrls(): array
    {
        $urls = self::all()['webhook_urls'];

        return is_array($urls) ? array_values(array_filter(array_map('strval', $urls))) : [];
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
