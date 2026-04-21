<?php namespace ProcessWire;

/**
 * PromptWire: ProcessWire ↔ Cursor MCP Bridge
 * 
 * This module serves as the ProcessWire component of the PromptWire bridge,
 * exposing ProcessWire's structure and content to Cursor IDE via the
 * Model Context Protocol (MCP).
 * 
 * Provides full read/write access: site inspection, content sync (pull/push),
 * file sync, schema sync, page management, and cross-environment deployment.
 * 
 * The module itself is minimal — most functionality is provided via
 * the CLI interface (bin/promptwire.php) which can be invoked by the
 * MCP server running in Node.js.
 * 
 * @package     PromptWire
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 * @version     1.5.1
 * @link        https://github.com/PeterKnightDigital/PromptWire-MCP
 * 
 * @see         /bin/promptwire.php      CLI entrypoint
 * @see         /src/Cli/CommandRouter   Command routing and execution
 * @see         /src/Schema/*            Schema export classes
 * @see         /src/Query/*             Page query classes
 */
class PromptWire extends WireData implements Module {

    public static function getModuleInfo(): array {
        return [
            'title' => 'PromptWire',
            'summary' => 'ProcessWire ↔ Cursor MCP Bridge for AI-assisted development',
            'version' => '1.5.1',
            'author' => 'Peter Knight',
            'href' => 'https://github.com/PeterKnightDigital/PromptWire-MCP',
            'singular' => true,
            'autoload' => false,
            'icon' => 'plug',
            'requires' => 'ProcessWire>=3.0.0',
            'installs' => ['ProcessPromptWireAdmin'],
        ];
    }

    public function ___install() {
        $this->cleanupLegacyStructure();
        $this->installAdminModule();
    }

    public function ___upgrade($fromVersion, $toVersion) {
        $this->cleanupLegacyStructure();
        $this->installAdminModule();
    }

    private function installAdminModule(): void {
        $modules = $this->wire('modules');
        if ($modules->isInstalled('ProcessPromptWireAdmin')) return;

        try {
            $modules->resetCache();
            $modules->install('ProcessPromptWireAdmin');
            $this->message('Installed PromptWire Admin dashboard — find it under Setup → PromptWire Admin.');
        } catch (\Exception $e) {
            $this->warning('Could not auto-install ProcessPromptWireAdmin: ' . $e->getMessage());
        }
    }

    /**
     * Detect and remove legacy module directories from pre-1.5 installs.
     */
    private function cleanupLegacyStructure(): void {
        $modulesPath = $this->wire('config')->paths->siteModules;
        $modules = $this->wire('modules');

        // Remove old PwMcpAdmin standalone directory
        $oldAdminPath = $modulesPath . 'PwMcpAdmin/';
        if (is_dir($oldAdminPath) && file_exists($oldAdminPath . 'ProcessPwMcpAdmin.module.php')) {
            if ($modules->isInstalled('ProcessPwMcpAdmin')) {
                try { $modules->uninstall('ProcessPwMcpAdmin'); } catch (\Exception $e) {}
            }
            $this->removeDirectoryRecursive($oldAdminPath);
            $this->message('Removed legacy site/modules/PwMcpAdmin/ directory.');
        }

        // Remove old PwMcp directory if PromptWire is installed alongside it
        $oldMcpPath = $modulesPath . 'PwMcp/';
        if (is_dir($oldMcpPath) && file_exists($oldMcpPath . 'PwMcp.module.php')) {
            if ($modules->isInstalled('PwMcp')) {
                try { $modules->uninstall('PwMcp'); } catch (\Exception $e) {}
            }
            $this->removeDirectoryRecursive($oldMcpPath);
            $this->message('Removed legacy site/modules/PwMcp/ directory.');
        }
    }

    private function removeDirectoryRecursive(string $dir): void {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    public function getPwVersion(): string {
        return $this->wire('config')->version;
    }

    public function getSiteName(): string {
        return $this->wire('config')->httpHost ?: 'ProcessWire Site';
    }

    public function isLoaded(): bool {
        return true;
    }

    public function getCounts(): array {
        return [
            'templates' => $this->wire('templates')->getAll()->count(),
            'fields' => $this->wire('fields')->getAll()->count(),
            'pages' => $this->wire('pages')->count('include=all'),
        ];
    }
}
