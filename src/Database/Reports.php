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
     * pageview events, joined with the confirmed conversions (form_success)
     * attributed to the same campaign. The tracker attributes a whole session
     * to its landing campaign, so these are session-attributed numbers, not
     * just tagged landings.
     *
     * A row qualifies when *any* campaign field is set — URLs tagged with
     * only utm_campaign or utm_medium still represent campaign traffic.
     * Conversions are counted by DISTINCT conversion id, so an at-least-once
     * redelivery of the same conversion never double-counts.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{utm_source: string, utm_medium: string, utm_campaign: string, utm_id: string, channel: string, views: int, sessions: int, conversions: int, conversion_rate: float}>
     */
    public static function topCampaigns(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table  = DatabaseManager::tableName();
        $tagged = "(utm_source <> '' OR utm_medium <> '' OR utm_campaign <> '')";

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT utm_source, utm_medium, utm_campaign, MAX(utm_id) AS utm_id,
                        MAX(channel) AS channel,
                        COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
                 FROM {$table}
                 WHERE event_type = 'pageview' AND {$tagged}
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

        $conversionRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT utm_source, utm_medium, utm_campaign,
                        COUNT(DISTINCT event_value) AS conversions
                 FROM {$table}
                 WHERE event_type = 'form_success' AND {$tagged}
                   AND created_at >= %s AND created_at < %s
                 GROUP BY utm_source, utm_medium, utm_campaign",
                $start,
                $end
            ),
            ARRAY_A
        );

        $conversions = [];
        foreach ((array) $conversionRows as $row) {
            $key = $row['utm_source'] . '|' . $row['utm_medium'] . '|' . $row['utm_campaign'];
            $conversions[$key] = (int) $row['conversions'];
        }

        return array_map(static function (array $row) use ($conversions): array {
            $key      = $row['utm_source'] . '|' . $row['utm_medium'] . '|' . $row['utm_campaign'];
            $sessions = (int) $row['sessions'];
            $count    = $conversions[$key] ?? 0;

            return [
                'utm_source'      => (string) $row['utm_source'],
                'utm_medium'      => (string) $row['utm_medium'],
                'utm_campaign'    => (string) $row['utm_campaign'],
                'utm_id'          => (string) $row['utm_id'],
                'channel'         => (string) $row['channel'],
                'views'           => (int) $row['views'],
                'sessions'        => $sessions,
                'conversions'     => $count,
                'conversion_rate' => $sessions > 0 ? round($count / $sessions * 100, 2) : 0.0,
            ];
        }, (array) $rows);
    }

    /**
     * Sessions and confirmed conversions per marketing channel within a range.
     *
     * The channel is classified at ingestion (see
     * {@see \SitePulseAnalytics\Tracking\Channels}). Pageviews with an empty
     * channel — mid-session internal navigation — are excluded, so each
     * session is counted under the channel(s) it actually entered through.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<int, array{channel: string, views: int, sessions: int, conversions: int, conversion_rate: float}>
     */
    public static function channelBreakdown(string $start, string $end): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT channel, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
                 FROM {$table}
                 WHERE event_type = 'pageview' AND channel <> ''
                   AND created_at >= %s AND created_at < %s
                 GROUP BY channel
                 ORDER BY sessions DESC",
                $start,
                $end
            ),
            ARRAY_A
        );

        $conversionRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT channel, COUNT(DISTINCT event_value) AS conversions
                 FROM {$table}
                 WHERE event_type = 'form_success'
                   AND created_at >= %s AND created_at < %s
                 GROUP BY channel",
                $start,
                $end
            ),
            ARRAY_A
        );

        $conversions = [];
        foreach ((array) $conversionRows as $row) {
            $conversions[(string) $row['channel']] = (int) $row['conversions'];
        }

        $out = [];
        foreach ((array) $rows as $row) {
            $channel  = (string) $row['channel'];
            $sessions = (int) $row['sessions'];
            $count    = $conversions[$channel] ?? 0;
            unset($conversions[$channel]);

            $out[] = [
                'channel'         => $channel,
                'views'           => (int) $row['views'],
                'sessions'        => $sessions,
                'conversions'     => $count,
                'conversion_rate' => $sessions > 0 ? round($count / $sessions * 100, 2) : 0.0,
            ];
        }

        // Channels that converted without a pageview in the window (e.g. a
        // session that landed just before the window started) still surface.
        foreach ($conversions as $channel => $count) {
            if ($channel !== '') {
                $out[] = [
                    'channel'         => $channel,
                    'views'           => 0,
                    'sessions'        => 0,
                    'conversions'     => $count,
                    'conversion_rate' => 0.0,
                ];
            }
        }

        return $out;
    }

    /**
     * Total confirmed conversions within a range, deduplicated by conversion
     * id (event_value) — at-least-once delivery can store the same conversion
     * twice, and duplicates must never inflate this number.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return int
     */
    public static function conversionCount(string $start, string $end): int
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT event_value)
                 FROM {$table}
                 WHERE event_type = 'form_success'
                   AND created_at >= %s AND created_at < %s",
                $start,
                $end
            )
        );
    }

    /**
     * Individual confirmed conversions within a range, newest first, each
     * with the attribution snapshot taken when it occurred — self-contained
     * records ready for a CRM or automation platform.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum conversions to return.
     * @return array<int, array<string, string>>
     */
    public static function recentConversions(string $start, string $end, int $limit = 100): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_value, element_label, page_url, referrer, device, channel,
                        utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content,
                        click_id_type, created_at
                 FROM {$table}
                 WHERE event_type = 'form_success'
                   AND created_at >= %s AND created_at < %s
                 ORDER BY id DESC
                 LIMIT %d",
                $start,
                $end,
                $limit
            ),
            ARRAY_A
        );

        $out  = [];
        $seen = [];
        foreach ((array) $rows as $row) {
            $id = (string) $row['event_value'];
            if ($id !== '' && isset($seen[$id])) {
                continue; // At-least-once duplicate of a conversion already listed.
            }
            $seen[$id] = true;

            $out[] = [
                'conversion_id' => $id,
                'form'          => (string) $row['element_label'],
                'page_url'      => (string) $row['page_url'],
                'referrer'      => (string) $row['referrer'],
                'device'        => (string) $row['device'],
                'occurred_at'   => (string) $row['created_at'],
                'attribution'   => [
                    'channel'       => (string) $row['channel'],
                    'utm_source'    => (string) $row['utm_source'],
                    'utm_medium'    => (string) $row['utm_medium'],
                    'utm_campaign'  => (string) $row['utm_campaign'],
                    'utm_id'        => (string) $row['utm_id'],
                    'utm_term'      => (string) $row['utm_term'],
                    'utm_content'   => (string) $row['utm_content'],
                    'click_id_type' => (string) $row['click_id_type'],
                ],
            ];
        }

        return $out;
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
            'channels'        => self::channelBreakdown($start, $end),
            'conversions'     => [
                'total'  => self::conversionCount($start, $end),
                'recent' => self::recentConversions($start, $end),
            ],
            'devices'         => self::deviceBreakdown($start, $end),
        ];
    }
}
