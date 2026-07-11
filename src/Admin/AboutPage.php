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
 * privacy posture, webhook delivery (with sample JSON payloads), the
 * Delivery Log / Deliveries API, and the developer API. Mirrors the README
 * so the documentation is available right inside wp-admin without leaving
 * the site.
 *
 * Layout is one card per section — ported from the Forms Webhook Integrator
 * plugin's About page, which uses the same card/table/code-block styling
 * throughout this plugin's admin screens.
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
        self::renderWebhookPayloadSection();
        self::renderDeliveryLogSection();
        self::renderDeveloperSection();
        self::renderUpdatesSection();

        echo '</div>';
    }

    /**
     * Renders the intro card: the plugin blurb, requirement badges, and the
     * version/links meta line.
     *
     * @return void
     */
    private static function renderIntro(): void
    {
        $version = defined('SPA_VERSION') ? SPA_VERSION : '';

        echo '<div class="spa-about-intro spa-card">';
        echo '<p>';
        echo '<strong>SitePulse Analytics</strong> is a self-hosted visitor analytics plugin. '
            . 'It tracks page views, link and button clicks, form submissions, confirmed form conversions with campaign '
            . 'attribution, mouse hover activity, and scroll depth, then surfaces everything right here in the WordPress '
            . 'dashboard — making it easy to identify popular pages, which campaigns and channels actually produce leads, '
            . 'and the areas of your content visitors engage with. '
            . 'On a configurable schedule, aggregated analytics (including individual attributed conversions) can also be '
            . 'delivered as JSON to one or more webhook endpoints, with every attempt recorded in a dedicated Delivery Log '
            . 'and, optionally, exposed through a read-only REST API.';
        echo '</p>';

        echo '<ul class="spa-about-requirements">';
        echo '<li><span class="spa-about-label">PHP</span> 8.1+</li>';
        echo '<li><span class="spa-about-label">WordPress</span> 6.3+</li>';
        echo '<li><span class="spa-about-label">Version</span> ' . esc_html($version) . '</li>';
        echo '</ul>';
        echo '</div>';

        echo '<p class="spa-about-meta">';
        echo '<a href="https://github.com/Magellan-Web-Dev/SitePulse-Analytics" target="_blank" rel="noopener">GitHub repository</a>';
        echo ' &nbsp;•&nbsp; <a href="' . esc_url(self_admin_url('admin.php?page=' . DashboardPage::MENU_SLUG)) . '">Dashboard</a>';
        echo ' &nbsp;•&nbsp; <a href="' . esc_url(self_admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)) . '">Settings</a>';
        echo ' &nbsp;•&nbsp; <a href="' . esc_url(self_admin_url('admin.php?page=' . DeliveryLogPage::MENU_SLUG)) . '">Delivery Log</a>';
        echo '</p>';
    }

    /**
     * Renders the "What gets tracked" feature card grid, inside its own card.
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
                'title' => 'Confirmed Conversions',
                'text'  => 'A separate form_success event fires only when the form plugin reports the server accepted the submission (Elementor Pro, Contact Form 7, WPForms, Gravity Forms — or a custom spa:conversion event). Each conversion gets a unique id and a snapshot of the session\'s campaign attribution.',
            ],
            [
                'title' => 'Campaigns & Channels',
                'text'  => 'All six utm parameters and ad-click identifiers (gclid, fbclid, …) are captured from tagged landings (only the click identifier\'s name is stored, never its value), and every visit is grouped into a marketing channel — Paid Search, Organic Social, Email, Referral, Direct, and more.',
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

        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">What Gets Tracked</h2>';
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
        echo '</div>';
    }

    /**
     * Renders the dashboard overview table, inside its own card.
     *
     * @return void
     */
    private static function renderDashboardSection(): void
    {
        $sections = [
            'Summary cards'         => 'Totals for page views, clicks, form submit attempts, confirmed conversions, hovers, and scroll milestones over the selected period.',
            'Daily Page Views'      => 'A bar chart of traffic across the period, for spotting trends and spikes.',
            'Top Pages'             => 'Most-viewed pages with view and unique-session counts.',
            'Top Clicked Elements'     => 'Which links and buttons visitors click most — your conversion actions.',
            'Top Form Submit Attempts' => 'Which forms are submitted, and on which pages (counted at submit time; success is not confirmed).',
            'Most Hovered Elements'    => 'Where visitor attention lingers before (or without) a click.',
            'Top Referrers'            => 'Which external sites and pages send you traffic.',
            'Campaigns'                => 'Sessions, views, confirmed conversions, and conversion rate per utm source/medium/campaign — last-touch within the session: the most recent tagged landing attributes the visit from that point on, and untagged pages inherit it.',
            'Channels'                 => 'Sessions, views, confirmed conversions, and conversion rate per marketing channel (Paid Search, Organic Social, Email, Referral, Direct, …), classified as events arrive.',
            'Devices'                  => 'Mobile versus desktop share of page views.',
            'Recent Activity'          => 'The latest raw events, useful for verifying tracking is working.',
        ];

        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">The Dashboard</h2>';
        echo '<p>The <a href="' . esc_url(self_admin_url('admin.php?page=' . DashboardPage::MENU_SLUG)) . '">SitePulse dashboard</a> '
            . 'shows the following for a selectable 7, 30, or 90-day period:</p>';

        echo '<table class="spa-about-table">';
        echo '<thead><tr><th>Section</th><th>What it tells you</th></tr></thead><tbody>';
        foreach ($sections as $name => $description) {
            echo '<tr><td><strong>' . esc_html($name) . '</strong></td><td>' . esc_html($description) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Renders the "How tracking works" card, including a sample of the raw
     * event batch the frontend tracker posts to the REST endpoint.
     *
     * @return void
     */
    private static function renderHowItWorksSection(): void
    {
        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">How Tracking Works</h2>';
        echo '<p>A single dependency-free script is loaded deferred in the footer of frontend pages. '
            . 'It batches events in memory and delivers them to a REST endpoint on this site:</p>';

        echo '<pre class="spa-about-code">POST ' . esc_html(rest_url('sitepulse/v1/track')) . '</pre>';

        echo '<p>Batches flush every few seconds and on page exit via <code>navigator.sendBeacon</code>, '
            . 'so events are not lost when a visitor navigates away. Batches that fail with a network error or '
            . 'a 5xx response are kept in a bounded <code>sessionStorage</code> map and resent by later flushes — '
            . 'even by the next page in that tab if navigation destroyed the one that failed. A batch body looks like this:</p>';

        echo '<pre class="spa-about-code">' . esc_html(
            (string) wp_json_encode([
                'events' => [
                    [
                        'type'          => 'pageview',
                        'page_url'      => home_url('/pricing/'),
                        'page_title'    => 'Pricing',
                        'referrer'      => 'https://www.google.com/',
                        'session_id'    => '3f2a9c1b8e0d4f5a6b7c8d9e0f1a2b3c',
                        'utm_source'    => 'google',
                        'utm_medium'    => 'cpc',
                        'utm_campaign'  => 'spring-sale',
                        'utm_term'      => 'pricing',
                        'click_id_type' => 'gclid',
                    ],
                    [
                        'type'          => 'click',
                        'page_url'      => home_url('/pricing/'),
                        'element_tag'   => 'a',
                        'element_label' => 'Get a Quote',
                        'target_url'    => home_url('/quote/'),
                        'session_id'    => '3f2a9c1b8e0d4f5a6b7c8d9e0f1a2b3c',
                    ],
                    [
                        'type'          => 'form_success',
                        'page_url'      => home_url('/quote/'),
                        'element_tag'   => 'form',
                        'element_label' => 'quote-form',
                        'event_value'   => 'c9d4e1f2a3b4c5d6e',
                        'session_id'    => '3f2a9c1b8e0d4f5a6b7c8d9e0f1a2b3c',
                        'utm_source'    => 'google',
                        'utm_medium'    => 'cpc',
                        'utm_campaign'  => 'spring-sale',
                        'utm_term'      => 'pricing',
                        'click_id_type' => 'gclid',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) . '</pre>';

        echo '<p>The endpoint accepts only whitelisted, currently-enabled event types; requires tracked page URLs to be '
            . 'http(s) URLs belonging to this site; rejects requests whose Origin or Referer names a foreign host; ignores '
            . 'known bots; caps request and batch sizes; sanitizes and truncates every field; and rate-limits by event '
            . 'count, both per IP and site-wide (tunable via the <code>spa_rate_limits</code> filter). If the site-wide '
            . 'limit is ever reached, a warning appears on the <a href="' . esc_url(self_admin_url('admin.php?page=' . DashboardPage::MENU_SLUG)) . '">dashboard</a> '
            . 'for the next 24 hours so dropped events don\'t go unnoticed. Events are stored in a dedicated database '
            . 'table, and a daily cleanup job deletes anything older than the retention window configured in Settings '
            . '(currently <strong>' . esc_html((string) Options::retentionDays()) . ' days</strong>).</p>';
        echo '</div>';
    }

    /**
     * Renders the privacy posture card.
     *
     * @return void
     */
    private static function renderPrivacySection(): void
    {
        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">Privacy</h2>';
        echo '<ul class="spa-about-list">';
        echo '<li>No cookies are set. The session identifier lives in <code>localStorage</code> and rotates after '
            . '30 minutes of inactivity, so it groups one visit without becoming a persistent visitor ID.</li>';
        echo '<li>Tracked URLs are canonicalized to their path — no query strings are ever stored, so search terms, '
            . 'tokens, and emails in page-URL or referrer query strings never reach the database. Campaign parameter '
            . 'values (all six <code>utm_*</code> fields) <em>are</em> stored '
            . '(values containing an <code>@</code> are dropped as likely emails) — never put personal information in '
            . 'UTM parameters. Ad-click identifiers (<code>gclid</code>, <code>fbclid</code>, …) are recognized, but only '
            . 'the parameter <em>name</em> is stored — the identifier value never leaves the browser. '
            . 'Clicked <code>mailto:</code>/<code>tel:</code> destinations are stored whole.</li>';
        echo '<li>Optionally, visitors sending Do Not Track / Global Privacy Control signals can be excluded entirely '
            . '(off by default; toggle in Settings) — enforced both client-side and, as a backstop, at the REST endpoint.</li>';
        echo '<li>No IP addresses or user agents are stored — only a coarse mobile/desktop device bucket. '
            . '(IPs are used transiently as hashed rate-limit keys and never written to the analytics table.)</li>';
        echo '<li>Logged-in users are excluded from tracking by default (toggleable in Settings).</li>';
        echo '<li>Data is automatically deleted after the retention window, and removed entirely if the plugin is uninstalled.</li>';
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Renders the "Webhook Payload" card: how scheduled delivery works, the
     * Webhook Status / Webhook Settings controls, and a full sample of the
     * JSON body every delivery sends.
     *
     * @return void
     */
    private static function renderWebhookPayloadSection(): void
    {
        $count = count(Options::webhookUrls());

        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">Webhook Payload</h2>';
        echo '<p>On an hourly, twice-daily, daily, or weekly schedule, aggregated analytics are sent as a JSON '
            . '<code>POST</code> to every endpoint configured under <strong>Settings → Webhook Settings</strong> — add a '
            . 'block for each endpoint, with an optional label so it is easy to identify in the Delivery Log, and use the '
            . '<strong>Webhook Status</strong> toggle to pause all scheduled sends without losing your configuration. '
            . 'Delivery windows are tracked per endpoint: each payload covers the time since that endpoint\'s last '
            . 'successful delivery, so a temporarily failing endpoint receives the full missed window on the next run '
            . 'instead of losing data.</p>';

        echo '<p>Failed deliveries are retried automatically up to 5 more times over about 24 hours '
            . '(after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours). The exact JSON body that failed is frozen '
            . 'and every retry — including retries resumed after an exhausted chain — re-sends the identical bytes under '
            . 'the same <code>delivery_id</code> (also sent as an <code>Idempotency-Key</code> header), so deduplicating '
            . 'by that id is sufficient for a receiver to never double-count. Deactivating the plugin suspends pending '
            . 'retries rather than discarding them: their frozen deliveries resume with the first scheduled dispatch '
            . 'after reactivation, still under their original ids.</p>';

        echo '<p>Every <code>POST</code> uses <code>Content-Type: application/json</code>. The body shape is:</p>';

        echo '<pre class="spa-about-code">' . esc_html(
            (string) wp_json_encode([
                'source'         => 'sitepulse-analytics',
                'plugin_version' => defined('SPA_VERSION') ? SPA_VERSION : '1.2.0',
                'website_info'   => [
                    'name'   => get_bloginfo('name'),
                    'url'    => home_url(),
                    'id'     => 'site-123',
                    'client' => ['first_name' => 'Jane', 'last_name' => 'Smith', 'id' => 'client-456'],
                ],
                'generated_at'   => '2026-07-10T12:00:00+00:00',
                'delivery_id'    => '0f52c1d6a4b98e73d21f06c58a9b3e47',
                'period'         => [
                    'start' => '2026-07-09T12:00:00+00:00',
                    'end'   => '2026-07-10T12:00:00+00:00',
                ],
                'analytics'      => [
                    'totals'          => ['pageview' => 1240, 'click' => 512, 'form_submit' => 38, 'form_success' => 24, 'hover' => 940, 'scroll_depth' => 2210],
                    'daily_pageviews' => [['date' => '2026-07-09', 'count' => 610], ['date' => '2026-07-10', 'count' => 630]],
                    'top_pages'       => [['page_url' => home_url('/'), 'page_title' => 'Home', 'views' => 400, 'sessions' => 310]],
                    'top_clicks'      => [['element_label' => 'Get a Quote', 'element_tag' => 'a', 'target_url' => home_url('/quote/'), 'clicks' => 88]],
                    'top_forms'       => [['element_label' => 'contact-form', 'page_url' => home_url('/contact/'), 'submissions' => 21]],
                    'top_hovers'      => [['element_label' => 'Pricing', 'element_tag' => 'a', 'hovers' => 130]],
                    'top_referrers'   => [['referrer' => 'https://www.google.com/', 'visits' => 210]],
                    'top_campaigns'   => [[
                        'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'spring-sale',
                        'utm_id' => 'cmp-2210', 'channel' => 'Email',
                        'views' => 96, 'sessions' => 74, 'conversions' => 7, 'conversion_rate' => 9.46,
                    ]],
                    'channels'        => [['channel' => 'Email', 'views' => 96, 'sessions' => 74, 'conversions' => 7, 'conversion_rate' => 9.46]],
                    'conversions'     => [
                        'total'  => 24,
                        'recent' => [[
                            'conversion_id' => 'c1f52c1d6a4b98e73',
                            'form'          => 'contact-form',
                            'page_url'      => home_url('/contact/'),
                            'referrer'      => home_url('/services/'),
                            'device'        => 'desktop',
                            'occurred_at'   => '2026-07-10 09:14:02',
                            'attribution'   => [
                                'channel'       => 'Paid Search',
                                'utm_source'    => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'summer-sale',
                                'utm_id'        => '', 'utm_term' => '', 'utm_content' => '',
                                'click_id_type' => 'gclid',
                            ],
                        ]],
                    ],
                    'devices'         => ['desktop' => 820, 'mobile' => 420],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) . '</pre>';

        echo '<p><code>website_info.id</code> and <code>website_info.client</code> (<code>first_name</code>, <code>last_name</code>, '
            . '<code>id</code>) are optional identifiers configured under <strong>Settings → Webhook Settings</strong> — '
            . 'each is always present in the payload as an empty string when not set, so consumers never have to check '
            . 'for its existence.</p>';

        echo '<p>Requests are sent with <code>wp_safe_remote_post()</code> (endpoints are re-validated at request time) and '
            . 'redirects disabled, and every request carries an <code>Idempotency-Key</code> header equal to the '
            . 'payload\'s <code>delivery_id</code>. A <strong>"Send test payload now"</strong> button on the Settings '
            . 'page delivers the last 7 days to every endpoint immediately (flagged with <code>"test": true</code>).</p>';

        echo '<p><em>' . esc_html($count === 0
            ? 'No webhook endpoints are configured yet.'
            : sprintf('%d webhook endpoint%s currently configured.', $count, $count === 1 ? ' is' : 's are'))
            . '</em> <a href="' . esc_url(self_admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)) . '">Manage webhooks →</a></p>';
        echo '</div>';
    }

    /**
     * Renders the "Delivery Log & Deliveries API" card, including a sample
     * of the JSON the read-only API returns.
     *
     * @return void
     */
    private static function renderDeliveryLogSection(): void
    {
        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">Delivery Log &amp; Deliveries API</h2>';

        echo '<p><strong><a href="' . esc_url(self_admin_url('admin.php?page=' . DeliveryLogPage::MENU_SLUG)) . '">SitePulse → Delivery Log</a></strong> '
            . 'records every delivery attempt — scheduled, retry, or test — with the exact payload sent and the response '
            . 'received.</p>';

        echo '<ul class="spa-about-list">';
        echo '<li>Paginated <em>Successful Deliveries</em> and <em>Failed Deliveries</em> lists, each sorted newest-first.</li>';
        echo '<li>Each entry shows the timestamp, endpoint (with its label, if one is set), delivery kind (Scheduled / Retry n of 5 / Test), HTTP response code, the full JSON payload, and the response body.</li>';
        echo '<li>Response downloads are capped at 64 KB, and values of sensitive-looking keys in JSON responses '
            . '(<code>password</code>, <code>token</code>, <code>secret</code>, <code>authorization</code>, …) are replaced with '
            . '<code>[REDACTED]</code> before storage. The <code>spa_delivery_log_row</code> filter allows site-specific '
            . 'redaction, or skipping an attempt entirely.</li>';
        echo '<li>Filter by <strong>year / month</strong>, by <strong>endpoint</strong> (when more than one has entries), or by free-text search over the payload. Paginate at 5 / 10 / 25 / 50 / 100 entries per page.</li>';
        echo '<li><strong>Delete</strong> a single entry via AJAX, <strong>Clear All Logs</strong> after confirmation, or export as <strong>CSV</strong> / <strong>JSON</strong> (both include the webhook label column and stream row-by-row, so large logs export in bounded memory).</li>';
        echo '<li>Entries share the analytics retention window and are pruned by the same daily cleanup.</li>';
        echo '</ul>';

        echo '<h3 class="spa-about-subheading">Deliveries REST API</h3>';
        echo '<p>The Delivery Log page can enable a read-only REST endpoint that returns the same data. It is intended for '
            . '<strong>server-to-server</strong> use — never embed the API key in public frontend JavaScript, where any visitor could read it.</p>';
        echo '<p><strong>Route:</strong> <code>GET /wp-json/sitepulse/v1/deliveries</code></p>';
        echo '<p>Enable the API from the <strong>Deliveries API</strong> card on the Delivery Log page. Only a hash of the key is '
            . 'stored, so the raw key is shown <strong>once</strong> — right after it is generated; copy it then, or regenerate a new one. '
            . 'Pass the key in every request (repeated failed attempts from one IP are throttled):</p>';
        echo '<pre class="spa-about-code">Authorization: &lt;your-api-key&gt;</pre>';

        echo '<table class="spa-about-table">';
        echo '<thead><tr><th>Query Parameter</th><th>Default</th><th>Notes</th></tr></thead><tbody>';
        echo '<tr><td><code>page</code></td><td>1</td><td>1-based page number, clamped to the last page.</td></tr>';
        echo '<tr><td><code>per_page</code></td><td>25</td><td>Max 100 entries per page.</td></tr>';
        echo '<tr><td><code>status</code></td><td><em>(all)</em></td><td><code>success</code> or <code>error</code> to filter; omit for every delivery.</td></tr>';
        echo '</tbody></table>';

        echo '<p style="margin-top:14px;">A sample response body — an array of delivery entries:</p>';
        echo '<pre class="spa-about-code">' . esc_html(
            (string) wp_json_encode(
                [
                    [
                        'id'            => 512,
                        'created_at'    => '2026-07-10 12:00:03',
                        'success'       => false,
                        'endpoint_url'  => 'https://crm.example.com/hooks/sitepulse',
                        'webhook_label' => 'CRM',
                        'delivery_id'   => '0f52c1d6a4b98e73d21f06c58a9b3e47',
                        'kind'          => 'retry',
                        'attempt'       => 2,
                        'response_code' => 503,
                        'request_data'  => ['source' => 'sitepulse-analytics', 'delivery_id' => '0f52c1d6a4b98e73d21f06c58a9b3e47', '...' => '...'],
                        'response_data' => ['error' => 'Service Unavailable'],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        ) . '</pre>';

        echo '<p>Pagination metadata is returned via <code>X-WP-Total</code>, <code>X-WP-TotalPages</code>, and '
            . '<code>X-SPA-Page</code> response headers. Cross-origin requests are permitted from any origin.</p>';
        echo '</div>';
    }

    /**
     * Renders the developer API reference (helper function and filters).
     *
     * @return void
     */
    private static function renderDeveloperSection(): void
    {
        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">Developer API</h2>';

        echo '<p>Record custom server-side events from any theme or plugin code:</p>';
        echo '<pre class="spa-about-code">' . esc_html(
            "spa_track_event('purchase', [\n    'page_url'      => home_url('/checkout/'),\n    'element_label' => 'Order #1234',\n    'event_value'   => '99.00',\n]);"
        ) . '</pre>';
        echo '<p>Custom event types appear in the dashboard\'s "Other Events" card, in totals, and in webhook payloads under their own type key.</p>';

        echo '<p>Record a confirmed conversion from frontend JavaScript — a <code>form_success</code> event with the '
            . 'session\'s attribution snapshot and a unique conversion id — by dispatching a DOM event. Use this for '
            . 'goals the built-in form integrations can\'t see (booking widgets, multi-step funnels, custom goals):</p>';
        echo '<pre class="spa-about-code">' . esc_html(
            "document.dispatchEvent(new CustomEvent('spa:conversion', {\n    detail: { name: 'appointment_booked' }\n}));"
        ) . '</pre>';

        echo '<table class="spa-about-table">';
        echo '<thead><tr><th>Filter</th><th>Purpose</th></tr></thead><tbody>';
        echo '<tr><td><code>spa_tracked_event</code></td>'
            . '<td>Inspect or modify an event row before it is stored; return <code>false</code> to drop it. '
            . 'Receives <code>(array $row, string $type)</code>.</td></tr>';
        echo '<tr><td><code>spa_webhook_payload</code></td>'
            . '<td>Modify the webhook payload before it is sent. '
            . 'Receives <code>(array $payload, int $startTs, int $endTs)</code>.</td></tr>';
        echo '<tr><td><code>spa_allowed_hosts</code></td>'
            . '<td>Hostnames accepted in tracked page URLs and Origin/Referer checks. Receives <code>(string[] $hosts)</code>.</td></tr>';
        echo '<tr><td><code>spa_rate_limits</code></td>'
            . '<td>Tune ingestion rate limits. Receives and returns <code>[\'per_ip\' => int, \'site_wide\' => int]</code>.</td></tr>';
        echo '<tr><td><code>spa_client_ip</code></td>'
            . '<td>Override the client IP used for rate limiting, e.g. to map a trusted reverse-proxy header.</td></tr>';
        echo '<tr><td><code>spa_source_aliases</code></td>'
            . '<td>Extend or override the map that normalizes <code>utm_source</code> values at ingestion '
            . '(e.g. <code>\'fb\' => \'facebook\'</code>). Receives and returns <code>array&lt;string, string&gt;</code> '
            . 'keyed by raw lowercase value.</td></tr>';
        echo '<tr><td><code>spa_channel</code></td>'
            . '<td>Override the marketing channel assigned to an event at ingestion. '
            . 'Receives <code>(string $channel, array $row, string $type)</code> — return any label (up to 24 characters).</td></tr>';
        echo '<tr><td><code>spa_delivery_log_row</code></td>'
            . '<td>Inspect or modify a delivery-log row before it is stored (e.g. site-specific redaction); '
            . 'return <code>false</code> to skip logging that attempt.</td></tr>';
        echo '</tbody></table>';

        echo '<div class="spa-about-note">';
        echo '<strong>Note:</strong> filters run on every tracked request, so keep callbacks fast and side-effect-free.';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Renders the updates explanation.
     *
     * @return void
     */
    private static function renderUpdatesSection(): void
    {
        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">Automatic Updates</h2>';
        echo '<p>The plugin checks its <a href="https://github.com/Magellan-Web-Dev/SitePulse-Analytics" target="_blank" rel="noopener">GitHub repository</a> '
            . 'for new releases every 12 hours through WordPress\'s normal update pipeline. When a newer release is published, '
            . 'the standard update banner appears on the Plugins screen; a "Check for updates" row action there forces an immediate check.</p>';
        echo '</div>';
    }
}
