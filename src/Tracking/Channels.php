<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Tracking;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;

/**
 * Marketing-channel classification and traffic-source normalization.
 *
 * Raw campaign data fragments easily — "Facebook", "fb", and "facebook.com"
 * are the same source, and "cpc" vs "ppc" the same medium — so reports built
 * on raw values split one campaign across many rows. This class fixes both
 * problems at ingestion time:
 *
 *  - {@see normalizeSource()} maps common source aliases onto one canonical
 *    name (extendable via the 'spa_source_aliases' filter), and
 *  - {@see classify()} derives a coarse marketing channel (Paid Search,
 *    Organic Social, Email, Referral, Direct, …) from the utm fields, the
 *    ad-click identifier type, and the referrer (overridable per event via
 *    the 'spa_channel' filter).
 *
 * The channel is stored on the event row, so classification-rule changes
 * apply to new data only — reports never reinterpret history.
 *
 * Ad-click identifiers: only the parameter NAME (e.g. "gclid") is ever kept,
 * as click_id_type. The identifier's value is a cross-site advertising ID
 * that may qualify as personal data, so it is never stored.
 */
final class Channels
{
    /** @var string[] Ad-click identifier parameter names the tracker recognizes. */
    public const CLICK_ID_TYPES = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id'];

    /** @var string[] Click identifiers that only paid-search ads produce. */
    private const PAID_SEARCH_CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid'];

    /** @var string[] Click identifiers that only paid-social ads produce. */
    private const PAID_SOCIAL_CLICK_IDS = ['ttclid', 'twclid', 'li_fat_id'];

    /** @var string[] Canonical search-engine source names (also matched as referrer host labels). */
    private const SEARCH_SOURCES = [
        'google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex',
        'ecosia', 'qwant', 'brave', 'startpage', 'aol', 'ask',
    ];

    /** @var string[] Canonical social-network source names (also matched as referrer host labels). */
    private const SOCIAL_SOURCES = [
        'facebook', 'instagram', 'twitter', 'linkedin', 'pinterest', 'tiktok',
        'youtube', 'reddit', 'threads', 'snapchat', 'mastodon', 'bluesky',
        'whatsapp', 'telegram', 'messenger', 'discord', 'tumblr', 'quora', 'nextdoor',
    ];

