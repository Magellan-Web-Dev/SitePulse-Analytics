<?php
declare(strict_types=1);

namespace SitePulseAnalytics;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Admin\AboutPage;
use SitePulseAnalytics\Admin\DashboardPage;
use SitePulseAnalytics\Admin\DeliveryLogPage;
use SitePulseAnalytics\Admin\SettingsPage;
use SitePulseAnalytics\Api\DeliveryLogApi;
use SitePulseAnalytics\Database\DatabaseManager;
use SitePulseAnalytics\Tracking\RestController;
use SitePulseAnalytics\Tracking\ScriptLoader;
use SitePulseAnalytics\Updates\GitHubUpdater;
use SitePulseAnalytics\Webhook\DeliveryLog;
use SitePulseAnalytics\Webhook\WebhookDispatcher;

/**
 * Composition root for the entire plugin.
 *
 * A single instance is created on plugins_loaded (see the main plugin file)
 * and init() wires every subsystem together:
 *
 *  - DatabaseManager   — custom events table (creation, upgrades, retention cleanup)
 *  - RestController    — public REST endpoint that receives tracked events
 *  - ScriptLoader      — enqueues the frontend tracker script with its config
 *  - WebhookDispatcher — periodic JSON POST of analytics to webhook endpoints
 *  - GitHubUpdater     — update notifications from GitHub releases
 *  - DashboardPage / SettingsPage / AboutPage — wp-admin UI (admin requests only)
 *
 * No subsystem holds state; each exposes a static init() that registers its
 * own hooks, keeping this class a thin, readable wiring diagram.
 */
final class Plugin
{
    /** @var Plugin|null Singleton instance. */
    private static ?Plugin $instance = null;

    /**
     * Private constructor; use {@see getInstance()}.
     */
    private function __construct()
    {
    }

    /**
     * Returns the shared plugin instance, creating it on first call.
     *
     * @return Plugin
     */
    public static function getInstance(): Plugin
    {
        return self::$instance ??= new self();
    }

    /**
     * Initializes every subsystem. Called once on plugins_loaded.
     *
     * @return void
     */
    public function init(): void
    {
        DatabaseManager::maybeUpgrade();
        DeliveryLog::maybeCreateTable();

        RestController::init();
        ScriptLoader::init();
        WebhookDispatcher::init();
        DeliveryLogApi::init();
        GitHubUpdater::init();

        add_action('spa_cleanup_old_events', [DatabaseManager::class, 'cleanupOldEvents']);
        add_action('spa_cleanup_old_events', [DeliveryLog::class, 'purgeOld']);
        add_action(DatabaseManager::CLEANUP_CATCHUP_HOOK, [DatabaseManager::class, 'cleanupOldEventsCatchUp']);

        $this->ensureCronScheduled();

        if (is_admin()) {
            DashboardPage::init();
            SettingsPage::init();
            DeliveryLogPage::init();
            AboutPage::init();
        }
    }

    /**
     * Safety net that re-schedules the cron events if they are missing.
     *
     * Activation normally schedules both events, but crons can be lost (e.g.
     * a cron-clearing plugin, a site migration, or a manual wp-cron purge).
     * Re-checking on every load keeps retention cleanup and webhook dispatch
     * running without requiring a re-activation.
     *
     * @return void
     */
    private function ensureCronScheduled(): void
    {
        if (!wp_next_scheduled('spa_cleanup_old_events')) {
            wp_schedule_event(time(), 'daily', 'spa_cleanup_old_events');
        }

        WebhookDispatcher::schedule();
    }
}
