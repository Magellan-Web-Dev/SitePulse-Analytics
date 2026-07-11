<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Admin;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;
use SitePulseAnalytics\Webhook\WebhookDispatcher;

/**
 * The "SitePulse → Settings" submenu page.
 *
 * Manages, via the WordPress Settings API:
 *  - which interaction types are tracked,
 *  - whether logged-in users are excluded,
 *  - the data retention window and hover dwell threshold,
 *  - the webhook endpoint list (one URL per line) and send interval.
 *
 * Also provides a nonce-protected "Send test payload now" action and shows
 * the rolling webhook delivery log so admins can verify endpoints work.
 */
final class SettingsPage
{
    /** @var string Menu slug for the settings submenu page. */
    public const MENU_SLUG = 'sitepulse-analytics-settings';

    /** @var string Settings API option group. */
    private const OPTION_GROUP = 'spa_settings_group';

    /** @var string Admin action name for the manual test send. */
    private const TEST_ACTION = 'spa_send_test_webhook';

    /**
     * Registers menu, settings, and test-send hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_init', [self::class, 'handleTestSend']);
        add_action('admin_notices', [self::class, 'maybeShowTestNotice']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Enqueues the settings-page script (webhook endpoint repeater) on this
     * page only. The shared stylesheet is enqueued by DashboardPage for all
     * plugin pages.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_script(
            'spa-admin',
            SPA_PLUGIN_URL . 'assets/js/admin.js',
            [],
            SPA_VERSION,
            true
        );
    }

    /**
     * Adds the Settings submenu under the SitePulse top-level menu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            DashboardPage::MENU_SLUG,
            'SitePulse Analytics Settings',
            'Settings',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Registers the single settings option with its sanitize callback.
     *
     * @return void
     */
    public static function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, Options::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => Options::defaults(),
        ]);
    }

    /**
     * Normalizes raw form input into the canonical settings shape.
     *
     * Invalid webhook lines are silently dropped; numeric fields are clamped
     * to the same ranges {@see Options} enforces on read, so what is stored
     * is always exactly what will be used.
     *
     * @param mixed $input Raw value from the settings form.
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $out   = Options::defaults();

        foreach (Options::EVENT_TYPES as $type) {
            $out['track_' . $type] = !empty($input['track_' . $type]);
        }

        $out['exclude_logged_in'] = !empty($input['exclude_logged_in']);
        $out['respect_dnt']       = !empty($input['respect_dnt']);
        $out['retention_days']    = min(365, max(7, (int) ($input['retention_days'] ?? 90)));
        $out['hover_dwell_ms']    = min(10000, max(200, (int) ($input['hover_dwell_ms'] ?? 800)));

        $interval = (string) ($input['webhook_interval'] ?? 'daily');
        $out['webhook_interval'] = in_array($interval, Options::INTERVALS, true) ? $interval : 'daily';

        // The endpoint repeater submits webhook_urls[] as an array of fields;
        // a newline-separated string is still accepted for robustness.
        $raw        = $input['webhook_urls'] ?? [];
        $candidates = is_array($raw) ? $raw : (preg_split('/\r\n|\r|\n/', (string) $raw) ?: []);

        $urls = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $url = esc_url_raw($candidate, ['http', 'https']);
            if ($url !== '' && wp_http_validate_url($url)) {
                $urls[] = $url;
            }
        }
        $out['webhook_urls'] = array_values(array_unique($urls));

        return $out;
    }

    /**
     * Handles the "Send test payload now" action triggered from this page.
     *
     * Mirrors the GitHubUpdater's action pattern: nonce + capability check,
     * do the work, then redirect back with a query flag for the notice.
     *
     * @return void
     */
    public static function handleTestSend(): void
    {
        if (
            empty($_GET['action']) ||
            $_GET['action'] !== self::TEST_ACTION ||
            empty($_GET['spa_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['spa_nonce'])), self::TEST_ACTION) ||
            !current_user_can('manage_options')
        ) {
            return;
        }

        WebhookDispatcher::sendTest();

        wp_safe_redirect(self_admin_url('admin.php?page=' . self::MENU_SLUG . '&spa_test_sent=1'));
        exit;
    }

    /**
     * Shows a notice after a manual test send completes.
     *
     * @return void
     */
    public static function maybeShowTestNotice(): void
    {
        if (empty($_GET['spa_test_sent']) || $_GET['spa_test_sent'] !== '1') {
            return;
        }

        if (Options::webhookUrls() === []) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . 'SitePulse Analytics: No webhook endpoints are configured, so no test payload was sent.'
                . '</p></div>';
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>'
            . 'SitePulse Analytics: Test payload sent. Check the delivery log below for each endpoint\'s result.'
            . '</p></div>';
    }

    /**
     * Renders the settings page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Options::all();

        echo '<div class="wrap spa-wrap">';
        echo '<h1>SitePulse Analytics Settings</h1>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);

        self::renderTrackingSection($settings);
        self::renderDataSection($settings);
        self::renderWebhookSection($settings);

        submit_button();
        echo '</form>';

        self::renderTestSendButton();
        self::renderPendingRetries();
        self::renderDeliveryLog();

        echo '</div>';
    }

    /**
     * Renders a warning listing deliveries currently waiting on a retry.
     *
     * Outputs nothing when no retries are pending, which is the normal state.
     *
     * @return void
     */
    private static function renderPendingRetries(): void
    {
        $pending = WebhookDispatcher::getPendingRetries();
        if ($pending === []) {
            return;
        }

        $max = WebhookDispatcher::maxRetries();

        echo '<div class="notice notice-warning inline"><p><strong>Pending delivery retries</strong></p><ul style="margin-left:1.5em;list-style:disc;">';

        foreach ($pending as $retry) {
            $when = (int) ($retry['scheduled_for'] ?? 0);
            $due  = $when > time()
                ? 'in ' . human_time_diff(time(), $when)
                : 'as soon as WP-Cron next runs';

            printf(
                '<li>Retry %d of %d to <code>%s</code> — next attempt %s.</li>',
                (int) ($retry['attempt'] ?? 1),
                (int) $max,
                esc_html((string) ($retry['url'] ?? '')),
                esc_html($due)
            );
        }

        echo '</ul></div>';
    }

    /**
     * Renders the "What to track" checkboxes.
     *
     * @param array<string, mixed> $settings Current settings.
     * @return void
     */
    private static function renderTrackingSection(array $settings): void
    {
        $labels = [
            'pageview'     => 'Page views',
            'click'        => 'Link &amp; button clicks',
            'form_submit'  => 'Form submissions',
            'hover'        => 'Mouse hover activity',
            'scroll_depth' => 'Scroll depth milestones (25/50/75/100%)',
        ];

        echo '<h2>Tracking</h2>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">Interactions to track</th><td>';
        foreach ($labels as $type => $label) {
            $field = 'track_' . $type;
            echo '<label style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[' . $field . ']') . '" value="1" '
                . checked(!empty($settings[$field]), true, false) . '> ' . $label;
            echo '</label>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">Logged-in users</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[exclude_logged_in]') . '" value="1" '
            . checked(!empty($settings['exclude_logged_in']), true, false) . '> Exclude logged-in users from tracking</label>';
        echo '<p class="description">Recommended, so admin and editor activity does not skew visitor analytics.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Browser privacy signals</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[respect_dnt]') . '" value="1" '
            . checked(!empty($settings['respect_dnt']), true, false) . '> Honor Do Not Track / Global Privacy Control</label>';
        echo '<p class="description">Visitors whose browser sends these signals are not tracked at all. Enabling this typically reduces recorded traffic.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="spa-hover-dwell">Hover dwell time (ms)</label></th><td>';
        echo '<input type="number" id="spa-hover-dwell" min="200" max="10000" step="50" name="'
            . esc_attr(Options::OPTION_KEY . '[hover_dwell_ms]') . '" value="' . esc_attr((string) $settings['hover_dwell_ms']) . '" class="small-text">';
        echo '<p class="description">How long the pointer must rest on an element before a hover event is recorded.</p>';
        echo '</td></tr>';

        echo '</table>';
    }

    /**
     * Renders the data retention field.
     *
     * @param array<string, mixed> $settings Current settings.
     * @return void
     */
    private static function renderDataSection(array $settings): void
    {
        echo '<h2>Data</h2>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="spa-retention">Retention period (days)</label></th><td>';
        echo '<input type="number" id="spa-retention" min="7" max="365" name="'
            . esc_attr(Options::OPTION_KEY . '[retention_days]') . '" value="' . esc_attr((string) $settings['retention_days']) . '" class="small-text">';
        echo '<p class="description">Events older than this are deleted by a daily cleanup job. Default is 90 days.</p>';
        echo '</td></tr>';

        echo '</table>';
    }

    /**
     * Renders the webhook endpoint list and interval selector.
     *
     * @param array<string, mixed> $settings Current settings.
     * @return void
     */
    private static function renderWebhookSection(array $settings): void
    {
        $urls = is_array($settings['webhook_urls']) ? $settings['webhook_urls'] : [];

        echo '<h2>Webhooks</h2>';
        echo '<p>On the schedule below, aggregated analytics are sent as a JSON <code>POST</code> to every endpoint listed. '
            . 'Each endpoint receives the data collected since its own last successful delivery. '
            . 'Failed webhook deliveries are retried automatically up to 5 more times over about 24 hours '
            . '(after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours); no data is lost either way, since '
            . 'an endpoint\'s next successful delivery always covers everything since its last one.</p>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">Endpoint URLs</th><td>';

        echo '<div id="spa-webhook-repeater">';
        foreach (array_values($urls === [] ? [''] : $urls) as $i => $url) {
            self::renderWebhookRow((string) $url, $i > 0);
        }
        echo '</div>';

        echo '<p><button type="button" class="button" id="spa-add-webhook">+ Add another endpoint</button></p>';
        echo '<p class="description">Only valid http(s) URLs are saved; empty fields are ignored. Clear every field to disable webhook sending.</p>';

        // Blueprint for rows the "+ Add another endpoint" button appends.
        echo '<template id="spa-webhook-row-template">';
        self::renderWebhookRow('', true);
        echo '</template>';

        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="spa-webhook-interval">Send interval</label></th><td>';
        echo '<select id="spa-webhook-interval" name="' . esc_attr(Options::OPTION_KEY . '[webhook_interval]') . '">';
        $intervalLabels = [
            'hourly'     => 'Hourly',
            'twicedaily' => 'Twice daily',
            'daily'      => 'Daily',
            'weekly'     => 'Weekly',
        ];
        foreach ($intervalLabels as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['webhook_interval'], $value, false) . '>'
                . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<p class="description">Each site delivers at a random, stable time within the interval (scattered up to 24 hours), '
            . 'so many sites sharing an endpoint don\'t all send at the same moment.';

        $next = wp_next_scheduled(WebhookDispatcher::CRON_HOOK);
        if ($next !== false) {
            $due = $next > time()
                ? 'in ' . human_time_diff(time(), (int) $next)
                : 'as soon as WP-Cron next runs';

            echo ' Next scheduled send: <strong>' . esc_html(gmdate('Y-m-d H:i', (int) $next)) . ' UTC</strong> ('
                . esc_html($due) . ').';
        }

        echo '</p>';
        echo '</td></tr>';

        echo '</table>';
    }

    /**
     * Renders one endpoint row of the webhook repeater. Also used inside the
     * <template> element that the "+ Add another endpoint" button clones.
     *
     * The first row is rendered without a Remove button — it is the form's
     * permanent field (clearing it is how webhooks are disabled), so removing
     * it would be meaningless. Every additional row gets one.
     *
     * @param string $url       Saved endpoint URL, or '' for an empty row.
     * @param bool   $removable Whether to include the Remove button.
     * @return void
     */
    private static function renderWebhookRow(string $url, bool $removable): void
    {
        echo '<div class="spa-webhook-row">';
        echo '<input type="url" name="' . esc_attr(Options::OPTION_KEY . '[webhook_urls][]') . '" value="' . esc_attr($url) . '" '
            . 'class="regular-text code" placeholder="https://example.com/analytics-hook">';

        if ($removable) {
            echo '<button type="button" class="button spa-remove-webhook" aria-label="Remove this endpoint">Remove</button>';
        }

        echo '</div>';
    }

    /**
     * Renders the nonce-protected "Send test payload now" button.
     *
     * @return void
     */
    private static function renderTestSendButton(): void
    {
        $url = wp_nonce_url(
            add_query_arg(
                ['page' => self::MENU_SLUG, 'action' => self::TEST_ACTION],
                self_admin_url('admin.php')
            ),
            self::TEST_ACTION,
            'spa_nonce'
        );

        echo '<h2>Test Delivery</h2>';
        echo '<p>Sends a payload covering the last 7 days to every saved endpoint. Save your settings first.</p>';
        echo '<p><a href="' . esc_url($url) . '" class="button button-secondary">Send test payload now</a></p>';
    }

    /**
     * Renders the rolling webhook delivery log.
     *
     * @return void
     */
    private static function renderDeliveryLog(): void
    {
        $log = WebhookDispatcher::getLog();

        echo '<h2>Delivery Log</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>When (UTC)</th><th>Endpoint</th><th>Result</th><th>HTTP</th><th>Type</th></tr></thead><tbody>';

        if ($log === []) {
            echo '<tr><td colspan="5">No webhook deliveries have been attempted yet.</td></tr>';
        }

        foreach ($log as $entry) {
            $status = !empty($entry['ok'])
                ? '<span style="color:#00a32a;">✔ ' . esc_html((string) ($entry['message'] ?? 'Delivered')) . '</span>'
                : '<span style="color:#d63638;">✘ ' . esc_html((string) ($entry['message'] ?? 'Failed')) . '</span>';

            $kind = match ((string) ($entry['kind'] ?? '')) {
                'test'  => 'Test',
                'retry' => sprintf('Retry %d/%d', (int) ($entry['attempt'] ?? 1), WebhookDispatcher::maxRetries()),
                default => 'Scheduled',
            };

            echo '<tr>';
            echo '<td>' . esc_html(gmdate('Y-m-d H:i:s', (int) ($entry['time'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($entry['url'] ?? '')) . '</td>';
            echo '<td>' . $status . '</td>';
            echo '<td>' . esc_html((string) ($entry['code'] ?? 0)) . '</td>';
            echo '<td>' . esc_html($kind) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