    /**
     * Normalizes a utm_source value onto one canonical, lowercase name.
     *
     * @param string $source Raw utm_source value.
     * @return string Canonical source (e.g. "fb", "facebook.com" → "facebook").
     */
    public static function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === '') {
            return '';
        }

        $aliases = [
            'facebook.com'   => 'facebook',
            'm.facebook.com' => 'facebook',
            'l.facebook.com' => 'facebook',
            'fb'             => 'facebook',
            'fb.com'         => 'facebook',
            'meta'           => 'facebook',
            'instagram.com'  => 'instagram',
            'ig'             => 'instagram',
            'twitter.com'    => 'twitter',
            'x'              => 'twitter',
            'x.com'          => 'twitter',
            't.co'           => 'twitter',
            'linkedin.com'   => 'linkedin',
            'lnkd.in'        => 'linkedin',
            'youtube.com'    => 'youtube',
            'youtu.be'       => 'youtube',
            'tiktok.com'     => 'tiktok',
            'pinterest.com'  => 'pinterest',
            'google.com'     => 'google',
            'adwords'        => 'google',
            'bing.com'       => 'bing',
            'microsoft'      => 'bing',
        ];

        /**
         * Filters the utm_source alias map used to normalize sources at
         * ingestion. Keys are raw lowercase values, values the canonical name.
         *
         * @param array<string, string> $aliases The default alias map.
         */
        $aliases = (array) apply_filters('spa_source_aliases', $aliases);

        return isset($aliases[$source]) ? (string) $aliases[$source] : $source;
    }

    /**
     * Derives the marketing channel for one event row.
     *
     * Precedence: ad-click identifier (unambiguous), then utm_medium/source
     * conventions, then — for untagged traffic — the referrer. Untagged
     * pageviews with an internal referrer return '' (mid-session navigation,
     * not an entrance); a conversion in the same situation falls back to
     * Direct so every conversion carries a channel.
     *
     * @param array<string, string> $row  Sanitized event row (utm_*, click_id_type, referrer).
     * @param string                $type Event type ('pageview' or 'form_success').
     * @return string Channel label, or '' for mid-session pageviews.
     */
    public static function classify(array $row, string $type): string
    {
        $channel = self::resolve($row, $type === 'form_success');

        /**
         * Filters the marketing channel assigned to an event before storage.
         *
         * @param string                $channel The derived channel label ('' = unclassified).
         * @param array<string, string> $row     The sanitized event row.
         * @param string                $type    The event type.
         */
        return (string) apply_filters('spa_channel', $channel, $row, $type);
    }

    /**
     * The rule chain behind {@see classify()}.
     *
     * @param array<string, string> $row          Sanitized event row.
     * @param bool                  $isConversion Whether Direct should be the fallback for internal referrers.
     * @return string
     */
    private static function resolve(array $row, bool $isConversion): string
    {
        $source  = strtolower((string) ($row['utm_source'] ?? ''));
        $medium  = strtolower((string) ($row['utm_medium'] ?? ''));
        $clickId = (string) ($row['click_id_type'] ?? '');
        $tagged  = $source !== '' || $medium !== '' || (string) ($row['utm_campaign'] ?? '') !== '';

        if (in_array($clickId, self::PAID_SEARCH_CLICK_IDS, true)) {
            return 'Paid Search';
        }
        if (in_array($clickId, self::PAID_SOCIAL_CLICK_IDS, true)) {
            return 'Paid Social';
        }

        $socialSource = in_array($source, self::SOCIAL_SOURCES, true);

        if (preg_match('~^(cpc|ppc|sem|paid[_\- ]?search)$~', $medium)) {
            return $socialSource ? 'Paid Social' : 'Paid Search';
        }
        if (str_contains($medium, 'paid')) {
            return ($socialSource || str_contains($medium, 'social')) ? 'Paid Social' : 'Paid Search';
        }
        if (preg_match('~^(display|banner|cpm|retargeting|remarketing)$~', $medium)) {
            return 'Display';
        }
        if (preg_match('~^(email|e[_\-]?mail|newsletter)$~', $medium) || $source === 'email' || $source === 'newsletter') {
            return 'Email';
        }
        if (preg_match('~^(sms|mms|text)$~', $medium)) {
            return 'SMS';
        }
        if (preg_match('~^(affiliate|partner)$~', $medium)) {
            return 'Affiliate';
        }
        if (str_contains($medium, 'social') || $socialSource) {
            return 'Organic Social';
        }
        if ($medium === 'organic' || in_array($source, self::SEARCH_SOURCES, true)) {
            return 'Organic Search';
        }
        if ($medium === 'referral' || $medium === 'link') {
            return 'Referral';
        }
        if ($tagged) {
            return 'Other';
        }

        // Untagged traffic: the referrer decides.
        $host = strtolower((string) wp_parse_url((string) ($row['referrer'] ?? ''), PHP_URL_HOST));

        if ($host === '') {
            return 'Direct';
        }
        if (in_array($host, Options::allowedHosts(), true)) {
            return $isConversion ? 'Direct' : '';
        }
        if (self::hostMatches($host, self::SEARCH_SOURCES)) {
            return 'Organic Search';
        }
        if (self::hostMatches($host, self::SOCIAL_SOURCES)) {
            return 'Organic Social';
        }

        return 'Referral';
    }

    /**
     * Whether any dot-separated label of a hostname matches a known source
     * name — so "l.facebook.com" and "www.google.co.uk" match without a
     * substring comparison that lookalike domains could game.
     *
     * @param string   $host    Lowercase hostname.
     * @param string[] $sources Canonical source names.
     * @return bool
     */
    private static function hostMatches(string $host, array $sources): bool
    {
        foreach (explode('.', $host) as $label) {
            if (in_array($label, $sources, true)) {
                return true;
            }
        }

        return false;
    }
}
