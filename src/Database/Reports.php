<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Database;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;

/**
 * Read-only aggregate queries over the events table.
 *
 * Every method takes a UTC datetime range ('Y-m-d H:i:s') and returns plain
 * arrays ready for the dashboard tables and the webhook JSON payload, so the
 * two consumers always report identical numbers.
 */
final class Reports
{
    /**
     * Total event counts per event type within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<string, int> Map of event_type → count.
     */
    public static function totalsByType(string $start, string $end): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, COUNT(*) AS total
                 FROM {$table}
                 WHERE created_at >= %s AND created_at < %s
                 GROUP BY event_type",
                $start,
                $end
            ),
            ARRAY_A
        );

        $totals = [];
        foreach ((array) $rows as $row) {
            $totals[(string) $row['event_type']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * Daily counts of one event type across a range, with zero-filled gaps so
     * charts and payloads always contain one entry per calendar day.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param string $type  Event type to count (e.g. "pageview").
     * @return array<int, array{date: string, count: int}>
     */
    public static function dailyCounts(string $start, string $end, string $type): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day, COUNT(*) AS total
                 FROM {$table}
                 WHERE event_type = %s AND created_at >= %s AND created_at < %s
                 GROUP BY day
                 ORDER BY day ASC",
                $type,
                $start,
                $end
            ),
            ARRAY_A
        );

        $byDay = [];
        foreach ((array) $rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['total'];
        }

        $series  = [];
        $current = strtotime(substr($start, 0, 10) . ' 00:00:00 UTC');
        $last    = strtotime(substr($end, 0, 10) . ' 00:00:00 UTC');

        while ($current !== false && $last !== false && $current <= $last) {
            $day      = gmdate('Y-m-d', $current);
            $series[] = ['date' => $day, 'count' => $byDay[$day] ?? 0];
            $current += DAY_IN_SECONDS;
        }

        return $series;
    }

    /**
     * Most-viewed pages within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{page_url: string, page_title: string, views: int, sessions: int}>
     */
    public static function topPages(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT page_url, MAX(page_title) AS page_title,
                        COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
                 FROM {$table}
                 WHERE event_type = 'pageview' AND created_at >= %s AND created_at < %s
                 GROUP BY page_url
                 ORDER BY views DESC
                 LIMIT %d",
                $start,
                $end,
                $limit
            ),
            ARRAY_A
        );

        return array_map(static fn(array $row): array => [
            'page_url'   => (string) $row['page_url'],
            'page_title' => (string) $row['page_title'],
            'views'      => (int) $row['views'],
            'sessions'   => (int) $row['sessions'],
        ], (array) $rows);
    }

    /**
     * Most-clicked links and buttons within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{element_label: string, element_tag: string, target_url: string, clicks: int}>
     */
    public static function topClicks(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT element_label, MAX(element_tag) AS element_tag,
                        target_url, COUNT(*) AS clicks
                 FROM {$table}
                 WHERE event_type = 'click' AND created_at >= %s AND created_at < %s
                 GROUP BY element_label, target_url
                 ORDER BY clicks DESC
                 LIMIT %d",
                $start,
                $end,
                $limit
            ),
            ARRAY_A
        );

        return array_map(static fn(array $row): array => [
            'element_label' => (string) $row['element_label'],
            'element_tag'   => (string) $row['element_tag'],
            'target_url'    => (string) $row['target_url'],
            'clicks'        => (int) $row['clicks'],
        ], (array) $rows);
    }

    /**
     * Most-submitted forms within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{element_label: string, page_url: string, submissions: int}>
     */
    public static function topForms(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT element_label, page_url, COUNT(*) AS submissions
                 FROM {$table}
                 WHERE event_type = 'form_submit' AND created_at >= %s AND created_at < %s
                 GROUP BY element_label, page_url
                 ORDER BY submissions DESC
                 LIMIT %d",
                $start,
                $end,
                $limit
            ),
            ARRAY_A
        );

        return array_map(static fn(array $row): array => [
            'element_label' => (string) $row['element_label'],
            'page_url'      => (string) $row['page_url'],
            'submissions'   => (int) $row['submissions'],
        ], (array) $rows);
    }

    /**
     * Most-hovered elements within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{element_label: string, element_tag: string, hovers: int}>
     */
    public static function topHovers(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT element_label, element_tag, COUNT(*) AS hovers
                 FROM {$table}
                 WHERE event_type = 'hover' AND created_at >= %s AND created_at < %s
                 GROUP BY element_label, element_tag
                 ORDER BY hovers DESC
                 LIMIT %d",
                $start,
                $end,
                $limit
            ),
            ARRAY_A
        );

        return array_map(static fn(array $row): array => [
            'element_label' => (string) $row['element_label'],
            'element_tag'   => (string) $row['element_tag'],
            'hovers'        => (int) $row['hovers'],
        ], (array) $rows);
    }

    /**
     * Top external referrers within a range.
     *
     * Internal navigation (referrers on this site's own host) is excluded so
     * the table answers "who sends us traffic?", not "how do visitors move
     * around?".
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{referrer: string, visits: int}>
     */
    public static function topReferrers(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        // Referrers are stored as scheme://host/path, so the expression below
        // extracts exactly the host. Exact comparison against the allowed-host
        // list can't be fooled by lookalike hosts (e.g. example.com.evil.test)
        // the way a substring LIKE could.
        $hosts        = Options::allowedHosts();
        $placeholders = implode(', ', array_fill(0, count($hosts), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT referrer, COUNT(*) AS visits
                 FROM {$table}
                 WHERE event_type = 'pageview' AND referrer <> ''
                   AND SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1) NOT IN ({$placeholders})
                   AND created_at >= %s AND created_at < %s
                 GROUP BY referrer
                 ORDER BY visits DESC
                 LIMIT %d",
                array_merge($hosts, [$start, $end, $limit])
            ),
            ARRAY_A
        );

        return array_map(static fn(array $row): array => [
            'referrer' => (string) $row['referrer'],
            'visits'   => (int) $row['visits'],
        ], (array) $rows);
    }

    /**
     * Top campaigns within a range, from the utm_* fields captured on
     * pageview events.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{utm_source: string, utm_medium: string, utm_campaign: string, views: int}>
     */
    public static function topCampaigns(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) AS views
                 FROM {$table}
                 WHERE event_type = 'pageview' AND utm_source <> ''
                   AND created_at >= %s AND created_at < %s
                 GROUP BY utm_source, utm_medium, utm_campaign
                 ORDER BY views DESC
                 LIMIT %d",
                $start,
                $end,
                $limit
            ),
            ARRAY_A
        );

        return array_map(static fn(array $row): array => [
            'utm_source'   => (string) $row['utm_source'],
            'utm_medium'   => (string) $row['utm_medium'],
            'utm_campaign' => (string) $row['utm_campaign'],
            'views'        => (int) $row['views'],
        ], (array) $rows);
    }

    /**
     * Page views per device bucket within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<string, int> Map of device (e.g. "desktop", "mobile") → page views.
     */
    public static function deviceBreakdown(string $start, string $end): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT device, COUNT(*) AS views
                 FROM {$table}
                 WHERE event_type = 'pageview' AND created_at >= %s AND created_at < %s
                 GROUP BY device
                 ORDER BY views DESC",
                $start,
                $end
            ),
            ARRAY_A
        );

        $breakdown = [];
        foreach ((array) $rows as $row) {
            $device = (string) $row['device'];
            $breakdown[$device !== '' ? $device : 'unknown'] = (int) $row['views'];
        }

        return $breakdown;
    }

    /**
     * The most recent raw events, newest first. Used for the dashboard's
     * "Recent activity" table.
     *
     * @param int $limit Maximum rows to return.
     * @return array<int, array<string, string>>
     */
    public static function recentEvents(int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, page_url, page_title, element_label, target_url,
                        event_value, device, created_at
                 FROM {$table}
                 ORDER BY id DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return (array) $rows;
    }

    /**
     * Full aggregate summary for a range — the shape shared by the dashboard
     * and the webhook payload's "analytics" section.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<string, mixed>
     */
    public static function buildSummary(string $start, string $end): array
    {
        return [
            'totals'          => self::totalsByType($start, $end),
            'daily_pageviews' => self::dailyCounts($start, $end, 'pageview'),
            'top_pages'       => self::topPages($start, $end),
            'top_clicks'      => self::topClicks($start, $end),
            'top_forms'       => self::topForms($start, $end),
            'top_hovers'      => self::topHovers($start, $end),
            'top_referrers'   => self::topReferrers($start, $end),
            'top_campaigns'   => self::topCampaigns($start, $end),
            'devices'         => self::deviceBreakdown($start, $end),
        ];
    }
}
