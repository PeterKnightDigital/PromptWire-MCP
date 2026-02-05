#!/usr/bin/env php
<?php
/**
 * PW-MCP CLI Entrypoint
 * 
 * This script bootstraps ProcessWire in CLI mode and routes commands
 * to the appropriate handlers. It serves as the bridge between the
 * Node.js MCP server and ProcessWire's PHP API.
 * 
 * Usage:
 *   php pw-mcp.php <command> [arguments] [--flags]
 * 
 * Examples:
 *   php pw-mcp.php health --pretty
 *   php pw-mcp.php list-templates
 *   php pw-mcp.php get-page /about/ --include=files
 *   php pw-mcp.php export-schema --format=yaml
 * 
 * Environment Variables:
 *   PW_PATH - Required. Path to ProcessWire installation root.
 * 
 * Output:
 *   All output is JSON by default. Use --format=yaml for YAML output
 *   (only supported for export-schema). Use --pretty for formatted JSON.
 * 
 * @package     PwMcp
 * @author      Peter Knight
 * @license     MIT
 */

namespace ProcessWire;

// ============================================================================
// SUPPRESS DEPRECATION WARNINGS
// ============================================================================
// ProcessWire's older code triggers PHP 8.2+ deprecation notices which
// pollute stdout and break JSON parsing. Suppress them here.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ============================================================================
// BOOTSTRAP PROCESSWIRE
// ============================================================================

// Get ProcessWire installation path from environment variable
$pwPath = getenv('PW_PATH');

if (!$pwPath) {
    outputError('PW_PATH environment variable not set');
}

$pwPath = rtrim($pwPath, '/');

// Verify ProcessWire exists at the specified path
if (!file_exists($pwPath . '/wire/core/ProcessWire.php')) {
    outputError("ProcessWire not found at: $pwPath");
}

// Define ProcessWire constant (matches index.php version)
if (!defined("PROCESSWIRE")) define("PROCESSWIRE", 300);

// Normalize path separators for cross-platform compatibility
$rootPath = $pwPath;
if (DIRECTORY_SEPARATOR != '/') {
    $rootPath = str_replace(DIRECTORY_SEPARATOR, '/', $rootPath);
}

// Load Composer autoloader if present (for third-party dependencies)
$composerAutoloader = $rootPath . '/vendor/autoload.php';
if (file_exists($composerAutoloader)) {
    require_once($composerAutoloader);
}

// Load ProcessWire core
require_once("$rootPath/wire/core/ProcessWire.php");

// Build ProcessWire configuration
$config = ProcessWire::buildConfig($rootPath);

if (!$config->dbName) {
    outputError('ProcessWire database not configured');
}

// Create ProcessWire instance
// Note: Unlike web requests, we don't execute ProcessPageView - 
// we just need access to the API ($pages, $templates, $fields, etc.)
$wire = new ProcessWire($config);

if (!$wire) {
    outputError('Failed to bootstrap ProcessWire');
}

// ============================================================================
// PARSE ARGUMENTS AND ROUTE COMMAND
// ============================================================================

// First argument is the command, rest are flags/arguments
$command = $argv[1] ?? 'help';
$flags = parseFlags(array_slice($argv, 2));

// Load and instantiate the command router
require_once(__DIR__ . '/../src/Cli/CommandRouter.php');
$router = new \PwMcp\Cli\CommandRouter($wire);

// Execute the command and get result
$result = $router->run($command, $flags);

// Output the result in the requested format
outputResult($result, $flags);

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Parse CLI flags into an associative array
 * 
 * Handles both flag formats:
 *   --flag=value  -> ['flag' => 'value']
 *   --flag        -> ['flag' => true]
 * 
 * The special --include flag can be specified multiple times:
 *   --include=usage --include=files -> ['include' => ['usage', 'files']]
 * 
 * Non-flag arguments are stored in '_positional' array.
 * 
 * @param array $args Command line arguments (excluding script and command)
 * @return array Parsed flags with defaults
 */
