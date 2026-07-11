<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Admin;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Database\Reports;
use SitePulseAnalytics\Tracking\RestController;

/**
 * The top-level "SitePulse" admin page that visualizes collected analytics.
 *
 * Renders, for a selectable period (7/30/90 days):
 *  - summary cards with totals per interaction type,
 *  - a daily page-view bar chart (pure CSS, no JS charting library),
 *  - top pages, top clicked elements, top forms, and top hovered elements,
 *  - a recent-activity feed of the latest raw events.
 *
 * All numbers come from {@see Reports}, the same query layer the webhook
 * payload uses, so the dashboard and webhook consumers always agree.
 */
final class DashboardPage
{
    /** @var string Menu slug for the top-level page. */
    public const MENU_SLUG = 'sitepulse-analytics';

    /** @var int[] Periods (in days) selectable in the dashboard filter. */
    private const PERIODS = [7, 30, 90];

    /**
     * Registers the admin menu and asset hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Adds the top-level SitePulse menu entry.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_menu_page(
            'SitePulse Analytics',
            'SitePulse Analytics',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render'],
            'dashicons-chart-area',
            58
        );
    }

    /**
     * Enqueues the shared admin stylesheet on plugin pages only.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_style(
            'spa-admin',
            SPA_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SPA_VERSION
        );
    }

    /**
     * Renders the dashboard page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $days = self::currentPeriod();
        $end  = gmdate('Y-m-d H:i:s');

        // Align the range to calendar days (UTC): the period covers the last
        // N days *including today*, so the chart renders exactly N bars.
        $start = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * DAY_IN_SECONDS);

        $totals = Reports::totalsByType($start, $end);
        $daily  = Reports::dailyCounts($start, $end, 'pageview');

        echo '<div class="wrap spa-wrap">';
        echo '<h1>SitePulse Analytics</h1>';

        self::maybeRenderRateLimitNotice();
        self::renderPeriodFilter($days);
        self::renderSummaryCards($totals);
        self::renderPageviewChart($daily, $days);

        echo '<div class="spa-tables">';
        self::renderTopPages($start, $end);
        self::renderTopClicks($start, $end);
        self::renderTopForms($start, $end);
        self::renderTopHovers($start, $end);
        self::renderTopReferrers($start, $end);
        self::renderCampaigns($start, $end);
        self::renderDevices($start, $end);
        echo '</div>';

        self::renderRecentEvents();

        echo '</div>';
    }

    /**
     * Warns when the site-wide ingestion rate limit was hit in the last 24
     * hours — events were dropped, so the numbers below may undercount.
     *
     * @return void
     */
    private static function maybeRenderRateLimitNotice(): void
    {
        $hitAt = (int) get_transient(RestController::RATE_LIMITED_FLAG);
        if ($hitAt <= 0) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>SitePulse Analytics:</strong> '
            . 'The site-wide event rate limit was reached in the last 24 hours (first at '
            . esc_html(gmdate('Y-m-d H:i', $hitAt)) . ' UTC), so some visitor events were not recorded. '
            . 'If this is legitimate traffic rather than a flood, raise the limits with the '
            . '<code>spa_rate_limits</code> filter.</p></div>';
    }

