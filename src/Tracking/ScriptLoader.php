<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Tracking;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;

/**
 * Enqueues the frontend tracker script and injects its configuration.
 *
 * The tracker is a single dependency-free script loaded deferred in the
 * footer. Its behavior (REST endpoint, which event types to record, hover
 * dwell time, batching) is passed via a window.SitePulseConfig object printed
 * immediately before the script tag, so the JS file itself stays static and
 * cacheable.
 */
final class ScriptLoader
{
    /** @var string Script handle for the frontend tracker. */
    private const HANDLE = 'spa-tracker';

    /**
     * Registers the wp_enqueue_scripts hook.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * Enqueues the tracker on frontend requests that should be tracked.
     *
     * Skipped entirely for logged-in users when exclusion is enabled and when
     * every event type has been turned off — in both cases no script tag is
     * printed at all.
     *
     * @return void
     */
    public static function enqueue(): void
    {
        if (Options::excludeLoggedIn() && is_user_logged_in()) {
            return;
        }

        $enabled = Options::enabledTypes();
        if ($enabled === []) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            SPA_PLUGIN_URL . 'assets/js/tracker.js',
            [],
            SPA_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );

        $config = [
            'endpoint'        => esc_url_raw(rest_url('sitepulse/v1/track')),
            'events'          => array_fill_keys($enabled, true),
            'hoverDwellMs'    => Options::hoverDwellMs(),
            'flushIntervalMs' => 5000,
            'maxBatch'        => 20,
            'respectDnt'      => Options::respectDnt(),
        ];

        wp_add_inline_script(
            self::HANDLE,
            'window.SitePulseConfig = ' . wp_json_encode($config) . ';',
            'before'
        );
    }
}
