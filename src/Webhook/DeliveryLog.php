<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Webhook;

if (!defined('ABSPATH')) exit;

use SitePulseAnalytics\Settings\Options;

/**
 * Persists and retrieves webhook delivery log entries in a custom table.
 *
 * One row = one delivery attempt (scheduled, retry, or test), storing the
 * exact JSON payload that was sent and the response the endpoint returned —
 * so the Delivery Log admin page and the read-only deliveries REST API can
 * show precisely what left the site and what came back. Both bodies are
 * capped at 64 KB so a misbehaving endpoint cannot balloon the table.
 *
 * Rows older than the plugin's retention window are pruned by the same daily
 * cron that trims the analytics events table.
 */
final class DeliveryLog
{
    /** @var string Table name without the wpdb prefix. */
    private const TABLE = 'spa_webhook_deliveries';

    /** @var string Option key storing the installed schema version. */
    private const DB_VERSION_OPTION = 'spa_delivery_db_version';

    /** @var string Current schema version; bump when the CREATE TABLE below changes. */
    private const DB_VERSION = '1.0.0';

    /** @var int Maximum stored bytes for the request payload and response body. */
    public const MAX_BODY_BYTES = 65536;

    /** @var int Rows deleted per statement during retention cleanup. */
    private const CLEANUP_CHUNK = 5000;

    /** @var int Maximum delete chunks per cron run, so one request can't run indefinitely. */
    private const CLEANUP_MAX_CHUNKS = 20;

