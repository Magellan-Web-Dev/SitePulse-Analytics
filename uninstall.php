<?php
/**
 * SitePulse Analytics — uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the Plugins screen (never on
 * deactivation). Removes everything the plugin created: the events table,
 * all options, the cached GitHub release transient, and any scheduled cron
 * events. After this runs, no trace of the plugin remains in the database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Custom analytics events and webhook delivery log tables.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}spa_events");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}spa_webhook_deliveries");

// Plugin options ('spa_webhook_log' is the pre-1.1.0 rolling log).
delete_option('spa_settings');
delete_option('spa_db_version');
delete_option('spa_delivery_db_version');
delete_option('spa_delivery_api_active');
delete_option('spa_delivery_api_key');
delete_option('spa_delivery_api_key_hash');
delete_option('spa_webhook_last_sent');
delete_option('spa_webhook_retry_state');
delete_option('spa_webhook_log');

// Cached GitHub release data.
delete_site_transient('spa_github_release');

// Rate-limit warning flag (would otherwise expire on its own within a day).
delete_transient('spa_rate_limited_at');

// Scheduled cron events, including any pending single-event delivery retries.
wp_clear_scheduled_hook('spa_cleanup_old_events');
wp_clear_scheduled_hook('spa_dispatch_webhooks');
wp_unschedule_hook('spa_retry_webhook');
