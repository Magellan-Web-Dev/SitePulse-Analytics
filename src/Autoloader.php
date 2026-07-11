<?php
namespace SitePulseAnalytics;

if (!defined('ABSPATH')) { exit; }

/**
 * Minimal PSR-4 autoloader.
 *
 * Maps one or more namespace prefixes to filesystem directories and
 * registers itself with spl_autoload_register so that classes in the
 * SitePulseAnalytics namespace are loaded on demand without Composer.
 */
final class Autoloader
{
    /**
     * Registered namespace prefix → base directory mappings.
     *
     * Keys are namespace prefixes with a trailing backslash (e.g. "SitePulseAnalytics\").
     * Values are absolute directory paths with a trailing directory separator.
     *
     * @var array<string, string>
     */
    private array $prefixes = [];

    /**
     * Registers a namespace prefix with its corresponding base directory.
     *
     * The prefix is normalized to include a trailing backslash and the
     * directory is normalized to include a trailing directory separator,
     * so callers do not need to worry about trailing characters.
     *
     * @param string $prefix  The namespace prefix (e.g. "SitePulseAnalytics").
     * @param string $baseDir Absolute path to the directory that contains the
     *                        classes for this prefix (e.g. "/path/to/plugin/src").
     * @return void
     */
    public function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->prefixes[$prefix] = $baseDir;
    }

    /**
     * Static factory that boots the autoloader in a single call.
     *
     * Creates an instance, registers the 'SitePulseAnalytics' namespace against
     * `{$pluginDir}src`, and activates it. Intended for use in the main plugin
     * file so the entire autoloader setup fits on one line.
     *
     * @param string $pluginDir Absolute path to the plugin root directory,
     *                          with or without a trailing separator.
     * @return void
     */
    public static function boot(string $pluginDir): void
    {
        $instance = new self();
        $instance->addNamespace('SitePulseAnalytics', rtrim($pluginDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src');
        $instance->register();
    }

    /**
     * Activates the autoloader by registering it with spl_autoload_register.
     *
     * After this call, PHP will invoke {@see self::autoload()} whenever an
     * undefined class is referenced. Uses the PHP 8.1 first-class callable
     * syntax so the closure is bound to this instance without an explicit array.
     *
     * @return void
     */
    public function register(): void
    {
        spl_autoload_register($this->autoload(...));
    }

    /**
     * Resolves a fully-qualified class name to a file path and includes it.
     *
     * Iterates over all registered prefixes. When the class name starts with a
     * known prefix the remaining relative class path is converted to a file path
     * (backslashes → directory separators, ".php" appended). The file is
     * included only if it is readable; unknown classes are silently ignored so
     * that other registered autoloaders can handle them.
     *
     * @param string $class Fully-qualified class name (e.g. "SitePulseAnalytics\Admin\DashboardPage").
     * @return void
     */
    private function autoload(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_readable($file)) {
                require_once $file;
            }
        }
    }
}