    /**
     * Substrings of JSON keys whose values are redacted from stored response
     * bodies. Endpoint responses sometimes echo debugging data or secrets;
     * the log must not become a credential store.
     *
     * @var string[]
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'credential', 'private_key',
    ];

    /**
     * Returns the fully-prefixed deliveries table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Creates or updates the deliveries table (idempotent via dbDelta).
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            success TINYINT(1) NOT NULL DEFAULT 0,
            endpoint_url TEXT NOT NULL,
            delivery_id VARCHAR(32) NOT NULL DEFAULT '',
            kind VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 0,
            request_data LONGTEXT NOT NULL,
            response_code INT NOT NULL DEFAULT 0,
            response_data LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY success (success),
            KEY created_at (created_at),
            KEY endpoint_url (endpoint_url(100))
        ) {$charset};";

        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Creates the table when the stored schema version differs, so plugin
     * updates apply the schema without a re-activation.
     *
     * @return void
     */
    public static function maybeCreateTable(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::createTable();
        }
    }

    /**
     * Inserts one delivery-attempt row.
     *
     * Transport errors (no HTTP response at all) are stored FWI-style as a
     * JSON object {"error": "..."} in response_data, so the UI and API can
     * distinguish "endpoint said no" from "endpoint unreachable".
     *
     * JSON response bodies pass through {@see redactSensitiveJson()} before
     * storage (values of password/token/secret-style keys become [REDACTED]),
     * and the whole row passes through the 'spa_delivery_log_row' filter —
     * return false from it to skip logging the attempt entirely, or modify
     * the row to apply site-specific redaction.
     *
     * @param string $url          Endpoint URL the payload was sent to.
     * @param bool   $ok           Whether the endpoint returned a 2xx response.
     * @param int    $code         HTTP status code (0 on a transport error).
     * @param string $message      Short human-readable outcome (used for transport errors).
     * @param string $kind         Delivery kind: 'scheduled', 'retry', or 'test'.
     * @param int    $attempt      Retry attempt number (0 when not a retry).
     * @param string $deliveryId   The payload's delivery_id / Idempotency-Key.
     * @param string $requestBody  JSON body that was sent, byte-for-byte.
     * @param string $responseBody Raw response body ('' on transport errors).
     * @return void
     */
    public static function log(
        string $url,
        bool $ok,
        int $code,
        string $message,
        string $kind,
        int $attempt,
        string $deliveryId,
        string $requestBody,
        string $responseBody
    ): void {
        global $wpdb;

        if ($code === 0 && $responseBody === '') {
            $responseBody = (string) wp_json_encode(['error' => $message]);
        }

        $row = [
            'success'       => $ok ? 1 : 0,
            'endpoint_url'  => $url,
            'delivery_id'   => $deliveryId,
            'kind'          => $kind,
            'attempt'       => max(0, min(255, $attempt)),
            'request_data'  => self::capBody($requestBody),
            'response_code' => $code,
            'response_data' => self::capBody(self::redactSensitiveJson($responseBody)),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ];

        /**
         * Filters a delivery-log row just before it is written.
         *
         * @param array<string, mixed>|false $row The row about to be stored;
         *                                        return false to skip logging
         *                                        this attempt.
         */
        $row = apply_filters('spa_delivery_log_row', $row);
        if (!is_array($row)) {
            return;
        }

        $wpdb->insert(
            self::tableName(),
            $row,
            ['%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Redacts values of sensitive-looking keys in a JSON response body.
     *
     * Only well-formed JSON objects/arrays are rewritten — non-JSON bodies
     * are stored as-is (there is no reliable way to find secrets in free
     * text, and mangling the body would hide what the endpoint actually
     * said). Key matching is case-insensitive substring matching against
     * {@see self::SENSITIVE_KEY_PATTERNS}, applied recursively.
     *
     * @param string $body Raw response body.
     * @return string The body with sensitive values replaced by [REDACTED].
     */
    private static function redactSensitiveJson(string $body): string
    {
        if ($body === '' || ($body[0] !== '{' && $body[0] !== '[')) {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $body;
        }

        $redact = static function (array $data) use (&$redact): array {
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $lower = strtolower($key);
                    foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
                        if (str_contains($lower, $pattern)) {
                            $data[$key] = '[REDACTED]';
                            continue 2;
                        }
                    }
                }
                if (is_array($value)) {
                    $data[$key] = $redact($value);
                }
            }

            return $data;
        };

        return (string) wp_json_encode($redact($decoded));
    }

    /**
     * Returns a single page of delivery rows, newest-first, with optional filters.
     *
     * @param int    $page        1-based page number.
     * @param int    $perPage     Rows per page (clamp in callers).
     * @param string $status      'success', 'error', or '' for all deliveries.
     * @param string $filterYear  Four-digit year string, or '' for all years.
     * @param string $filterMonth Two-digit month string ('01'–'12'), or '' for all months.
     * @param string $search      Substring searched against the payload JSON.
     * @param string $endpoint    Exact endpoint URL, or '' for all endpoints.
     * @return array<int, array<string, mixed>>
     */
    public static function getLogsPaginated(
        int $page = 1,
        int $perPage = 10,
        string $status = '',
        string $filterYear = '',
        string $filterMonth = '',
        string $search = '',
        string $endpoint = ''
    ): array {
        global $wpdb;

        [$where, $values] = self::buildWhereClause($status, $filterYear, $filterMonth, $search, $endpoint);

        $values[] = $perPage;
        $values[] = ($page - 1) * $perPage;

        $sql = 'SELECT * FROM ' . self::tableName() . " {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the total number of rows matching the given filters.
     *
     * @param string $status      'success', 'error', or '' for all deliveries.
     * @param string $filterYear
     * @param string $filterMonth
     * @param string $search
     * @param string $endpoint
     * @return int
     */
    public static function getLogCount(
        string $status = '',
        string $filterYear = '',
        string $filterMonth = '',
        string $search = '',
        string $endpoint = ''
    ): int {
        global $wpdb;

        [$where, $values] = self::buildWhereClause($status, $filterYear, $filterMonth, $search, $endpoint);

        $sql = 'SELECT COUNT(*) FROM ' . self::tableName() . " {$where}";

        return (int) ($values ? $wpdb->get_var($wpdb->prepare($sql, $values)) : $wpdb->get_var($sql));
    }

    /**
     * Returns one keyset-paginated chunk of rows for streaming exports,
     * newest-first.
     *
     * Exports iterate: start with $beforeId = PHP_INT_MAX, then pass the
     * last row's id back in until fewer than $limit rows return. Keyset
     * pagination (WHERE id < x) stays fast on any table size — and loading
     * everything at once, with two LONGTEXT bodies per row, could exhaust
     * PHP memory on a busy site.
     *
     * @param int $beforeId Only rows with an id strictly below this value.
     * @param int $limit    Maximum rows to return.
     * @return array<int, array<string, mixed>>
     */
    public static function getLogsChunk(int $beforeId, int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tableName() . ' WHERE id < %d ORDER BY id DESC LIMIT %d',
                $beforeId,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the distinct calendar years and months that have log entries,
     * for the Delivery Log page's filter dropdowns.
     *
     * @param string $status   'success', 'error', or '' for all deliveries.
     * @param string $endpoint When non-empty, only rows for this endpoint.
     * @return array{years: list<string>, months: list<string>}
     */
    public static function getDistinctDates(string $status = '', string $endpoint = ''): array
    {
        global $wpdb;

        $conditions = [];
        $values     = [];

        if ($status === 'success' || $status === 'error') {
            $conditions[] = 'success = ' . ($status === 'success' ? '1' : '0');
        }
        if ($endpoint !== '') {
            $conditions[] = 'endpoint_url = %s';
            $values[]     = $endpoint;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql   = "SELECT DISTINCT DATE_FORMAT(created_at, '%%Y-%%m') FROM " . self::tableName() . " {$where} ORDER BY 1 DESC";

        // prepare() is required even without filter values to unescape the %%.
        $rows = $wpdb->get_col($values ? $wpdb->prepare($sql, $values) : $wpdb->prepare($sql));

        $years  = [];
        $months = [];

        foreach ((array) $rows as $ym) {
            if (!is_string($ym) || strlen($ym) < 7) {
                continue;
            }
            $y = substr($ym, 0, 4);
            $m = substr($ym, 5, 2);
            if (!in_array($y, $years, true)) {
                $years[] = $y;
            }
            if (!in_array($m, $months, true)) {
                $months[] = $m;
            }
        }

        sort($months);

        return ['years' => $years, 'months' => $months];
    }

    /**
     * Returns the distinct endpoint URLs present in the log, for the endpoint
     * filter dropdown.
     *
     * @return list<string>
     */
    public static function getDistinctEndpoints(): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            'SELECT DISTINCT endpoint_url FROM ' . self::tableName() . " WHERE endpoint_url <> '' ORDER BY endpoint_url ASC"
        );

        return is_array($rows) ? array_values(array_filter($rows, 'is_string')) : [];
    }

    /**
     * Deletes a single log row by its primary key.
     *
     * @param int $id The row ID to delete.
     * @return void
     */
    public static function deleteLog(int $id): void
    {
        global $wpdb;

        $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);
    }

    /**
     * Removes all rows (TRUNCATE also resets the auto-increment counter).
     *
     * @return void
     */
    public static function clearLogs(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::tableName());
    }

    /**
     * Deletes rows older than the plugin's retention window. Runs on the same
     * daily cron as the events-table cleanup.
     *
     * Deletes in bounded chunks (one giant DELETE over LONGTEXT rows can hold
     * locks for seconds), and caps the chunks per run — a huge backlog is
     * worked off across successive daily runs instead of pinning one request.
     *
     * @return void
     */
    public static function purgeOld(): void
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - Options::retentionDays() * DAY_IN_SECONDS);
        $table  = self::tableName();
        $runs   = 0;

        do {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
                    $cutoff,
                    self::CLEANUP_CHUNK
                )
            );
        } while (is_int($deleted) && $deleted === self::CLEANUP_CHUNK && ++$runs < self::CLEANUP_MAX_CHUNKS);
    }

    /**
     * Truncates a body to the storage cap, marking the cut.
     *
     * The limit is enforced in BYTES (plain substr), not characters — the
     * cap exists to bound storage, and mb_substr()'s character counting
     * would let multibyte content exceed it. The marker is budgeted inside
     * the cap, so the returned string never exceeds MAX_BODY_BYTES. A
     * multibyte character sliced at the boundary may be left incomplete;
     * acceptable for a diagnostic log.
     *
     * @param string $body Raw body.
     * @return string At most MAX_BODY_BYTES bytes.
     */
    private static function capBody(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }

        $marker = ' [TRUNCATED]';

        return substr($body, 0, self::MAX_BODY_BYTES - strlen($marker)) . $marker;
    }

    /**
     * Builds a SQL WHERE clause and its ordered values from the filters.
     *
     * @param string $status      'success', 'error', or '' for all deliveries.
     * @param string $filterYear
     * @param string $filterMonth
     * @param string $search
     * @param string $endpoint
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhereClause(
        string $status,
        string $filterYear,
        string $filterMonth,
        string $search,
        string $endpoint
    ): array {
        global $wpdb;

        $conditions = [];
        $values     = [];

        if ($status === 'success' || $status === 'error') {
            $conditions[] = 'success = ' . ($status === 'success' ? '1' : '0');
        }

        if ($filterYear !== '' && ctype_digit($filterYear) && strlen($filterYear) === 4) {
            $conditions[] = 'YEAR(created_at) = %d';
            $values[]     = (int) $filterYear;
        }

        if ($filterMonth !== '' && ctype_digit($filterMonth) && (int) $filterMonth >= 1 && (int) $filterMonth <= 12) {
            $conditions[] = 'MONTH(created_at) = %d';
            $values[]     = (int) $filterMonth;
        }

        if ($search !== '') {
            $conditions[] = 'request_data LIKE %s';
            $values[]     = '%' . $wpdb->esc_like($search) . '%';
        }

        if ($endpoint !== '') {
            $conditions[] = 'endpoint_url = %s';
            $values[]     = $endpoint;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }
}
