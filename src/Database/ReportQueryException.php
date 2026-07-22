<?php
declare(strict_types=1);

namespace SitePulseAnalytics\Database;

if (!defined('ABSPATH')) exit;

/**
 * Thrown when a Reports.php query fails at the database level.
 *
 * $wpdb silently turns a failed query into an empty/false result — this
 * exception is how {@see Reports}'s internal query helpers turn that back
 * into something callers can't mistake for a legitimate zero.
 */
final class ReportQueryException extends \RuntimeException
{
}
