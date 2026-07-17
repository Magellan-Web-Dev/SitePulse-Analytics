<?php
/**
 * Plugin Name: SitePulse Analytics
 * Description: Tracks page views, link and button clicks, form submissions, hover activity, scroll depth, and other visitor interactions. Analytics are displayed in the WordPress dashboard and can be sent on a schedule as JSON to one or more webhook endpoints.
 * Version:     1.6.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author:      Chris Paschall
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * This file must not use PHP 8.1+ syntax directly. PHP parses the entire file
 * before executing any branch, so 8.1+ syntax here would cause a fatal parse
 * error on older runtimes before this guard ever runs. PHP 8.1+ code is safely
 * isolated in the separately required files inside the else block below.
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>SitePulse Analytics</strong> requires PHP 8.1 or higher. '
            . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });

/**
 * Main plugin bootstrap.
 * Defines plugin constants, boots the PSR-4 autoloader, and registers the
 * activation/deactivation hooks and the plugins_loaded handler that
 * instantiates and initializes the Plugin composition root.
 */
} else {

    define('SPA_VERSION', '1.6.0');
    define('SPA_PLUGIN_FILE', __FILE__);
    define('SPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('SPA_PLUGIN_URL', plugin_dir_url(__FILE__));

    require_once SPA_PLUGIN_DIR . 'src/Autoloader.php';

    SitePulseAnalytics\Autoloader::boot(SPA_PLUGIN_DIR);

    /**
     * Plugin activation: create the custom events table and schedule the cron
     * events for daily data retention cleanup and periodic webhook dispatch.
     *
     * register_activation_hook must be called in the main plugin file (not inside
     * a class method) to fire reliably. The DatabaseManager handles idempotent
     * table creation via dbDelta, so re-activating the plugin is safe.
     */
    register_activation_hook(__FILE__, static function (): void {
        SitePulseAnalytics\Database\DatabaseManager::createTable();
        SitePulseAnalytics\Webhook\DeliveryLog::createTable();

        if (!wp_next_scheduled('spa_cleanup_old_events')) {
            wp_schedule_event(time(), 'daily', 'spa_cleanup_old_events');
        }

        SitePulseAnalytics\Webhook\WebhookDispatcher::schedule();
    });

    /**
     * Plugin deactivation: unschedule the cleanup and webhook dispatch cron
     * events. Pending webhook retries are suspended, not discarded — their
     * frozen deliveries are kept in the exhausted state so the first
     * scheduled dispatch after reactivation resumes them under their
     * original delivery IDs (see WebhookDispatcher::suspendAllRetries()).
     *
     * The events table and its data are intentionally preserved on deactivation
     * so analytics survive a deactivate/reactivate cycle. Data is only removed
     * when rows age past the configured retention window or when the plugin is
     * uninstalled (see uninstall.php).
     */
    register_deactivation_hook(__FILE__, static function (): void {
        wp_clear_scheduled_hook('spa_cleanup_old_events');
        wp_clear_scheduled_hook(SitePulseAnalytics\Webhook\WebhookDispatcher::CRON_HOOK);
        SitePulseAnalytics\Webhook\WebhookDispatcher::suspendAllRetries();
    });

    add_action('plugins_loaded', static function (): void {
        SitePulseAnalytics\Plugin::getInstance()->init();
    });

    /**
     * Records a custom analytics event from server-side code.
     *
     * Use this to track interactions the frontend script cannot see (e.g. a
     * REST API hit, a completed purchase, a custom conversion action). Events
     * recorded here appear in the dashboard's totals and are included in
     * webhook payloads under their own event type.
     *
     * @param string               $type Short event type key (lowercase letters,
     *                                   digits, dashes, underscores; max 20 chars).
     * @param array<string, mixed> $data Optional event context. Recognized keys:
     *                                   'page_url', 'page_title', 'element_tag',
     *                                   'element_label', 'target_url',
     *                                   'event_value', 'referrer', 'session_id',
     *                                   'device', 'utm_source', 'utm_medium',
     *                                   'utm_campaign', 'utm_id', 'utm_term',
     *                                   'utm_content', 'click_id_type',
     *                                   'channel', and 'session_referrer' (the
     *                                   session's entrance referrer — used for
     *                                   channel classification, not stored).
     *                                   Unknown keys are ignored.
     *                                   Note: a 'form_success' event requires
     *                                   'event_value' to be a unique conversion
     *                                   id (8–100 chars of A-Za-z0-9_.:-);
     *                                   events without one are rejected so
     *                                   conversion dedup stays consistent.
     * @return bool True when the event row was stored.
     */
    function spa_track_event($type, array $data = array())
    {
        return SitePulseAnalytics\Database\DatabaseManager::insertEvent((string) $type, $data);
    }
}
