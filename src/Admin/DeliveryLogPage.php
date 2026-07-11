<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Admin;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Api\DeliveryLogApi;
use SitePulseAnalytics\Settings\Options;
use SitePulseAnalytics\Webhook\DeliveryLog;
use SitePulseAnalytics\Webhook\WebhookDispatcher;

/**
 * The "SitePulse → Delivery Log" admin page.
 *
 * Layout and behavior mirror the analytics page of the Forms Webhook
 * Integrator plugin used elsewhere on this site:
 *  - an API card that toggles the read-only deliveries REST endpoint and
 *    manages its key,
 *  - a retention notice and a toolbar (clear all logs, CSV/JSON export),
 *  - two paginated accordions — Successful Deliveries and Failed
 *    Deliveries — whose entries show the exact JSON payload sent and the
 *    response the endpoint returned.
 *
 * Log lists are populated client-side (assets/js/delivery-log.js) via the
 * spa_get_delivery_logs AJAX action; only row counts are fetched on page
 * load. Log data lives in the {@see DeliveryLog} table.
 */
final class DeliveryLogPage
{
    /** @var string Menu slug for the submenu page. */
    public const MENU_SLUG = 'sitepulse-analytics-deliveries';

    /** @var int Rows fetched per database round-trip while streaming an export. */
    private const EXPORT_CHUNK = 200;

    /**
     * Registers menu, asset, action, and AJAX hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'processClearLogs']);
        add_action('admin_init', [self::class, 'processExport']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);

        add_action('wp_ajax_spa_get_delivery_logs', [self::class, 'handleGetLogsAjax']);
        add_action('wp_ajax_spa_delete_delivery_log', [self::class, 'handleDeleteLogAjax']);
        add_action('wp_ajax_spa_toggle_delivery_api', [self::class, 'handleApiToggleAjax']);
        add_action('wp_ajax_spa_regen_delivery_api_key', [self::class, 'handleApiRegenKeyAjax']);
    }

    /**
     * Adds the Delivery Log submenu under the SitePulse top-level menu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            DashboardPage::MENU_SLUG,
            'SitePulse Webhook Delivery Log',
            'Delivery Log',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the page's script on this page only. The shared stylesheet is
     * enqueued by DashboardPage for all plugin pages.
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
            'spa-delivery-log',
            SPA_PLUGIN_URL . 'assets/js/delivery-log.js',
            [],
            SPA_VERSION,
            true
        );

        wp_localize_script('spa-delivery-log', 'SPA_LOG', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'logsNonce'      => wp_create_nonce('spa_get_delivery_logs'),
            'deleteNonce'    => wp_create_nonce('spa_delete_delivery_log'),
            'apiToggleNonce' => wp_create_nonce('spa_toggle_delivery_api'),
            'apiRegenNonce'  => wp_create_nonce('spa_regen_delivery_api_key'),
        ]);
    }

    /**
     * Clears all stored delivery logs if a valid nonce-protected POST is
     * detected, then redirects back with a notice flag.
     *
     * @return void
     */
    public static function processClearLogs(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !isset($_POST['spa_action']) ||
            $_POST['spa_action'] !== 'clear_delivery_logs' ||
            !isset($_POST['spa_clear_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['spa_clear_nonce'])), 'spa_clear_delivery_logs') ||
            !current_user_can('manage_options')
        ) {
            return;
        }

        DeliveryLog::clearLogs();

        wp_safe_redirect(
            add_query_arg(['page' => self::MENU_SLUG, 'spa_cleared' => '1'], self_admin_url('admin.php'))
        );
        exit;
    }

    /**
     * Streams a CSV or JSON file download when a valid export link is followed.
     *
     * @return void
     */
    public static function processExport(): void
    {
        if (!isset($_GET['spa_export']) || !current_user_can('manage_options')) {
            return;
        }

        // Only act on this plugin's page so the shared query var can never
        // hijack another admin screen.
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }

        $type = sanitize_key((string) $_GET['spa_export']);
        if ($type !== 'csv' && $type !== 'json') {
            return;
        }

        if (
            !isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'spa_export_' . $type)
        ) {
            wp_die('Invalid or expired export link.');
        }

