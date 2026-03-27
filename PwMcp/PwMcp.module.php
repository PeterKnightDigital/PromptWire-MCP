<?php namespace ProcessWire;

/**
 * PW-MCP: ProcessWire ↔ Cursor MCP Bridge
 * 
 * This module serves as the ProcessWire component of the PW-MCP bridge,
 * exposing ProcessWire's structure and content to Cursor IDE via the
 * Model Context Protocol (MCP).
 * 
 * Provides full read/write access: site inspection, content sync (pull/push),
 * file sync, schema sync, page management, and cross-environment deployment.
 * 
 * The module itself is minimal — most functionality is provided via
 * the CLI interface (bin/pw-mcp.php) which can be invoked by the
 * MCP server running in Node.js.
 * 
 * @package     PwMcp
 * @author      Peter Knight
 * @license     MIT
 * @version     1.3.0
 * @link        https://github.com/peterknight/pw-mcp
 * 
 * @see         /bin/pw-mcp.php          CLI entrypoint
 * @see         /src/Cli/CommandRouter   Command routing and execution
 * @see         /src/Schema/*            Schema export classes
 * @see         /src/Query/*             Page query classes
 */
class PwMcp extends WireData implements Module {

    /**
     * Provide module information to ProcessWire
     * 
     * This static method is required by all ProcessWire modules.
     * It tells ProcessWire about the module's title, version,
     * requirements, and behavior.
     * 
     * Note: autoload is false because this module is only invoked
     * via CLI - it doesn't need to run on every page request.
     * 
     * @return array Module information array
     */
    public static function getModuleInfo(): array {
        return [
            'title' => 'PW-MCP',
            'summary' => 'ProcessWire ↔ Cursor MCP Bridge for AI-assisted development',
            'version' => '1.3.0',
            'author' => 'Peter Knight',
            'href' => 'https://github.com/peterknight/pw-mcp',
            'singular' => true,
            'autoload' => false,  // CLI-invoked only, not needed on web requests
            'icon' => 'plug',
            'requires' => 'ProcessWire>=3.0.0',
        ];
    }

    /**
     * Get the current ProcessWire version
     * 
     * @return string ProcessWire version string (e.g., "3.0.241")
     */
    public function getPwVersion(): string {
        return $this->wire('config')->version;
    }

    /**
     * Get the site name from configuration
     * 
     * Returns the HTTP host if configured, otherwise falls back
     * to a generic name.
     * 
     * @return string Site name or host
     */
    public function getSiteName(): string {
        return $this->wire('config')->httpHost ?: 'ProcessWire Site';
    }

    /**
     * Check if the module is properly loaded
     * 
     * This method exists for health check purposes - if you can
     * call this method and get true, the module is working.
     * 
     * @return bool Always returns true if module is loaded
     */
    public function isLoaded(): bool {
        return true;
    }

    /**
     * Get counts of main ProcessWire objects for health check
     * 
     * Returns the number of templates, fields, and pages in the site.
     * Used by the health command to verify the connection is working.
     * 
     * @return array Associative array with 'templates', 'fields', 'pages' counts
     */
    public function getCounts(): array {
        return [
            'templates' => $this->wire('templates')->getAll()->count(),
            'fields' => $this->wire('fields')->getAll()->count(),
            'pages' => $this->wire('pages')->count('include=all'),
        ];
    }
}
