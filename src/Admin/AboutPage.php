<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Admin;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;

/**
 * The "SitePulse → About" submenu page.
 *
 * A static, read-only reference page that explains what the plugin does and
 * how it works — what gets tracked, how data is collected and stored, the
 * privacy posture, webhook delivery, and the developer API. Mirrors the
 * README so the documentation is available right inside wp-admin without
 * leaving the site.
 */
final class AboutPage
{
    /** @var string Menu slug for the about submenu page. */
    public const MENU_SLUG = 'sitepulse-analytics-about';

    /**
     * Registers the admin menu hook.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
    }

    /**
     * Adds the About submenu under the SitePulse top-level menu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            DashboardPage::MENU_SLUG,
            'About SitePulse Analytics',
            'About',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Renders the about page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap spa-wrap spa-about">';
        echo '<h1>About SitePulse Analytics</h1>';

        self::renderIntro();
        self::renderFeatureCards();
        self::renderDashboardSection();
        self::renderHowItWorksSection();
        self::renderPrivacySection();
        self::renderWebhooksSection();
        self::renderDeveloperSection();
        self::renderUpdatesSection();

        echo '</div>';
    }

    /**
     * Renders the intro paragraph and the version/links meta line.
     *
     * @return void
     */
    private static function renderIntro(): void
    {
        echo '<p class="spa-about-lead">SitePulse Analytics is a self-hosted visitor analytics plugin. '
            . 'It tracks page views, link and button clicks, form submissions, mouse hover activity, and scroll depth, '
            . 'then surfaces everything right here in the WordPress dashboard — making it easy to identify popular pages, '
            . 'important conversion actions, and the areas of your content visitors actually engage with. '
            . 'On a configurable schedule, aggregated analytics can also be delivered as JSON to one or more webhook endpoints.</p>';

        $version = defined('SPA_VERSION') ? SPA_VERSION : '';

        echo '<p class="spa-about-meta">';
        echo 'Version <strong>' . esc_html($version) . '</strong>';
        echo ' &nbsp;•&nbsp; <a href="https://github.com/Magellan-Web-Dev/SitePulse-Analytics" target="_blank" rel="noopener">GitHub repository</a>';
        echo ' &nbsp;•&nbsp; <a href="' . esc_url(self_admin_url('admin.php?page=' . DashboardPage::MENU_SLUG)) . '">Dashboard</a>';
        echo ' &nbsp;•&nbsp; <a href="' . esc_url(self_admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)) . '">Settings</a>';
        echo '</p>';
    }

    /**
     * Renders the "What gets tracked" feature card grid.
     *
     * @return void
     */
    private static function renderFeatureCards(): void
    {
        $features = [
            [
                'title' => 'Page Views',
                'text'  => 'Every frontend page load is recorded with its URL, title, and referrer, so you can see which pages draw traffic and where it comes from.',
            ],
            [
                'title' => 'Link & Button Clicks',
                'text'  => 'Links, buttons, submit inputs, and role="button" elements are tracked with their label and destination URL — your conversion actions, measured.',
            ],
            [
                'title' => 'Form Submissions',
                'text'  => 'Native submit events are captured before any AJAX handler can swallow them, so Elementor and similar AJAX-powered forms are counted too. Counted at submit time — attempts, not confirmed successes.',
            ],
            [
                'title' => 'Hover Activity',
                'text'  => 'Records when a visitor\'s pointer rests on an interactive element or image for a configurable dwell time, once per element per page view. Add data-spa-hover to any element to opt it in.',
            ],
            [
                'title' => 'Scroll Depth',
                'text'  => 'Reaching 25%, 50%, 75%, and 100% of the page fires a milestone once each per page view, showing how far visitors actually read.',
            ],
            [
                'title' => 'Custom Events',
                'text'  => 'Anything else — purchases, API hits, custom conversions — can be recorded from server-side code with the spa_track_event() helper.',
            ],
        ];

        echo '<h2>What Gets Tracked</h2>';
        echo '<p>Each interaction type can be toggled individually on the <a href="'
            . esc_url(self_admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)) . '">Settings</a> page.</p>';

        echo '<div class="spa-cards spa-about-features">';
        foreach ($features as $feature) {
            echo '<div class="spa-card">';
            echo '<h3>' . esc_html($feature['title']) . '</h3>';
            echo '<p>' . esc_html($feature['text']) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Renders the dashboard overview table.
     *
     * @return void
     */
    private static function renderDashboardSection(): void
    {
        $sections = [
            'Summary cards'         => 'Totals for page views, clicks, form submissions, hovers, and scroll milestones over the selected period.',
            'Daily Page Views'      => 'A bar chart of traffic across the period, for spotting trends and spikes.',
            'Top Pages'             => 'Most-viewed pages with view and unique-session counts.',
            'Top Clicked Elements'     => 'Which links and buttons visitors click most — your conversion actions.',
            'Top Form Submit Attempts' => 'Which forms are submitted, and on which pages (counted at submit time; success is not confirmed).',
            'Most Hovered Elements'    => 'Where visitor attention lingers before (or without) a click.',
            'Top Referrers'            => 'Which external sites and pages send you traffic.',
            'Campaigns'                => 'Pageviews attributed to utm_source / utm_medium / utm_campaign parameters.',
            'Devices'                  => 'Mobile versus desktop share of page views.',
            'Recent Activity'          => 'The latest raw events, useful for verifying tracking is working.',
        ];

        echo '<h2>The Dashboard</h2>';
        echo '<p>The <a href="' . esc_url(self_admin_url('admin.php?page=' . DashboardPage::MENU_SLUG)) . '">SitePulse dashboard</a> '
            . 'shows the following for a selectable 7, 30, or 90-day period:</p>';

        echo '<table class="wp-list-table widefat striped spa-about-table">';
        echo '<thead><tr><th>Section</th><th>What it tells you</th></tr></thead><tbody>';
        foreach ($sections as $name => $description) {
            echo '<tr><td><strong>' . esc_html($name) . '</strong></td><td>' . esc_html($description) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Renders the "How tracking works" section.
     *
     * @return void
     */
    private static function renderHowItWorksSection(): void
    {
        echo '<h2>How Tracking Works</h2>';
        echo '<p>A single dependency-free script is loaded deferred in the footer of frontend pages. '
            . 'It batches events in memory and delivers them to a REST endpoint on this site '
            . '(<code>' . esc_html(rest_url('sitepulse/v1/track')) . '</code>). '
            . 'Batches flush every few seconds and on page exit via <code>navigator.sendBeacon</code>, '
            . 'so events are not lost when a visitor navigates away.</p>';

        echo '<p>The endpoint accepts only whitelisted, currently-enabled event types; requires tracked page URLs to belong '
            . 'to this site; rejects requests whose Origin or Referer names a foreign host; ignores known bots; caps request '
            . 'and batch sizes; sanitizes and truncates every field; and rate-limits by event count, both per IP and '
            . 'site-wide. Events are stored in a dedicated database table, and a daily cleanup job deletes anything older '
            . 'than the retention window configured in Settings '
            . '(currently <strong>' . esc_html((string) Options::retentionDays()) . ' days</strong>).</p>';
    }

    /**
     * Renders the privacy posture list.
     *
     * @return void
     */
    private static function renderPrivacySection(): void
    {
        echo '<h2>Privacy</h2>';
        echo '<ul class="spa-about-list">';
        echo '<li>No cookies are set. The session identifier lives in <code>localStorage</code> and rotates after '
            . '30 minutes of inactivity, so it groups one visit without becoming a persistent visitor ID.</li>';
        echo '<li>Tracked URLs are canonicalized to their path — no query strings are ever stored. Campaign parameters '
            . '(<code>utm_source</code>/<code>utm_medium</code>/<code>utm_campaign</code>) are captured as separate fields, '
            . 'and referrers and click/form destinations are stored without query strings or fragments, so search terms, '
            . 'tokens, and emails in URLs never reach the database.</li>';
        echo '<li>Optionally, visitors sending Do Not Track / Global Privacy Control signals can be excluded entirely '
            . '(off by default; toggle in Settings).</li>';
        echo '<li>No IP addresses or user agents are stored — only a coarse mobile/desktop device bucket. '
            . '(IPs are used transiently as hashed rate-limit keys and never written to the analytics table.)</li>';
        echo '<li>Logged-in users are excluded from tracking by default (toggleable in Settings).</li>';
        echo '<li>Data is automatically deleted after the retention window, and removed entirely if the plugin is uninstalled.</li>';
        echo '</ul>';
    }

    /**
     * Renders the webhooks explanation.
     *
     * @return void
     */
    private static function renderWebhooksSection(): void
    {
        $count = count(Options::webhookUrls());

        echo '<h2>Webhook Delivery</h2>';
        echo '<p>On an hourly, twice-daily, daily, or weekly schedule, aggregated analytics are sent as a JSON '
            . '<code>POST</code> to every endpoint configured in Settings — add a field for each endpoint, as many as you need. '
            . 'Delivery windows are tracked per endpoint: each payload covers the time since that endpoint\'s last successful '
            . 'delivery, so a temporarily failing endpoint receives the full missed window on the next run instead of losing data. '
            . 'Failed webhook deliveries are retried automatically up to 5 more times over about 24 hours '
            . '(after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours). '
            . 'Each site also delivers at a random, stable time within its interval, so many sites sharing '
            . 'one endpoint don\'t all send at the same moment.</p>';

        echo '<p>The payload contains the same aggregates the dashboard shows — totals per interaction type, daily page views, '
            . 'top pages, top clicks, top forms, top hovers, top referrers, and a device breakdown — alongside the site URL, '
            . 'plugin version, and reporting period. Delivery is at-least-once: every payload carries a '
            . '<code>delivery_id</code> (also sent as an <code>Idempotency-Key</code> header) that stays stable across '
            . 'retries of the same window, so receivers can deduplicate. A "Send test payload now" button and a delivery log '
            . 'on the Settings page make it easy to verify endpoints are receiving data.</p>';

        echo '<p><em>' . esc_html($count === 0
            ? 'No webhook endpoints are configured yet.'
            : sprintf('%d webhook endpoint%s currently configured.', $count, $count === 1 ? ' is' : 's are'))
            . '</em> <a href="' . esc_url(self_admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)) . '">Manage webhooks →</a></p>';
    }

    /**
     * Renders the developer API reference (helper function and filters).
     *
     * @return void
     */
    private static function renderDeveloperSection(): void
    {
        echo '<h2>Developer API</h2>';

        echo '<p>Record custom server-side events from any theme or plugin code:</p>';
        echo '<pre class="spa-about-code"><code>'
            . esc_html("spa_track_event('purchase', [\n    'page_url'      => home_url('/checkout/'),\n    'element_label' => 'Order #1234',\n    'event_value'   => '99.00',\n]);")
            . '</code></pre>';
        echo '<p>Custom event types appear in the dashboard\'s "Other Events" card, in totals, and in webhook payloads under their own type key.</p>';

        echo '<table class="wp-list-table widefat striped spa-about-table">';
        echo '<thead><tr><th>Filter</th><th>Purpose</th></tr></thead><tbody>';
        echo '<tr><td><code>spa_tracked_event</code></td>'
            . '<td>Inspect or modify an event row before it is stored; return <code>false</code> to drop it. '
            . 'Receives <code>(array $row, string $type)</code>.</td></tr>';
        echo '<tr><td><code>spa_webhook_payload</code></td>'
            . '<td>Modify the webhook payload before it is sent. '
            . 'Receives <code>(array $payload, int $startTs, int $endTs)</code>.</td></tr>';
        echo '</tbody></table>';
    }

    /**
     * Renders the updates explanation.
     *
     * @return void
     */
    private static function renderUpdatesSection(): void
    {
        echo '<h2>Updates</h2>';
        echo '<p>The plugin checks its <a href="https://github.com/Magellan-Web-Dev/SitePulse-Analytics" target="_blank" rel="noopener">GitHub repository</a> '
            . 'for new releases every 12 hours through WordPress\'s normal update pipeline. When a newer release is published, '
            . 'the standard update banner appears on the Plugins screen; a "Check for updates" row action there forces an immediate check.</p>';
    }
}