        $type === 'csv' ? self::exportCsv() : self::exportJson();
    }

    /**
     * Handles the spa_get_delivery_logs AJAX action.
     *
     * Accepts page, per_page, status, search, filter_year, filter_month, and
     * endpoint from POST. Returns JSON with rendered log-item HTML, total row
     * count, total pages, current page, and the distinct year/month/endpoint
     * arrays for the filter dropdowns.
     *
     * @return never
     */
    public static function handleGetLogsAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'spa_get_delivery_logs') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $page        = max(1, (int) ($_POST['page'] ?? 1));
        $perPage     = min(100, max(5, (int) ($_POST['per_page'] ?? 10)));
        $status      = sanitize_key((string) ($_POST['status'] ?? ''));
        $status      = in_array($status, ['success', 'error'], true) ? $status : '';
        $search      = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));
        $filterYear  = sanitize_text_field(wp_unslash($_POST['filter_year'] ?? ''));
        $filterMonth = sanitize_text_field(wp_unslash($_POST['filter_month'] ?? ''));
        $endpoint    = esc_url_raw(wp_unslash($_POST['endpoint'] ?? ''));

        $logs  = DeliveryLog::getLogsPaginated($page, $perPage, $status, $filterYear, $filterMonth, $search, $endpoint);
        $total = DeliveryLog::getLogCount($status, $filterYear, $filterMonth, $search, $endpoint);
        $dates = DeliveryLog::getDistinctDates($status, $endpoint);

        $html = '';
        foreach ($logs as $entry) {
            $html .= self::renderLogEntryHtml($entry);
        }

        wp_send_json_success([
            'html'        => $html,
            'total'       => $total,
            'totalPages'  => max(1, (int) ceil($total / $perPage)),
            'currentPage' => $page,
            'years'       => $dates['years'],
            'months'      => $dates['months'],
            'endpoints'   => DeliveryLog::getDistinctEndpoints(),
        ]);
    }

    /**
     * AJAX handler that deletes a single log entry.
     *
     * @return never
     */
    public static function handleDeleteLogAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'spa_delete_delivery_log') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $id = (int) ($_POST['log_id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid log id.']);
        }

        DeliveryLog::deleteLog($id);
        wp_send_json_success();
    }

    /**
     * AJAX handler that toggles the deliveries API on or off.
     *
     * When turning ON for the first time, generates an API key if none
     * exists and returns the raw key — the only time it is ever sent; the
     * server stores just its hash. Later toggles return an empty key and
     * the UI keeps showing the masked placeholder.
     *
     * @return never
     */
    public static function handleApiToggleAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'spa_toggle_delivery_api') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $active = isset($_POST['active']) && $_POST['active'] === '1';
        DeliveryLogApi::setActive($active);

        $key = '';
        if ($active && !DeliveryLogApi::hasKey()) {
            $key = DeliveryLogApi::generateKey();
        }

        wp_send_json_success(['active' => $active, 'key' => $key]);
    }

    /**
     * AJAX handler that generates a new deliveries API key and returns it.
     *
     * @return never
     */
    public static function handleApiRegenKeyAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'spa_regen_delivery_api_key') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        wp_send_json_success(['key' => DeliveryLogApi::generateKey()]);
    }

    /**
     * Renders the full Delivery Log page HTML.
     *
     * Only row counts are fetched on page load; log entries load lazily into
     * each accordion when it is first opened.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $cleared     = isset($_GET['spa_cleared']) && $_GET['spa_cleared'] === '1';
        $totalOk     = DeliveryLog::getLogCount('success');
        $totalErrors = DeliveryLog::getLogCount('error');
        $totalAll    = $totalOk + $totalErrors;
        $apiActive   = DeliveryLogApi::isActive();
        $hasKey      = DeliveryLogApi::hasKey();

        ?>
        <div class="wrap spa-wrap spa-delivery-wrap">
            <h1>Webhook Delivery Log</h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible"><p>All delivery logs have been cleared.</p></div>
            <?php endif; ?>

            <!-- ── Deliveries API Card ────────────────────────────────────── -->
            <div class="spa-card spa-delivery-api-card">
                <h2 class="spa-card-title">Deliveries API</h2>
                <p class="description" style="margin-bottom:12px;">
                    Enable a read-only REST endpoint that returns all delivery log data as JSON.
                    Pass the API key as the <code>Authorization</code> header on every request.
                    This API is intended for <strong>server-to-server</strong> use — never embed the key in public frontend JavaScript, where any visitor could read it.
                </p>

                <div class="spa-toggle-row">
                    <label class="spa-toggle" for="spa-delivery-api-toggle" aria-label="Toggle Deliveries API active state">
                        <input
                            type="checkbox"
                            id="spa-delivery-api-toggle"
                            <?php checked($apiActive, true); ?>
                        >
                        <span class="spa-toggle-slider" aria-hidden="true"></span>
                    </label>
                    <span class="spa-toggle-label" id="spa-api-toggle-label">
                        <?php echo $apiActive ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>

                <div id="spa-api-key-section"<?php echo $apiActive ? '' : ' hidden'; ?>>
                    <table class="form-table" role="presentation" style="margin-top:12px;">
                        <tr>
                            <th scope="row">Endpoint</th>
                            <td>
                                <code><?php echo esc_url(rest_url('sitepulse/v1/deliveries')); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Key</th>
                            <td>
                                <div class="spa-api-key-row">
                                    <code id="spa-api-key-value" data-masked="1"><?php echo $hasKey ? '••••••••••••••••••••' : '(no key generated yet)'; ?></code>
                                    <button type="button" class="button spa-copy-key-btn" hidden>Copy</button>
                                    <button type="button" class="button spa-regen-key-btn">Regenerate</button>
                                </div>
                                <p class="description" style="margin-top:6px;">
                                    Only a hash of the key is stored, so the key is shown <strong>once</strong> — right after it is generated. Copy it then; if it is lost, regenerate a new one (any integrations using the old key stop working).
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="notice notice-info inline spa-retention-notice">
                <p>
                    <strong>Log retention policy:</strong>
                    Entries older than <?php echo esc_html((string) Options::retentionDays()); ?> days are automatically removed daily.
                    The retention period is shared with the analytics data and can be changed under <strong>Settings</strong>.
                </p>
            </div>

            <div class="spa-delivery-toolbar">
                <form method="post" action="" class="spa-clear-form">
                    <?php wp_nonce_field('spa_clear_delivery_logs', 'spa_clear_nonce'); ?>
                    <input type="hidden" name="spa_action" value="clear_delivery_logs">
                    <button
                        type="submit"
                        class="button button-secondary spa-btn-danger"
                        onclick="return confirm('Are you sure you want to clear all delivery logs? This cannot be undone.');"
                    >
                        Clear All Logs
                    </button>
                </form>

                <?php if ($totalAll > 0): ?>
                    <div class="spa-export-buttons">
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => self::MENU_SLUG, 'spa_export' => 'csv'], self_admin_url('admin.php')), 'spa_export_csv')); ?>"
                            class="button button-secondary"
                        >
                            Export All To CSV
                        </a>
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => self::MENU_SLUG, 'spa_export' => 'json'], self_admin_url('admin.php')), 'spa_export_json')); ?>"
                            class="button button-secondary"
                        >
                            Export All To JSON
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Successful Deliveries Accordion ─────────────────────────── -->
            <div class="spa-accordion">
                <button type="button" class="spa-accordion-header" aria-expanded="false" aria-controls="spa-acc-success">
                    <span class="spa-accordion-title">Successful Deliveries</span>
                    <span class="spa-badge"><?php echo esc_html((string) $totalOk); ?></span>
                    <span class="spa-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="spa-accordion-body" id="spa-acc-success" data-status="success" hidden>
                    <!-- Log list injected by delivery-log.js via spa_get_delivery_logs AJAX -->
                </div>
            </div>

            <!-- ── Failed Deliveries Accordion ─────────────────────────────── -->
            <div class="spa-accordion">
                <button type="button" class="spa-accordion-header" aria-expanded="false" aria-controls="spa-acc-errors">
                    <span class="spa-accordion-title">Failed Deliveries</span>
                    <span class="spa-badge spa-badge-error"><?php echo esc_html((string) $totalErrors); ?></span>
                    <span class="spa-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="spa-accordion-body" id="spa-acc-errors" data-status="error" hidden>
                    <!-- Log list injected by delivery-log.js via spa_get_delivery_logs AJAX -->
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Renders a single log row as an HTML list-item string.
     *
     * @param array<string, mixed> $entry A single DeliveryLog row.
     * @return string
     */
    private static function renderLogEntryHtml(array $entry): string
    {
        $isError     = (int) ($entry['success'] ?? 0) === 0;
        $itemClass   = $isError ? 'spa-log-error' : 'spa-log-success';
        $statusText  = $isError ? 'Error' : 'Success';
        $statusClass = $isError ? 'error' : 'success';
        $timestamp   = (string) ($entry['created_at'] ?? '');
        $endpoint    = (string) ($entry['endpoint_url'] ?? '');
        $code        = (int) ($entry['response_code'] ?? 0);
        $rawResponse = (string) ($entry['response_data'] ?? '');
        $deliveryId  = (string) ($entry['delivery_id'] ?? '');
        $attempt     = (int) ($entry['attempt'] ?? 0);
        $label       = $endpoint !== '' ? Options::webhookLabel($endpoint) : '';

        $kindLabel = match ((string) ($entry['kind'] ?? '')) {
            'test'  => 'Test',
            'retry' => sprintf('Retry %d/%d', $attempt, WebhookDispatcher::maxRetries()),
            default => 'Scheduled',
        };

        $requestDecoded = json_decode((string) ($entry['request_data'] ?? '{}'), true);
        $prettyRequest  = is_array($requestDecoded)
            ? json_encode($requestDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) ($entry['request_data'] ?? '');

        // Transport errors are stored as {"error": "..."} with response code 0.
        $responseDecoded = json_decode($rawResponse, true);
        $errorMessage    = ($code === 0 && is_array($responseDecoded))
            ? (string) ($responseDecoded['error'] ?? '')
            : '';

        ob_start();
        ?>
        <li class="spa-log-item <?php echo esc_attr($itemClass); ?>" data-log-id="<?php echo esc_attr((string) ($entry['id'] ?? '')); ?>">

            <div class="spa-log-meta">
                <span class="spa-log-time"><?php echo esc_html($timestamp); ?> UTC</span>
                <?php if ($label !== ''): ?>
                    <span class="spa-log-webhook-label"><?php echo esc_html($label); ?></span>
                <?php endif; ?>
                <span class="spa-log-kind-label"><?php echo esc_html($kindLabel); ?></span>
                <span class="spa-log-status <?php echo esc_attr($statusClass); ?>">
                    <?php echo esc_html($statusText); ?>
                    <?php if ($code !== 0): ?>
                        (<?php echo esc_html((string) $code); ?>)
                    <?php endif; ?>
                </span>
                <button type="button" class="button spa-log-delete-btn" aria-label="Delete log entry <?php echo esc_attr((string) ($entry['id'] ?? '')); ?>">Delete</button>
            </div>

            <?php if ($endpoint !== ''): ?>
                <div class="spa-log-url">
                    <strong>Endpoint:</strong> <code><?php echo esc_html($endpoint); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($deliveryId !== ''): ?>
                <div class="spa-log-url">
                    <strong>Delivery ID:</strong> <code><?php echo esc_html($deliveryId); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="spa-log-error-msg">
                    <strong>Error:</strong> <?php echo esc_html($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="spa-log-data">
                <strong>Payload:</strong>
                <pre><?php echo esc_html((string) $prettyRequest); ?></pre>
            </div>

            <?php if ($rawResponse !== '' && $errorMessage === ''): ?>
                <div class="spa-log-response">
                    <strong>Response:</strong>
                    <pre><?php echo esc_html($rawResponse); ?></pre>
                </div>
            <?php endif; ?>

        </li>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Streams all log rows as a UTF-8 CSV file and exits.
     *
     * Rows are fetched in keyset-paginated chunks and written as they
     * arrive — with two potentially-64 KB bodies per row, loading the whole
     * table into memory could exhaust PHP on a long-retention site.
     *
     * @return never
     */
    private static function exportCsv(): never
    {
        $filename = 'sitepulse-delivery-log-' . gmdate('Y-m-d') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, ['ID', 'Date/Time (UTC)', 'Success', 'Kind', 'Attempt', 'Endpoint', 'Webhook Label', 'Delivery ID', 'Response Code', 'Payload', 'Response']);

        $beforeId = PHP_INT_MAX;

        do {
            $logs = DeliveryLog::getLogsChunk($beforeId, self::EXPORT_CHUNK);

            foreach ($logs as $entry) {
                $endpoint = (string) ($entry['endpoint_url'] ?? '');
                $beforeId = (int) ($entry['id'] ?? 0);

                fputcsv($output, [
                    (int) ($entry['id'] ?? 0),
                    self::escapeCsvCell((string) ($entry['created_at'] ?? '')),
                    (int) ($entry['success'] ?? 0) === 1 ? 'Yes' : 'No',
                    self::escapeCsvCell((string) ($entry['kind'] ?? '')),
                    (int) ($entry['attempt'] ?? 0),
                    self::escapeCsvCell($endpoint),
                    self::escapeCsvCell($endpoint !== '' ? Options::webhookLabel($endpoint) : ''),
                    self::escapeCsvCell((string) ($entry['delivery_id'] ?? '')),
                    (int) ($entry['response_code'] ?? 0),
                    self::escapeCsvCell((string) ($entry['request_data'] ?? '')),
                    self::escapeCsvCell((string) ($entry['response_data'] ?? '')),
                ]);
            }
        } while (count($logs) === self::EXPORT_CHUNK);

        fclose($output);
        exit;
    }

    /**
     * Prefixes spreadsheet-formula trigger characters so Excel/Sheets treat
     * the value as text rather than a formula.
     *
     * @param string $value Raw cell value.
     * @return string
     */
    private static function escapeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "\t" . $value;
        }

        return $value;
    }

    /**
     * Streams all log rows as a pretty-printed JSON array and exits.
     *
     * The array is streamed structurally — an opening bracket, each entry
     * encoded and emitted individually, then a closing bracket — so memory
     * use stays bounded regardless of table size. The output is still one
     * valid JSON document.
     *
     * @return never
     */
    private static function exportJson(): never
    {
        $filename = 'sitepulse-delivery-log-' . gmdate('Y-m-d') . '.json';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "[\n";

        $beforeId = PHP_INT_MAX;
        $first    = true;

        do {
            $logs = DeliveryLog::getLogsChunk($beforeId, self::EXPORT_CHUNK);

            foreach ($logs as $entry) {
                $beforeId = (int) ($entry['id'] ?? 0);

                echo ($first ? '' : ",\n") . wp_json_encode(
                    DeliveryLogApi::formatEntry($entry),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                $first = false;
            }
        } while (count($logs) === self::EXPORT_CHUNK);

        echo "\n]";
        exit;
    }
}