    /**
     * Returns the validated period (in days) from the request, defaulting to 30.
     *
     * @return int
     */
    private static function currentPeriod(): int
    {
        $days = isset($_GET['period']) ? (int) $_GET['period'] : 30;

        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    /**
     * Renders the 7/30/90-day period selector links.
     *
     * @param int $active Currently selected period in days.
     * @return void
     */
    private static function renderPeriodFilter(int $active): void
    {
        echo '<p class="spa-period-filter">Showing the last ';

        $links = [];
        foreach (self::PERIODS as $days) {
            $url = add_query_arg(
                ['page' => self::MENU_SLUG, 'period' => $days],
                self_admin_url('admin.php')
            );

            $links[] = $days === $active
                ? '<strong>' . (int) $days . ' days</strong>'
                : '<a href="' . esc_url($url) . '">' . (int) $days . ' days</a>';
        }

        echo implode(' | ', $links) . '</p>';
    }

    /**
     * Renders the totals-per-event-type summary cards.
     *
     * @param array<string, int> $totals Map of event_type → count for the period.
     * @return void
     */
    private static function renderSummaryCards(array $totals): void
    {
        $cards = [
            'pageview'     => 'Page Views',
            'click'        => 'Clicks',
            'form_submit'  => 'Form Submit Attempts',
            'hover'        => 'Hovers',
            'scroll_depth' => 'Scroll Milestones',
        ];

        // Any custom event types recorded via spa_track_event() are summed
        // into a single "Other Events" card so nothing is invisible.
        $other = array_sum(array_diff_key($totals, $cards));

        echo '<div class="spa-cards">';
        foreach ($cards as $type => $label) {
            echo '<div class="spa-card">';
            echo '<div class="spa-card-value">' . esc_html(number_format_i18n($totals[$type] ?? 0)) . '</div>';
            echo '<div class="spa-card-label">' . esc_html($label) . '</div>';
            echo '</div>';
        }

        if ($other > 0) {
            echo '<div class="spa-card">';
            echo '<div class="spa-card-value">' . esc_html(number_format_i18n($other)) . '</div>';
            echo '<div class="spa-card-label">Other Events</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Renders the daily page-view bar chart.
     *
     * A flexbox of divs whose heights are scaled to the busiest day; each bar
     * carries a title attribute for a native hover tooltip, so no charting
     * library is needed.
     *
     * @param array<int, array{date: string, count: int}> $daily Zero-filled daily series.
     * @param int                                         $days  Period length, for the heading.
     * @return void
     */
    private static function renderPageviewChart(array $daily, int $days): void
    {
        $max = 0;
        foreach ($daily as $point) {
            $max = max($max, $point['count']);
        }

        echo '<div class="spa-section">';
        echo '<h2>Daily Page Views (' . (int) $days . ' days)</h2>';
        echo '<div class="spa-chart" role="img" aria-label="Daily page views bar chart">';

        foreach ($daily as $point) {
            $height = $max > 0 ? max(2, (int) round($point['count'] / $max * 100)) : 2;
            $title  = sprintf(
                '%s — %s page view%s',
                $point['date'],
                number_format_i18n($point['count']),
                $point['count'] === 1 ? '' : 's'
            );

            echo '<div class="spa-chart-bar" style="height:' . (int) $height . '%" title="' . esc_attr($title) . '"></div>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Renders the "Top Pages" table.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderTopPages(string $start, string $end): void
    {
        $rows = Reports::topPages($start, $end);

        echo '<div class="spa-section">';
        echo '<h2>Top Pages</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>Page</th><th>Views</th><th>Sessions</th></tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="3">No page views recorded in this period.</td></tr>';
        }

        foreach ($rows as $row) {
            $label = $row['page_title'] !== '' ? $row['page_title'] : $row['page_url'];
            echo '<tr>';
            echo '<td><a href="' . esc_url($row['page_url']) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a></td>';
            echo '<td>' . esc_html(number_format_i18n($row['views'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($row['sessions'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Renders the "Top Clicked Elements" table.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderTopClicks(string $start, string $end): void
    {
        $rows = Reports::topClicks($start, $end);

        echo '<div class="spa-section">';
        echo '<h2>Top Clicked Elements</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>Element</th><th>Destination</th><th>Clicks</th></tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="3">No clicks recorded in this period.</td></tr>';
        }

        foreach ($rows as $row) {
            $label = $row['element_label'] !== '' ? $row['element_label'] : '(unlabeled ' . $row['element_tag'] . ')';
            echo '<tr>';
            echo '<td>' . esc_html($label) . '</td>';
            echo '<td>' . esc_html($row['target_url'] !== '' ? $row['target_url'] : '—') . '</td>';
            echo '<td>' . esc_html(number_format_i18n($row['clicks'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Renders the "Top Form Submissions" table.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderTopForms(string $start, string $end): void
    {
        $rows = Reports::topForms($start, $end);

        echo '<div class="spa-section">';
        echo '<h2>Top Form Submit Attempts</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>Form</th><th>Page</th><th>Attempts</th></tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="3">No form submissions recorded in this period.</td></tr>';
        }

        foreach ($rows as $row) {
            $label = $row['element_label'] !== '' ? $row['element_label'] : '(unnamed form)';
            echo '<tr>';
            echo '<td>' . esc_html($label) . '</td>';
            echo '<td>' . esc_html($row['page_url']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($row['submissions'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Renders the "Most Hovered Elements" table.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderTopHovers(string $start, string $end): void
    {
        $rows = Reports::topHovers($start, $end);

        echo '<div class="spa-section">';
        echo '<h2>Most Hovered Elements</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>Element</th><th>Type</th><th>Hovers</th></tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="3">No hover activity recorded in this period.</td></tr>';
        }

        foreach ($rows as $row) {
            $label = $row['element_label'] !== '' ? $row['element_label'] : '(unlabeled element)';
            echo '<tr>';
            echo '<td>' . esc_html($label) . '</td>';
            echo '<td>' . esc_html($row['element_tag']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($row['hovers'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Renders the "Top Referrers" table of external traffic sources.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderTopReferrers(string $start, string $end): void
    {
        $rows = Reports::topReferrers($start, $end);

        echo '<div class="spa-section">';
        echo '<h2>Top Referrers</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>Referring Page</th><th>Visits</th></tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="2">No external referrers recorded in this period.</td></tr>';
        }

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['referrer']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($row['visits'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Renders the "Campaigns" table of pageviews attributed to utm parameters.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderCampaigns(string $start, string $end): void
    {
        $rows = Reports::topCampaigns($start, $end);

        echo '<div class="spa-section">';
        echo '<h2>Campaigns</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>Source</th><th>Medium</th><th>Campaign</th><th>Views</th></tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="4">No campaign-tagged (utm) visits recorded in this period.</td></tr>';
        }

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['utm_source'] !== '' ? $row['utm_source'] : '—') . '</td>';
            echo '<td>' . esc_html($row['utm_medium'] !== '' ? $row['utm_medium'] : '—') . '</td>';
            echo '<td>' . esc_html($row['utm_campaign'] !== '' ? $row['utm_campaign'] : '—') . '</td>';
            echo '<td>' . esc_html(number_format_i18n($row['views'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Renders the "Devices" table of page views by device bucket.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderDevices(string $start, string $end): void
    {
        $devices = Reports::deviceBreakdown($start, $end);
        $total   = array_sum($devices);

        echo '<div class="spa-section">';
        echo '<h2>Devices</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>Device</th><th>Page Views</th><th>Share</th></tr></thead><tbody>';

        if ($devices === []) {
            echo '<tr><td colspan="3">No page views recorded in this period.</td></tr>';
        }

        foreach ($devices as $device => $views) {
            $share = $total > 0 ? round($views / $total * 100) . '%' : '—';

            echo '<tr>';
            echo '<td>' . esc_html(ucfirst($device)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($views)) . '</td>';
            echo '<td>' . esc_html($share) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Renders the "Recent Activity" table of the latest raw events.
     *
     * @return void
     */
    private static function renderRecentEvents(): void
    {
        $rows = Reports::recentEvents(15);

        echo '<div class="spa-section">';
        echo '<h2>Recent Activity</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>When (UTC)</th><th>Event</th><th>Page</th><th>Detail</th><th>Device</th></tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="5">No events recorded yet. Visit the site\'s frontend to start collecting data.</td></tr>';
        }

        foreach ($rows as $row) {
            $detail = $row['element_label'] !== '' ? $row['element_label'] : $row['target_url'];
            if (($row['event_value'] ?? '') !== '') {
                $detail = trim($detail . ' (' . $row['event_value'] . ')');
            }

            echo '<tr>';
            echo '<td>' . esc_html($row['created_at']) . '</td>';
            echo '<td>' . esc_html($row['event_type']) . '</td>';
            echo '<td>' . esc_html($row['page_title'] !== '' ? $row['page_title'] : $row['page_url']) . '</td>';
            echo '<td>' . esc_html($detail !== '' ? $detail : '—') . '</td>';
            echo '<td>' . esc_html($row['device']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}
