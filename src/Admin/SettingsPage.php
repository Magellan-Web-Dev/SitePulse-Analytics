<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Admin;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;
use SitePulseAnalytics\Webhook\WebhookDispatcher;

/**
 * The "SitePulse → Settings" submenu page.
 *
 * Manages, via the WordPress Settings API, in the order rendered on the page:
 *  - the "Webhook Status" master toggle, a labeled, repeatable webhook
 *    endpoint list, optional client/website identifiers sent in every
 *    payload, and the send interval (layout ported from the Forms Webhook
 *    Integrator plugin's settings page);
 *  - which interaction types are tracked, whether logged-in users are
 *    excluded, DNT/GPC handling, and the hover dwell threshold;
 *  - the data retention window.
 *
 * Also provides a nonce-protected "Send test payload now" action and a link
 * to the Delivery Log page so admins can verify endpoints work.
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
        $out['webhook_active']   = !empty($input['webhook_active']);

        $interval = (string) ($input['webhook_interval'] ?? 'daily');
        $out['webhook_interval'] = in_array($interval, Options::INTERVALS, true) ? $interval : 'daily';

        $out['webhook_secret']   = mb_substr(sanitize_text_field((string) ($input['webhook_secret'] ?? '')), 0, 190);
        $out['webhook_backfill'] = !empty($input['webhook_backfill']);

        $out['client_first_name'] = mb_substr(sanitize_text_field((string) ($input['client_first_name'] ?? '')), 0, 190);
        $out['client_last_name']  = mb_substr(sanitize_text_field((string) ($input['client_last_name'] ?? '')), 0, 190);
        $out['client_id']         = mb_substr(sanitize_text_field((string) ($input['client_id'] ?? '')), 0, 190);
        $out['website_id']        = mb_substr(sanitize_text_field((string) ($input['website_id'] ?? '')), 0, 190);

        // The webhook repeater submits webhooks[][url] / webhooks[][label].
        $raw = is_array($input['webhooks'] ?? null) ? $input['webhooks'] : [];

        $webhooks = [];
        $seen     = [];
        foreach ($raw as $entry) {
            $rawUrl = trim(is_array($entry) ? (string) ($entry['url'] ?? '') : (string) $entry);
            if ($rawUrl === '') {
                continue;
            }

            $url = esc_url_raw($rawUrl, ['http', 'https']);
            if ($url === '' || !wp_http_validate_url($url) || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $webhooks[] = [
                'url'   => $url,
                'label' => mb_substr(sanitize_text_field((string) (is_array($entry) ? ($entry['label'] ?? '') : '')), 0, 100),
            ];
        }
        $out['webhooks'] = $webhooks;

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

        self::renderWebhookSection($settings);
        self::renderTrackingSection($settings);
        self::renderDataSection($settings);

        submit_button();
        echo '</form>';

        self::renderTestSendButton();
        self::renderPendingRetries();
        self::renderDeliveryLogLink();

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

            if (!empty($retry['exhausted'])) {
                $due = 'with the next scheduled send (retry chain exhausted; the frozen payload is kept and re-sent first)';
            } elseif ($when > time()) {
                $due = 'in ' . human_time_diff(time(), $when);
            } else {
                $due = 'as soon as WP-Cron next runs';
            }

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
            'form_submit'  => 'Form submissions (attempts)',
            'form_success' => 'Confirmed form conversions (Elementor Pro, Contact Form 7, WPForms, Gravity Forms)',
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
     * Renders the "Webhook Status" toggle card and the "Webhook Settings"
     * card (labeled endpoint repeater plus the send interval), matching the
     * layout of the Forms Webhook Integrator plugin's settings page. Like
     * that plugin's toggle, this one is hidden until at least one endpoint
     * URL is saved — a master switch is meaningless with nothing configured
     * to activate.
     *
     * @param array<string, mixed> $settings Current settings.
     * @return void
     */
    private static function renderWebhookSection(array $settings): void
    {
        $webhooks = Options::webhooks();
        if ($webhooks === []) {
            $webhooks = [['url' => '', 'label' => '']];
        }
        $hasAnyUrl = (bool) array_filter(array_column($webhooks, 'url'));

        echo '<div class="spa-card spa-toggle-card" id="spa-webhook-toggle-card"'
            . ($hasAnyUrl ? '' : ' style="display:none"') . '>';
        echo '<h2 class="spa-card-title">Webhook Status</h2>';
        echo '<div class="spa-toggle-row">';
        echo '<label class="spa-toggle" for="spa_webhook_active" aria-label="Toggle webhook active state">';
        echo '<input type="checkbox" id="spa_webhook_active" name="' . esc_attr(Options::OPTION_KEY . '[webhook_active]')
            . '" value="1" ' . checked(!empty($settings['webhook_active']), true, false) . '>';
        echo '<span class="spa-toggle-slider" aria-hidden="true"></span>';
        echo '</label>';
        echo '<span class="spa-toggle-label" id="spa-webhook-toggle-label">'
            . (!empty($settings['webhook_active']) ? 'Active' : 'Inactive') . '</span>';
        echo '</div>';
        echo '<p class="description">When inactive, no new scheduled deliveries are sent — saved endpoints and settings are preserved.</p>';
        echo '</div>';

        echo '<div class="spa-card">';
        echo '<h2 class="spa-card-title">Webhook Settings</h2>';
        echo '<p class="description" style="margin-bottom:14px;">Aggregated analytics are sent to every endpoint listed below on the schedule you choose. '
            . 'Add a label so each webhook is easy to identify in the Delivery Log. '
            . 'Each endpoint receives the data collected since its own last successful delivery, and failed deliveries are retried '
            . 'automatically up to 5 more times over about 24 hours — no data is lost either way.</p>';

        echo '<div id="spa-webhooks-container">';
        foreach ($webhooks as $idx => $webhook) {
            self::renderWebhookBlock($idx, (string) $webhook['url'], (string) $webhook['label']);
        }
        echo '</div>';

        echo '<button type="button" id="spa-add-webhook" class="button" style="margin-top:12px;">+ Add Additional URL</button>';

        echo '<hr style="border:none;border-top:1px solid #f0f0f1;margin:20px 0;">';

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="spa-client-first-name">Client First Name</label></th><td>';
        echo '<input type="text" id="spa-client-first-name" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[client_first_name]') . '" value="' . esc_attr((string) $settings['client_first_name']) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="spa-client-last-name">Client Last Name</label></th><td>';
        echo '<input type="text" id="spa-client-last-name" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[client_last_name]') . '" value="' . esc_attr((string) $settings['client_last_name']) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="spa-client-id">Client ID <span class="description">(optional)</span></label></th><td>';
        echo '<input type="text" id="spa-client-id" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[client_id]') . '" value="' . esc_attr((string) $settings['client_id']) . '">';
        echo '<p class="description">Sent as <code>website_info.client.id</code> in every webhook payload.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="spa-website-id">Website ID <span class="description">(optional)</span></label></th><td>';
        echo '<input type="text" id="spa-website-id" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[website_id]') . '" value="' . esc_attr((string) $settings['website_id']) . '">';
        echo '<p class="description">Sent as <code>website_info.id</code> in every webhook payload.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="spa-webhook-secret">Signing secret <span class="description">(optional)</span></label></th><td>';
        echo '<input type="text" id="spa-webhook-secret" class="regular-text code" autocomplete="off" name="'
            . esc_attr(Options::OPTION_KEY . '[webhook_secret]') . '" value="' . esc_attr((string) $settings['webhook_secret']) . '">';
        echo '<p class="description">When set, every webhook request includes an <code>X-SPA-Signature</code> header — '
            . '<code>sha256=&lt;hex&gt;</code>, the HMAC-SHA256 of the raw JSON body keyed with this secret — so the '
            . 'receiver can verify payloads genuinely came from this site. Share the secret with the endpoint operator '
            . 'over a secure channel.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">History backfill</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[webhook_backfill]') . '" value="1" '
            . checked(!empty($settings['webhook_backfill']), true, false) . '> Send retained history to new endpoints</label>';
        echo '<p class="description">When enabled, an endpoint that has never received a delivery starts from the '
            . 'beginning of the retention window instead of one send interval ago. History is delivered in '
            . 'interval-sized windows (up to 10 per scheduled run), so a long backlog is worked off over a few runs.</p>';
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
        echo '</div>';
    }

    /**
     * Renders one webhook block of the repeater: a URL field and an optional
     * label field, with a Remove button on every block but the first (which
     * is the form's permanent field — clearing it, alongside the Webhook
     * Status toggle, is how webhook sending is disabled).
     *
     * @param int    $index Zero-based position in the repeater.
     * @param string $url   Saved endpoint URL, or '' for an empty block.
     * @param string $label Saved label, or '' when none was set.
     * @return void
     */
    private static function renderWebhookBlock(int $index, string $url, string $label): void
    {
        echo '<div class="spa-webhook-block" data-webhook-index="' . esc_attr((string) $index) . '">';

        echo '<div class="spa-webhook-block-header">';
        echo '<strong class="spa-webhook-block-title">Webhook ' . esc_html((string) ($index + 1)) . '</strong>';
        if ($index > 0) {
            echo '<button type="button" class="button spa-remove-webhook-btn" aria-label="Remove webhook '
                . esc_attr((string) ($index + 1)) . '">Remove</button>';
        }
        echo '</div>';

        echo '<div class="spa-webhook-url-row">';
        echo '<input type="url" class="spa-webhook-url-input regular-text code" '
            . 'name="' . esc_attr(Options::OPTION_KEY . '[webhooks][' . $index . '][url]') . '" '
            . 'value="' . esc_attr($url) . '" placeholder="https://example.com/analytics-hook" '
            . 'aria-label="Webhook ' . esc_attr((string) ($index + 1)) . ' URL">';
        echo '</div>';

        echo '<div style="margin-top:8px;">';
        echo '<input type="text" class="regular-text spa-webhook-label-input" '
            . 'name="' . esc_attr(Options::OPTION_KEY . '[webhooks][' . $index . '][label]') . '" '
            . 'value="' . esc_attr($label) . '" placeholder="Label (optional — shown in the Delivery Log)" '
            . 'aria-label="Webhook ' . esc_attr((string) ($index + 1)) . ' label">';
        echo '</div>';

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
     * Renders a pointer to the Delivery Log page, which shows every attempt
     * with its payload, response, filters, and pagination.
     *
     * @return void
     */
    private static function renderDeliveryLogLink(): void
    {
        $url = add_query_arg(['page' => DeliveryLogPage::MENU_SLUG], self_admin_url('admin.php'));

        echo '<h2>Delivery Log</h2>';
        echo '<p>Every delivery attempt — with the exact payload sent and the response received — is recorded on the '
            . '<a href="' . esc_url($url) . '">Delivery Log</a> page.</p>';
    }
}