function parseFlags(array $args): array {
    // Default flag values
    $flags = [
        'format' => 'json',      // Output format: json or yaml
        'pretty' => false,       // Pretty-print JSON output
        'include' => [],         // Additional data to include (usage, files)
    ];
    
    // Positional arguments (not flags)
    $positional = [];
    
    foreach ($args as $arg) {
        if (strpos($arg, '--') === 0) {
            // Remove the leading --
            $arg = substr($arg, 2);
            
            if (strpos($arg, '=') !== false) {
                // Handle --key=value format
                [$key, $value] = explode('=', $arg, 2);
                
                // Special handling for --include (can be specified multiple times)
                if ($key === 'include') {
                    $flags['include'][] = $value;
                } else {
                    $flags[$key] = $value;
                }
            } else {
                // Handle --flag format (boolean true)
                $flags[$arg] = true;
            }
        } else {
            // Not a flag - store as positional argument
            $positional[] = $arg;
        }
    }
    
    // Store positional arguments for command handlers
    $flags['_positional'] = $positional;
    
    return $flags;
}

/**
 * Output result in the requested format (JSON or YAML)
 * 
 * @param array $result Result data from command handler
 * @param array $flags  Parsed CLI flags
 */
function outputResult(array $result, array $flags): void {
    $format = $flags['format'] ?? 'json';
    $pretty = !empty($flags['pretty']);
    
    if ($format === 'yaml') {
        // Use PHP's yaml extension if available
        if (function_exists('yaml_emit')) {
            echo yaml_emit($result, YAML_UTF8_ENCODING);
        } else {
            // Fallback: use our simple YAML converter
            echo arrayToYaml($result);
        }
    } else {
        // JSON output (default)
        $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }
        echo json_encode($result, $options) . "\n";
    }
}

/**
 * Convert a PHP array to YAML format
 * 
 * This is a simple fallback for when the yaml PHP extension
 * is not installed. It handles nested arrays and common data types.
 * 
 * @param array $data   Array to convert
 * @param int   $indent Current indentation level
 * @return string YAML formatted string
 */
function arrayToYaml(array $data, int $indent = 0): string {
    $yaml = '';
    $prefix = str_repeat('  ', $indent);
    
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (empty($value)) {
                // Empty array
                $yaml .= "$prefix$key: []\n";
            } elseif (array_keys($value) === range(0, count($value) - 1)) {
                // Indexed (sequential) array - use list format
                $yaml .= "$prefix$key:\n";
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $yaml .= "$prefix  -\n" . arrayToYaml($item, $indent + 2);
                    } else {
                        $yaml .= "$prefix  - " . formatYamlValue($item) . "\n";
                    }
                }
            } else {
                // Associative array - use nested format
                $yaml .= "$prefix$key:\n" . arrayToYaml($value, $indent + 1);
            }
        } else {
            // Scalar value
            $yaml .= "$prefix$key: " . formatYamlValue($value) . "\n";
        }
    }
    
    return $yaml;
}

/**
 * Format a scalar value for YAML output
 * 
 * Handles null, booleans, numbers, and strings (with proper quoting
 * when the string contains special characters).
 * 
 * @param mixed $value Value to format
 * @return string YAML-formatted value
 */
function formatYamlValue($value): string {
    if (is_null($value)) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_numeric($value)) {
        return (string) $value;
    }
    if (is_string($value)) {
        // Quote strings that contain special YAML characters
        if (preg_match('/[\n\r\t:#{}\[\]&*!|>\'"%@`]/', $value) || $value === '') {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
    return (string) $value;
}

/**
 * Output an error message as JSON and exit
 * 
 * @param string $message Error message
 */
function outputError(string $message): void {
    echo json_encode(['error' => $message]) . "\n";
    exit(1);
}
