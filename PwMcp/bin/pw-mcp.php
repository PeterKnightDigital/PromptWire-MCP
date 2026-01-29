#!/usr/bin/env php
<?php
/**
 * PW-MCP CLI Entrypoint
 * 
 * Bootstraps ProcessWire and routes commands to appropriate handlers.
 * All output is JSON unless --format=yaml is specified.
 */

// Get ProcessWire installation path from environment
$pwPath = getenv('PW_PATH');

if (!$pwPath) {
    outputError('PW_PATH environment variable not set');
}

$pwPath = rtrim($pwPath, '/');
$indexPath = $pwPath . '/index.php';

if (!file_exists($indexPath)) {
    outputError("ProcessWire not found at: $pwPath");
}

// Bootstrap ProcessWire
chdir($pwPath);
require_once($indexPath);

// Now ProcessWire is loaded - get wire() function
$wire = wire();

if (!$wire) {
    outputError('Failed to bootstrap ProcessWire');
}

// Parse command and flags
$command = $argv[1] ?? 'help';
$flags = parseFlags(array_slice($argv, 2));

// Include the command router
require_once(__DIR__ . '/../src/Cli/CommandRouter.php');

// Route and execute
$router = new \PwMcp\Cli\CommandRouter($wire);
$result = $router->run($command, $flags);

// Output result
outputResult($result, $flags);

/**
 * Parse CLI flags into associative array
 */
function parseFlags(array $args): array {
    $flags = [
        'format' => 'json',
        'pretty' => false,
        'include' => [],
    ];
    
    $positional = [];
    
    foreach ($args as $arg) {
        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', $arg, 2);
                if ($key === 'include') {
                    $flags['include'][] = $value;
                } else {
                    $flags[$key] = $value;
                }
            } else {
                $flags[$arg] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }
    
    $flags['_positional'] = $positional;
    
    return $flags;
}

/**
 * Output result as JSON or YAML
 */
function outputResult(array $result, array $flags): void {
    $format = $flags['format'] ?? 'json';
    $pretty = !empty($flags['pretty']);
    
    if ($format === 'yaml') {
        // For YAML, we need the yaml extension or a simple converter
        if (function_exists('yaml_emit')) {
            echo yaml_emit($result, YAML_UTF8_ENCODING);
        } else {
            // Fallback: simple YAML-like output
            echo arrayToYaml($result);
        }
    } else {
        $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }
        echo json_encode($result, $options) . "\n";
    }
}

/**
 * Simple array to YAML converter (fallback)
 */
function arrayToYaml(array $data, int $indent = 0): string {
    $yaml = '';
    $prefix = str_repeat('  ', $indent);
    
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (empty($value)) {
                $yaml .= "$prefix$key: []\n";
            } elseif (array_keys($value) === range(0, count($value) - 1)) {
                // Indexed array
                $yaml .= "$prefix$key:\n";
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $yaml .= "$prefix  -\n" . arrayToYaml($item, $indent + 2);
                    } else {
                        $yaml .= "$prefix  - " . formatYamlValue($item) . "\n";
                    }
                }
            } else {
                // Associative array
                $yaml .= "$prefix$key:\n" . arrayToYaml($value, $indent + 1);
            }
        } else {
            $yaml .= "$prefix$key: " . formatYamlValue($value) . "\n";
        }
    }
    
    return $yaml;
}

/**
 * Format a value for YAML output
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
        if (preg_match('/[\n\r\t:#{}\[\]&*!|>\'"%@`]/', $value) || $value === '') {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
    return (string) $value;
}

/**
 * Output error and exit
 */
function outputError(string $message): void {
    echo json_encode(['error' => $message]) . "\n";
    exit(1);
}
