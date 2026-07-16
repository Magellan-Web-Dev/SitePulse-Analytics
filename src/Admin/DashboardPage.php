<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Admin;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Database\Reports;
use SitePulseAnalytics\Settings\Options;
use SitePulseAnalytics\Tracking\RestController;

/**
 * The top-level "SitePulse" admin page that visualizes collected analytics.
 *
 * Renders, for a selectable period (7/30/90 days), an Overview section
 * (summary cards and an accessible daily page-view chart) followed by
 * collapsible report sections — Content, Engagement, Acquisition, Devices,
 * and Recent Activity — so the page stays scannable without hiding any data.
 *
 * The chart is dependency-free: each day is a real <button> with its value in
 * an accessible label and data attributes (assets/js/dashboard.js adds the
 * visual tooltip), and a "View data table" fallback exposes every daily value
 * even without JavaScript. Collapsible sections are native <details>
 * elements, so they are keyboard accessible with no script at all.
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
     * Enqueues the shared admin stylesheet on plugin pages only, and the
     * dashboard script (chart tooltips) on the main analytics screen only —
     * not on the Settings, About, or Delivery Log subpages.
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

        if ($hook === 'toplevel_page_' . self::MENU_SLUG) {
            wp_enqueue_style(
                'spa-dashboard',
                SPA_PLUGIN_URL . 'assets/css/dashboard.css',
                ['spa-admin'],
                SPA_VERSION
            );

            wp_enqueue_script(
                'spa-dashboard',
                SPA_PLUGIN_URL . 'assets/js/dashboard.js',
                [],
                SPA_VERSION,
                true
            );
        }
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

        echo '<div class="wrap spa-wrap spa-dash">';
        echo '<h1>SitePulse Analytics</h1>';

        self::maybeRenderRateLimitNotice();
        self::renderPeriodFilter($days);

        echo '<section class="spa-overview" aria-labelledby="spa-h-overview">';
        echo '<h2 id="spa-h-overview">Overview</h2>';
        self::renderSummaryCards($totals);
        self::renderPageviewChart($daily, $days);
        echo '</section>';

        // Revealed by dashboard.js: without JavaScript the buttons would do
        // nothing, and the <details> panels are already usable natively.
        echo '<div class="spa-panel-toolbar" hidden>';
        echo '<button type="button" class="button spa-panels-expand">Expand all sections</button>';
        echo '<button type="button" class="button spa-panels-collapse">Collapse all sections</button>';
        echo '<button type="button" class="button spa-print-btn">Print / Save as PDF</button>';
        echo '</div>';

        self::panelStart('content', 'Content', 'Which pages draw traffic and where visitors arrive.', true);
        echo '<div class="spa-tables">';
        self::renderTopPages($start, $end);
        self::renderLandingPages($start, $end);
        echo '</div>';
        self::panelEnd();

        self::panelStart('engagement', 'Engagement', 'How visitors interact with your pages: clicks, form activity, and attention.');
        echo '<div class="spa-tables">';
        self::renderTopClicks($start, $end);
        self::renderTopForms($start, $end);
        self::renderTopHovers($start, $end);
        echo '</div>';
        self::panelEnd();

        self::panelStart('acquisition', 'Acquisition', 'Where traffic comes from: referrers, campaigns, and marketing channels.');
        echo '<div class="spa-tables">';
        self::renderTopReferrers($start, $end);
        self::renderChannels($start, $end);
        self::renderCampaigns($start, $end);
        self::renderCampaignContent($start, $end);
        echo '</div>';
        self::panelEnd();

        self::panelStart('devices', 'Devices', 'Mobile versus desktop share of page views.');
        self::renderDevices($start, $end);
        self::panelEnd();

        self::panelStart('recent', 'Recent Activity');
        self::renderRecentEvents();
        self::panelEnd();

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

        // spa-rate-limit-notice keeps this warning visible in the printed
        // report too — it flags that the numbers below may undercount.
        echo '<div class="notice notice-warning spa-rate-limit-notice"><p><strong>SitePulse Analytics:</strong> '
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
     * Renders the 7/30/90-day period selector as a segmented button group,
     * with the covered UTC date range spelled out below it.
     *
     * @param int $active Currently selected period in days.
     * @return void
     */
    private static function renderPeriodFilter(int $active): void
    {
        $startLabel = self::utcDate('M j, Y', time() - ($active - 1) * DAY_IN_SECONDS);
        $endLabel   = self::utcDate('M j, Y', time());

        echo '<div class="spa-period">';
        echo '<nav class="spa-period-group" aria-label="Reporting period">';

        foreach (self::PERIODS as $days) {
            $url = add_query_arg(
                ['page' => self::MENU_SLUG, 'period' => $days],
                self_admin_url('admin.php')
            );

            $isActive = $days === $active;

            echo '<a class="spa-period-btn' . ($isActive ? ' is-active' : '') . '"'
                . ($isActive ? ' aria-current="page"' : '')
                . ' href="' . esc_url($url) . '">Last ' . (int) $days . ' days</a>';
        }

        echo '</nav>';
        echo '<p class="spa-period-range">'
            . esc_html($startLabel) . ' &ndash; ' . esc_html($endLabel)
            . '. Dates are UTC; the current day is still collecting data.</p>';

        // Print-only report header (dashboard.css shows it in @media print):
        // site, range, and generation time so a saved PDF is self-describing.
        $generatedFormat = trim(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a'));
        echo '<p class="spa-print-meta">'
            . esc_html(get_bloginfo('name')) . ' &mdash; SitePulse Analytics report &middot; '
            . esc_html($startLabel) . ' &ndash; ' . esc_html($endLabel) . ' (UTC, last ' . (int) $active . ' days; the final day was still collecting when generated) &middot; Generated '
            . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s'), $generatedFormat))
            . ' (' . esc_html(self::siteTimezoneLabel()) . ')</p>';
        echo '</div>';
    }

    /**
     * Renders the totals-per-event-type summary cards, with short
     * explanations under metrics whose meaning isn't self-evident.
     *
     * @param array<string, int> $totals Map of event_type → count for the period.
     * @return void
     */
    private static function renderSummaryCards(array $totals): void
    {
        $cards = [
            'pageview'     => ['Page Views', ''],
            'click'        => ['Clicks', ''],
            'form_submit'  => ['Form Submit Attempts', 'Counted when a form is submitted, before the server confirms success.'],
            'form_success' => ['Confirmed Conversions', 'Submissions the form plugin confirmed the server accepted.'],
            'hover'        => ['Hovers', ''],
            'scroll_depth' => ['Scroll Milestones', 'Times visitors reached 25%, 50%, 75%, or 100% of a page.'],
        ];

        // Any custom event types recorded via spa_track_event() are summed
        // into a single "Other Events" card so nothing is invisible.
        $other = array_sum(array_diff_key($totals, $cards));

        echo '<div class="spa-cards">';
        foreach ($cards as $type => [$label, $desc]) {
            self::renderStatCard(number_format_i18n($totals[$type] ?? 0), $label, $desc);
        }

        if ($other > 0) {
            self::renderStatCard(number_format_i18n($other), 'Other Events', 'Custom event types recorded via spa_track_event().');
        }
        echo '</div>';
    }

    /**
     * Renders a single summary card.
     *
     * @param string $value Formatted metric value.
     * @param string $label Metric name.
     * @param string $desc  Optional one-line explanation.
     * @return void
     */
    private static function renderStatCard(string $value, string $label, string $desc): void
    {
        echo '<div class="spa-card spa-stat-card">';
        echo '<span class="spa-card-value">' . esc_html($value) . '</span>';
        echo '<span class="spa-card-label">' . esc_html($label) . '</span>';
        if ($desc !== '') {
            echo '<span class="spa-card-desc">' . esc_html($desc) . '</span>';
        }
        echo '</div>';
    }

    /**
     * Renders the daily page-view chart.
     *
     * Each day is a <button> (keyboard-, mouse-, and touch-reachable) whose
     * accessible label carries the date and exact count; the visible bar is a
     * child span sized via a CSS custom property, so a zero-view day renders
     * as truly zero. The current (incomplete) day is patterned, a Y-axis
     * scale and spaced X-axis date labels frame the plot, and a native
     * <details> data table provides every value without JavaScript.
     *
     * @param array<int, array{date: string, count: int}> $daily Zero-filled daily series.
     * @param int                                         $days  Period length, for layout density.
     * @return void
     */
    private static function renderPageviewChart(array $daily, int $days): void
    {
        $counts = array_column($daily, 'count');
        $count  = count($daily);
        $total  = array_sum($counts);
        $max    = $counts === [] ? 0 : max($counts);
        $scale  = self::niceScaleMax($max);
        $today  = gmdate('Y-m-d');

        $busiestDate  = '';
        $busiestCount = 0;
        foreach ($daily as $point) {
            if ($point['count'] > $busiestCount) {
                $busiestCount = $point['count'];
                $busiestDate  = $point['date'];
            }
        }

        // The average uses completed days only: today is still collecting,
        // so including it would drag the number down all day long.
        $completedTotal = 0;
        $completedCount = 0;
        foreach ($daily as $point) {
            if ($point['date'] < $today) {
                $completedTotal += $point['count'];
                $completedCount++;
            }
        }

        if ($completedCount > 0) {
            $avg      = $completedTotal / $completedCount;
            $avgLabel = $avg >= 10
                ? number_format_i18n(round($avg))
                : number_format_i18n(round($avg, 1), 1);
        } else {
            $avgLabel = '— (no completed days yet)';
        }

        // X-axis label density: every day at 7, every 5th at 30, every 15th at 90.
        $step = match (true) {
            $days <= 7  => 1,
            $days <= 30 => 5,
            default     => 15,
        };

        echo '<div class="spa-chart-frame">';
        echo '<h3>Daily Page Views</h3>';

        echo '<p class="spa-chart-summary">';
        echo '<span>Total: <strong>' . esc_html(number_format_i18n($total)) . '</strong></span>';
        echo '<span>Avg per completed day: <strong>' . esc_html($avgLabel) . '</strong></span>';
        if ($busiestDate !== '') {
            echo '<span>Busiest day: <strong>'
                . esc_html(self::utcDate('M j', (int) strtotime($busiestDate . ' UTC')))
                . ' (' . esc_html(number_format_i18n($busiestCount)) . ')</strong></span>';
        }
        echo '<span class="spa-chart-key"><span class="spa-chart-key-swatch" aria-hidden="true"></span>Today (still collecting)</span>';
        echo '</p>';

        echo '<div class="spa-chart-scroll">';
        echo '<div class="spa-chart-layout spa-chart-layout--' . (int) $days . '">';

        echo '<div class="spa-chart-yaxis" aria-hidden="true">';
        echo '<span>' . esc_html(number_format_i18n($scale)) . '</span>';
        echo '<span>' . esc_html(number_format_i18n((int) ($scale / 2))) . '</span>';
        echo '<span>0</span>';
        echo '</div>';

        echo '<div class="spa-chart-main">';
        echo '<div class="spa-chart-plot">';
        echo '<div class="spa-chart-cols" role="group" aria-label="Daily page views: one button per day, oldest first">';

        foreach ($daily as $point) {
            $dateLabel = self::utcDate('M j, Y', (int) strtotime($point['date'] . ' UTC'));
            $isToday   = $point['date'] === $today;
            $height    = round($point['count'] / $scale * 100, 2);
            $aria      = sprintf(
                '%s: %s page view%s%s',
                $dateLabel,
                number_format_i18n($point['count']),
                $point['count'] === 1 ? '' : 's',
                $isToday ? ' (today, still collecting)' : ''
            );

            echo '<button type="button" class="spa-chart-col' . ($isToday ? ' is-today' : '') . '"'
                . ' data-date="' . esc_attr($dateLabel) . '"'
                . ' data-count="' . esc_attr(number_format_i18n($point['count'])) . '"'
                . ' aria-label="' . esc_attr($aria) . '">'
                . '<span class="spa-chart-bar" style="--spa-h:' . esc_attr((string) $height) . '%"></span>'
                . '</button>';
        }

        echo '</div>'; // .spa-chart-cols
        echo '</div>'; // .spa-chart-plot

        echo '<div class="spa-chart-xaxis" aria-hidden="true">';
        foreach ($daily as $i => $point) {
            $isLast = $i === $count - 1;
            // Step labels stop short of the final label so the two never collide.
            $onStep = $i % $step === 0 && ($count - 1 - $i) >= $step / 2;
            if (!$isLast && !$onStep) {
                continue;
            }
            $x = round((($i + 0.5) / max(1, $count)) * 100, 2);
            echo '<span class="spa-chart-xlabel" style="--spa-x:' . esc_attr((string) $x) . '%">'
                . esc_html(self::utcDate('M j', (int) strtotime($point['date'] . ' UTC'))) . '</span>';
        }
        echo '</div>'; // .spa-chart-xaxis

        echo '</div></div></div>'; // .spa-chart-main, .spa-chart-layout, .spa-chart-scroll

        echo '<details class="spa-chart-data">';
        echo '<summary>View data table</summary>';
        echo '<div class="spa-table-scroll">';
        echo '<table class="wp-list-table widefat striped spa-chart-data-table">';
        echo '<caption class="screen-reader-text">Daily page views for the selected period</caption>';
        echo '<thead><tr><th scope="col">Date</th><th scope="col" class="spa-num">Page Views</th></tr></thead><tbody>';

        foreach ($daily as $point) {
            $isToday = $point['date'] === $today;
            echo '<tr><td>' . esc_html(self::utcDate('M j, Y', (int) strtotime($point['date'] . ' UTC')))
                . ($isToday ? ' <em>(today, partial)</em>' : '') . '</td>'
                . '<td class="spa-num">' . esc_html(number_format_i18n($point['count'])) . '</td></tr>';
        }

        echo '</tbody></table></div></details>';
        echo '</div>'; // .spa-chart-frame
    }

    /**
     * Rounds a series maximum up to a "nice" chart ceiling whose half is also
     * a round number, so the Y-axis ticks (max, half, 0) read cleanly.
     *
     * @param int $max Largest value in the series.
     * @return int Always >= 2 and >= $max.
     */
    private static function niceScaleMax(int $max): int
    {
        if ($max <= 2) {
            return 2;
        }

        for ($pow = 1; $pow <= 1000000000; $pow *= 10) {
            foreach ([2, 4, 10] as $base) {
                if ($base * $pow >= $max) {
                    return $base * $pow;
                }
            }
        }

        return $max;
    }

    /**
     * Opens a collapsible dashboard section (a native <details> panel, so it
     * is keyboard accessible without JavaScript). Must be paired with
     * {@see panelEnd()}.
     *
     * @param string $id    Slug used for the element id.
     * @param string $title Section heading.
     * @param string $desc  Optional one-line description under the heading.
     * @param bool   $open  Whether the panel starts expanded.
     * @return void
     */
    private static function panelStart(string $id, string $title, string $desc = '', bool $open = false): void
    {
        echo '<details class="spa-panel" id="spa-panel-' . esc_attr($id) . '"' . ($open ? ' open' : '') . '>';
        echo '<summary class="spa-panel-summary">';
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<span class="spa-panel-arrow" aria-hidden="true">&#9660;</span>';
        echo '</summary>';
        echo '<div class="spa-panel-body">';
        if ($desc !== '') {
            echo '<p class="spa-panel-desc">' . esc_html($desc) . '</p>';
        }
    }

    /**
     * Closes a panel opened with {@see panelStart()}.
     *
     * @return void
     */
    private static function panelEnd(): void
    {
        echo '</div></details>';
    }

    /**
     * Renders one report table: heading, optional description, and the table
     * inside a horizontal-scroll container so wide data can never break the
     * page layout.
     *
     * @param string                                        $title   Table heading (h3).
     * @param string                                        $desc    Optional description under the heading.
     * @param array<int, array{label: string, num?: bool}>  $columns Column definitions; num columns are right-aligned.
     * @param array<int, array<int, string>>                $rows    Rows of pre-escaped cell HTML (from the cell*() helpers).
     * @param string                                        $empty   Empty-state message.
     * @param bool                                          $wide    Span the full grid width (for many-column tables).
     * @return void
     */
    private static function renderReportTable(string $title, string $desc, array $columns, array $rows, string $empty, bool $wide = false): void
    {
        echo '<div class="spa-report' . ($wide ? ' spa-report--wide' : '') . '">';
        echo '<h3>' . esc_html($title) . '</h3>';
        if ($desc !== '') {
            echo '<p class="spa-report-desc">' . esc_html($desc) . '</p>';
        }

        echo '<div class="spa-table-scroll">';
        echo '<table class="wp-list-table widefat striped">';
        // Named tables let screen-reader users tell "Top Pages" from "Top
        // Referrers" when jumping directly between tables.
        echo '<caption class="screen-reader-text">' . esc_html($title) . '</caption>';
        echo '<thead><tr>';
        foreach ($columns as $col) {
            echo '<th scope="col"' . (!empty($col['num']) ? ' class="spa-num"' : '') . '>'
                . esc_html($col['label']) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="' . count($columns) . '">' . esc_html($empty) . '</td></tr>';
        }

        foreach ($rows as $cells) {
            echo '<tr>';
            foreach (array_values($cells) as $i => $cell) {
                echo '<td' . (!empty($columns[$i]['num']) ? ' class="spa-num"' : '') . '>' . $cell . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
    }

    /**
     * Escapes a plain-text cell, rendering an em dash for empty values.
     *
     * @param string $value Raw cell text.
     * @return string Safe HTML.
     */
    private static function cellText(string $value): string
    {
        return $value !== '' ? esc_html($value) : '&mdash;';
    }

    /**
     * Formats an integer cell with locale thousands separators.
     *
     * @param int $value Raw count.
     * @return string Safe HTML.
     */
    private static function cellNum(int $value): string
    {
        return esc_html(number_format_i18n($value));
    }

    /**
     * Renders a URL cell: a link (new tab) when the value is a usable web
     * URL, plain text otherwise (mailto:/tel: destinations stay readable but
     * are never made actionable). Every link carries the same visible
     * new-tab indicator and exactly one concise screen-reader announcement —
     * "(external link, opens in a new tab)" off-site, "(opens in a new tab)"
     * on-site. The link text is a readable host/path while the full URL
     * stays accessible as the href.
     *
     * @param string $url   Raw URL value.
     * @param string $label Optional display label; defaults to a readable host/path.
     * @return string Safe HTML.
     */
    private static function cellLink(string $url, string $label = ''): string
    {
        if ($url === '') {
            return '&mdash;';
        }

        if (!self::isLinkableUrl($url)) {
            return esc_html($label !== '' ? $label : $url);
        }

        $text = $label !== '' ? $label : self::urlDisplayText($url);
        $sr   = self::isExternalUrl($url)
            ? ' (external link, opens in a new tab)'
            : ' (opens in a new tab)';

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($text)
            . '<span class="spa-newtab" aria-hidden="true">&#8599;</span>'
            . '<span class="screen-reader-text">' . esc_html($sr) . '</span></a>';
    }

    /**
     * Renders a page cell: the title as the link text with the readable URL
     * beneath it, or just the readable URL when no title was captured.
     *
     * @param string $url   Page URL.
     * @param string $title Page title (possibly empty).
     * @return string Safe HTML.
     */
    private static function cellPage(string $url, string $title): string
    {
        $html = self::cellLink($url, $title);

        if ($title !== '' && self::isLinkableUrl($url)) {
            $html .= '<span class="spa-url-sub">' . esc_html(self::urlDisplayText($url)) . '</span>';
        }

        return $html;
    }

    /**
     * Whether a value is a usable http(s) URL and therefore safe to link.
     *
     * This is display validation, not request validation — deliberately NOT
     * wp_http_validate_url(), which is built for server-side HTTP requests
     * and rejects private-network hosts, so it would strip legitimate links
     * on local, staging, and intranet installs. Here: an explicit http(s)
     * scheme, a parseable host with plausible characters, and no embedded
     * credentials; this site's own hosts are always accepted, and esc_url()
     * remains the final output sanitizer.
     *
     * @param string $url Raw value.
     * @return bool
     */
    private static function isLinkableUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        if (in_array($host, array_map('strtolower', Options::allowedHosts()), true)) {
            return true;
        }

        // Hostname, IPv4, or bracketed IPv6 characters only.
        return (bool) preg_match('/^[a-z0-9.\-\[\]:]+$/', $host);
    }

    /**
     * Whether a URL points off this site (its host is not an allowed host).
     *
     * @param string $url Web URL.
     * @return bool
     */
    private static function isExternalUrl(string $url): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return !in_array($host, array_map('strtolower', Options::allowedHosts()), true);
    }

    /**
     * A compact, readable form of a URL for display: host plus path, no
     * scheme, no trailing slash-only path. The full URL remains available
     * through the link href.
     *
     * @param string $url Web URL.
     * @return string
     */
    private static function urlDisplayText(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');

        return $parts['host'] . ($path === '/' ? '' : $path);
    }

    /**
     * Formats a Unix timestamp for display as a localized date pinned to
     * UTC — report boundaries are UTC calendar days, so displayed dates must
     * not shift with the site timezone.
     *
     * @param string $format PHP date format.
     * @param int    $timestamp Unix timestamp.
     * @return string
     */
    private static function utcDate(string $format, int $timestamp): string
    {
        return (string) wp_date($format, $timestamp, new \DateTimeZone('UTC'));
    }

    /**
     * A display label for the site timezone. wp_timezone_string() returns a
     * bare offset like "+00:00" when no named zone is configured; prefix it
     * with "UTC" so the label reads as a timezone rather than a stray number.
     *
     * @return string
     */
    private static function siteTimezoneLabel(): string
    {
        $tz = wp_timezone_string();

        return ($tz !== '' && ($tz[0] === '+' || $tz[0] === '-')) ? 'UTC' . $tz : $tz;
    }

    /**
     * Human-readable label for a raw event type key.
     *
     * @param string $type Raw event_type value.
     * @return string
     */
    private static function eventLabel(string $type): string
    {
        return match ($type) {
            'pageview'     => 'Page View',
            'click'        => 'Click',
            'form_submit'  => 'Form Submit Attempt',
            'form_success' => 'Confirmed Conversion',
            'hover'        => 'Hover',
            'scroll_depth' => 'Scroll Milestone',
            default        => ucwords(str_replace(['_', '-'], ' ', $type)),
        };
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

        self::renderReportTable(
            'Top Pages',
            'The most-viewed pages (up to 10 shown). Sessions group one visit within a 30-minute inactivity window.',
            [
                ['label' => 'Page'],
                ['label' => 'Views', 'num' => true],
                ['label' => 'Sessions', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellPage($row['page_url'], $row['page_title']),
                self::cellNum($row['views']),
                self::cellNum($row['sessions']),
            ], $rows),
            'No page views recorded in this period.'
        );
    }

    /**
     * Renders the "Top Landing Pages" table — the first page of each session
     * that started in the period.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderLandingPages(string $start, string $end): void
    {
        $rows = Reports::topLandingPages($start, $end);

        self::renderReportTable(
            'Top Landing Pages',
            'The first page of each session that started in this period — where visitors actually arrive. Up to 10 shown.',
            [
                ['label' => 'Landing Page'],
                ['label' => 'Sessions', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellPage($row['page_url'], $row['page_title']),
                self::cellNum($row['sessions']),
            ], $rows),
            'No sessions recorded in this period.'
        );
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

        self::renderReportTable(
            'Top Clicked Elements',
            'The links and buttons visitors click most (up to 10 shown).',
            [
                ['label' => 'Element'],
                ['label' => 'Destination'],
                ['label' => 'Clicks', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellText($row['element_label'] !== '' ? $row['element_label'] : '(unlabeled ' . $row['element_tag'] . ')'),
                self::cellLink($row['target_url']),
                self::cellNum($row['clicks']),
            ], $rows),
            'No clicks recorded in this period.'
        );
    }

    /**
     * Renders the "Top Form Submit Attempts" table.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderTopForms(string $start, string $end): void
    {
        $rows = Reports::topForms($start, $end);

        self::renderReportTable(
            'Top Form Submit Attempts',
            'Counted when a visitor submits the form — success is not confirmed (see Confirmed Conversions). Up to 10 shown.',
            [
                ['label' => 'Form'],
                ['label' => 'Page'],
                ['label' => 'Attempts', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellText($row['element_label'] !== '' ? $row['element_label'] : '(unnamed form)'),
                self::cellLink($row['page_url']),
                self::cellNum($row['submissions']),
            ], $rows),
            'No form submissions recorded in this period.'
        );
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

        self::renderReportTable(
            'Most Hovered Elements',
            'Elements the pointer rested on — where visitor attention lingers before (or without) a click. Up to 10 shown.',
            [
                ['label' => 'Element'],
                ['label' => 'Type'],
                ['label' => 'Hovers', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellText($row['element_label'] !== '' ? $row['element_label'] : '(unlabeled element)'),
                self::cellText($row['element_tag']),
                self::cellNum($row['hovers']),
            ], $rows),
            'No hover activity recorded in this period.'
        );
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

        self::renderReportTable(
            'Top Referrers',
            'The external pages that sent this site the most visitors (up to 10 shown).',
            [
                ['label' => 'Referring Page'],
                ['label' => 'Visits', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellLink($row['referrer']),
                self::cellNum($row['visits']),
            ], $rows),
            'No external referrers recorded in this period.'
        );
    }

    /**
     * Renders the "Campaigns" table: sessions, views, and confirmed
     * conversions attributed to each utm source/medium/campaign/id
     * combination.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderCampaigns(string $start, string $end): void
    {
        $rows = Reports::topCampaigns($start, $end);

        self::renderReportTable(
            'Campaigns',
            'Session-attributed performance of utm-tagged visits (up to 10 shown, ranked by views). Conv. rate is the share of sessions with at least one conversion.',
            [
                ['label' => 'Source'],
                ['label' => 'Medium'],
                ['label' => 'Campaign'],
                ['label' => 'ID'],
                ['label' => 'Sessions', 'num' => true],
                ['label' => 'Views', 'num' => true],
                ['label' => 'Conversions', 'num' => true],
                ['label' => 'Conv. Rate', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellText($row['utm_source']),
                self::cellText($row['utm_medium']),
                self::cellText($row['utm_campaign']),
                self::cellText($row['utm_id']),
                self::cellNum($row['sessions']),
                self::cellNum($row['views']),
                self::cellNum($row['conversions']),
                self::cellText($row['sessions'] > 0 ? $row['conversion_rate'] . '%' : ''),
            ], $rows),
            'No campaign-tagged (utm) visits recorded in this period.',
            true
        );
    }

    /**
     * Renders the "Campaign Terms & Content" drilldown: performance per
     * utm_term (keyword) and utm_content (creative), with campaign context.
     * Always rendered — an explanatory empty state replaces the previous
     * silent omission, so the report's existence is discoverable.
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderCampaignContent(string $start, string $end): void
    {
        $rows = Reports::topCampaignContent($start, $end);

        self::renderReportTable(
            'Campaign Terms & Content',
            'Keyword (utm_term) and creative (utm_content) performance, with campaign context. Up to 10 shown.',
            [
                ['label' => 'Source'],
                ['label' => 'Campaign'],
                ['label' => 'Term'],
                ['label' => 'Content'],
                ['label' => 'Sessions', 'num' => true],
                ['label' => 'Views', 'num' => true],
                ['label' => 'Conversions', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellText($row['utm_source']),
                self::cellText($row['utm_campaign']),
                self::cellText($row['utm_term']),
                self::cellText($row['utm_content']),
                self::cellNum($row['sessions']),
                self::cellNum($row['views']),
                self::cellNum($row['conversions']),
            ], $rows),
            'No visits carrying utm_term or utm_content tags were recorded in this period. Campaigns that never tag keywords or creatives simply don\'t appear here.',
            true
        );
    }

    /**
     * Renders the "Channels" table: sessions and confirmed conversions per
     * marketing channel (Paid Search, Organic Social, Email, Referral, …).
     *
     * @param string $start UTC datetime range start.
     * @param string $end   UTC datetime range end.
     * @return void
     */
    private static function renderChannels(string $start, string $end): void
    {
        $rows = Reports::channelBreakdown($start, $end);

        self::renderReportTable(
            'Channels',
            'Sessions and conversions per marketing channel, classified as events arrive. Conv. rate is the share of sessions with at least one conversion.',
            [
                ['label' => 'Channel'],
                ['label' => 'Sessions', 'num' => true],
                ['label' => 'Views', 'num' => true],
                ['label' => 'Conversions', 'num' => true],
                ['label' => 'Conv. Rate', 'num' => true],
            ],
            array_map(static fn(array $row): array => [
                self::cellText($row['channel']),
                self::cellNum($row['sessions']),
                self::cellNum($row['views']),
                self::cellNum($row['conversions']),
                self::cellText($row['sessions'] > 0 ? $row['conversion_rate'] . '%' : ''),
            ], $rows),
            'No channel data recorded in this period. Channels are classified as new events arrive, so this fills in from the moment of the update onward.'
        );
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

        $rows = [];
        foreach ($devices as $device => $views) {
            $rows[] = [
                self::cellText(ucfirst($device)),
                self::cellNum($views),
                self::cellText($total > 0 ? round($views / $total * 100) . '%' : ''),
            ];
        }

        self::renderReportTable(
            'Devices',
            '',
            [
                ['label' => 'Device'],
                ['label' => 'Page Views', 'num' => true],
                ['label' => 'Share', 'num' => true],
            ],
            $rows,
            'No page views recorded in this period.'
        );
    }

    /**
     * Renders the "Recent Activity" table of the latest raw events.
     *
     * Display formatting only: times are converted to the site timezone and
     * event types get readable labels, but the underlying query and the
     * information shown per event are unchanged.
     *
     * @return void
     */
    private static function renderRecentEvents(): void
    {
        $rows = Reports::recentEvents(15);

        // The site's own date/time display settings, as everywhere in wp-admin.
        $format = trim(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a'));

        $cells = [];
        foreach ($rows as $row) {
            $detail = $row['element_label'] !== '' ? $row['element_label'] : $row['target_url'];
            if (($row['event_value'] ?? '') !== '') {
                $detail = trim($detail . ' (' . $row['event_value'] . ')');
            }

            $cells[] = [
                self::cellText(get_date_from_gmt((string) $row['created_at'], $format)),
                self::cellText(self::eventLabel((string) $row['event_type'])),
                self::cellPage((string) $row['page_url'], (string) $row['page_title']),
                self::cellText($detail),
                self::cellText(ucfirst((string) $row['device'])),
            ];
        }

        self::renderReportTable(
            'Latest Events',
            sprintf(
                'The latest 15 events, independent of the selected reporting period. Times are shown in the site timezone (%s).',
                self::siteTimezoneLabel()
            ),
            [
                ['label' => 'When'],
                ['label' => 'Event'],
                ['label' => 'Page'],
                ['label' => 'Detail'],
                ['label' => 'Device'],
            ],
            $cells,
            'No events recorded yet. Visit the site\'s frontend to start collecting data.'
        );
    }
}
