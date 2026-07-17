<?php
/**
 * SitePulse Analytics — uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the Plugins screen (never on
 * deactivation). Removes everything the plugin created: the events table,
 * all options, the cached GitHub release transient, and any scheduled cron
 * events. On multisite, the per-site cleanup runs for EVERY site — tables,
 * options, and cron events are per-site, so a network-activated uninstall
 * that only cleaned the current site would leave data behind everywhere
 * else. After this runs, no trace of the plugin remains in the database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Removes everything the plugin created for the CURRENT site (tables,
 * options, transients, cron events).
 *
 * @return void
 */
function spa_uninstall_current_site(): void
{
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
    delete_option('spa_webhook_dispatch_lock');
    delete_option('spa_webhook_log');

    // Rate-limit counter rows, written directly to the options table by the
    // REST controller when no persistent object cache is available.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'spa\\_rl\\_%'");

    // Rate-limit warning flag (would otherwise expire on its own within a day).
    delete_transient('spa_rate_limited_at');

    // Scheduled cron events, including any pending single-event delivery retries.
    wp_clear_scheduled_hook('spa_cleanup_old_events');
    wp_clear_scheduled_hook('spa_dispatch_webhooks');
    wp_unschedule_hook('spa_retry_webhook');
}

if (is_multisite()) {
    $spa_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($spa_site_ids as $spa_site_id) {
        switch_to_blog((int) $spa_site_id);
        spa_uninstall_current_site();
        restore_current_blog();
    }
} else {
    spa_uninstall_current_site();
}

// Cached GitHub release data (network-wide on multisite).
delete_site_transient('spa_github_release');
