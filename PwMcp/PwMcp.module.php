<?php namespace ProcessWire;

/**
 * PW-MCP: ProcessWire ↔ Cursor MCP Bridge
 * 
 * Exposes ProcessWire structure and content to Cursor IDE via MCP.
 * Phase 1: Read-only operations for querying and understanding the site.
 *
 * @author Peter Knight
 * @license MIT
 */
class PwMcp extends WireData implements Module {

    /**
     * Module information
     */
    public static function getModuleInfo() {
        return [
            'title' => 'PW-MCP',
            'summary' => 'ProcessWire ↔ Cursor MCP Bridge for AI-assisted development',
            'version' => '0.1.0',
            'author' => 'Peter Knight',
            'href' => 'https://github.com/peterknight/pw-mcp',
            'singular' => true,
            'autoload' => false,  // CLI-invoked only
            'icon' => 'plug',
            'requires' => 'ProcessWire>=3.0.0',
        ];
    }

    /**
     * Get ProcessWire version
     */
    public function getPwVersion(): string {
        return $this->wire('config')->version;
    }

    /**
     * Get site name from config
     */
    public function getSiteName(): string {
        return $this->wire('config')->httpHost ?: 'ProcessWire Site';
    }

    /**
     * Check if module is properly loaded
     */
    public function isLoaded(): bool {
        return true;
    }

    /**
     * Get counts for health check
     */
    public function getCounts(): array {
        return [
            'templates' => $this->wire('templates')->count(),
            'fields' => $this->wire('fields')->count(),
            'pages' => $this->wire('pages')->count('include=all'),
        ];
    }
}
