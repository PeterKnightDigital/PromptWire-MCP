<?php
/**
 * PW-MCP Sync Manager
 * 
 * Handles pull/push operations for syncing ProcessWire pages
 * to local file-based snapshots for editing.
 * 
 * @package     PwMcp
 * @subpackage  Sync
 * @author      Peter Knight
 * @license     MIT
 */

namespace PwMcp\Sync;

use ProcessWire\ProcessWire;
use ProcessWire\Page;

/**
 * Manages page sync operations: pull, plan, push
 * 
 * Implements a Git-like workflow for ProcessWire content:
 * - Pull: ProcessWire page → local files
 * - Plan: Compare local vs remote (diff)
 * - Push: Local files → ProcessWire page
 */
class SyncManager {
    
    /**
     * ProcessWire instance
     * @var ProcessWire
     */
    private $wire;
    
    /**
     * Root directory for synced content
     * @var string
     */
    private $syncRoot;
    
    /**
     * Create a new SyncManager
     * 
     * @param ProcessWire $wire ProcessWire instance
     * @param string|null $syncRoot Custom sync root (default: site/syncs)
     */
    public function __construct(ProcessWire $wire, ?string $syncRoot = null) {
        $this->wire = $wire;
        $this->syncRoot = $syncRoot ?: $wire->config->paths->site . 'syncs';
        
        // Ensure sync directory exists with protection
        $this->ensureSyncDirectory();
    }
    
    /**
     * Ensure the sync directory exists and is protected
     */
    private function ensureSyncDirectory(): void {
        if (!is_dir($this->syncRoot)) {
            mkdir($this->syncRoot, 0755, true);
        }
        
        // Add .htaccess to block web access
        $htaccess = $this->syncRoot . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
        
        // Add index.php as extra protection
        $index = $this->syncRoot . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // Silence is golden\n");
        }
    }
    
    // ========================================================================
    // PULL OPERATIONS
    // ========================================================================
    
    /**
     * Pull a single page into local sync directory
     * 
     * Creates a mirrored directory structure based on the page path,
     * with page.meta.json (identity) and page.yaml (editable content).
     * 
     * @param int|string $idOrPath Page ID or path
     * @param string $format Output format for content (yaml or json)
     * @return array Result with success status and file paths
     */
    public function pullPage($idOrPath, string $format = 'yaml'): array {
        // Load the page
        $page = $this->getPage($idOrPath);
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $idOrPath"];
        }
        
        // Don't sync system pages
        if ($page->template->flags & \ProcessWire\Template::flagSystem) {
            return ['error' => "Cannot sync system pages"];
        }
        
        // Create the mirrored directory path
        $localPath = $this->getLocalPath($page);
        
        if (!is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }
        
        // Generate content snapshot
        $fields = $this->extractPageFields($page);
        
        // Generate revision hash for conflict detection
        $revisionHash = $this->generateRevisionHash($fields);
        
        // Create metadata file
        $meta = [
            'pageId' => $page->id,
            'canonicalPath' => $page->path,
            'template' => $page->template->name,
            'title' => $page->title,
            'pulledAt' => date('c'),
            'lastPushedAt' => null,
            'revisionHash' => $revisionHash,
            'status' => 'clean',
        ];
        
        $metaPath = $localPath . '/page.meta.json';
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Create content file
        $content = ['fields' => $fields];
        
        if ($format === 'yaml') {
            $contentPath = $localPath . '/page.yaml';
            $yamlContent = $this->arrayToYaml($content);
            file_put_contents($contentPath, $yamlContent);
        } else {
            $contentPath = $localPath . '/page.json';
            file_put_contents($contentPath, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        
        return [
            'success' => true,
            'pageId' => $page->id,
            'pagePath' => $page->path,
            'template' => $page->template->name,
            'localPath' => $this->getRelativePath($localPath),
            'files' => [
                'meta' => $this->getRelativePath($metaPath),
                'content' => $this->getRelativePath($contentPath),
            ],
            'fieldCount' => count($fields),
            'revisionHash' => substr($revisionHash, 0, 16) . '...',
        ];
    }
    
    /**
     * Get a page by ID or path
     * 
     * @param int|string $idOrPath Page ID or path
     * @return Page|null
     */
    private function getPage($idOrPath): ?Page {
        if (is_numeric($idOrPath)) {
            return $this->wire->pages->get((int) $idOrPath);
        }
        return $this->wire->pages->get($idOrPath);
    }
    
    /**
     * Get local filesystem path for a page
     * 
     * Mirrors the page's URL path structure.
     * 
     * @param Page $page
     * @return string Absolute path to local directory
     */
    private function getLocalPath(Page $page): string {
        // Remove leading/trailing slashes, replace remaining with directory separator
        $pathSegments = trim($page->path, '/');
        
        if (empty($pathSegments)) {
            // Homepage
            return $this->syncRoot . '/home';
        }
        
        return $this->syncRoot . '/' . $pathSegments;
    }
    
    /**
     * Get path relative to ProcessWire root
     * 
     * @param string $absolutePath
     * @return string
     */
    private function getRelativePath(string $absolutePath): string {
        $root = $this->wire->config->paths->root;
        if (strpos($absolutePath, $root) === 0) {
            return substr($absolutePath, strlen($root));
        }
        return $absolutePath;
    }
    
    /**
     * Extract editable field values from a page
     * 
     * @param Page $page
     * @return array Field name => value pairs
     */
    private function extractPageFields(Page $page): array {
        $fields = [];
        
        foreach ($page->template->fields as $field) {
            $name = $field->name;
            $value = $page->get($name);
            
            // Skip system fields
            if (strpos($name, '_') === 0 && $name !== '_title') {
                continue;
            }
            
            $fields[$name] = $this->formatFieldForExport($field, $value);
        }
        
        return $fields;
    }
    
    /**
     * Format a field value for YAML/JSON export
     * 
     * @param \ProcessWire\Field $field
     * @param mixed $value
     * @return mixed
     */
    private function formatFieldForExport($field, $value) {
        // Null/empty
        if ($value === null || $value === '') {
            return null;
        }
        
        // Single page reference
        if ($value instanceof \ProcessWire\Page) {
            return [
                '_ref' => 'page',
                'id' => $value->id,
                'path' => $value->path,
                'title' => $value->title,
            ];
        }
        
        // Page array (multi-page reference)
        if ($value instanceof \ProcessWire\PageArray && 
            !($value instanceof \ProcessWire\RepeaterPageArray) &&
            strpos(get_class($value), 'Repeater') === false) {
            $pages = [];
            foreach ($value as $p) {
                $pages[] = [
                    '_ref' => 'page',
                    'id' => $p->id,
                    'path' => $p->path,
                    'title' => $p->title,
                ];
            }
            return $pages;
        }
        
        // Repeater/Matrix fields
        if ($value instanceof \ProcessWire\RepeaterPageArray || 
            (is_object($value) && $value instanceof \ProcessWire\PageArray && 
             strpos(get_class($value), 'Repeater') !== false)) {
            return $this->formatRepeaterForExport($field, $value);
        }
        
        // Files/Images
        if ($value instanceof \ProcessWire\Pagefiles || $value instanceof \ProcessWire\Pageimages) {
            $files = [];
            foreach ($value as $file) {
                $fileData = [
                    'filename' => $file->name,
                    'description' => $file->description ?: null,
                ];
                if ($file instanceof \ProcessWire\Pageimage) {
                    $fileData['width'] = $file->width;
                    $fileData['height'] = $file->height;
                }
                $files[] = $fileData;
            }
            return $files;
        }
        
        // WireArray
        if ($value instanceof \ProcessWire\WireArray) {
            return $value->getArray();
        }
        
        // Scalar values
        return $value;
    }
    
    /**
     * Format repeater/matrix items for export
     * 
     * @param \ProcessWire\Field $field
     * @param \ProcessWire\PageArray $repeater
     * @return array
     */
    private function formatRepeaterForExport($field, $repeater): array {
        $items = [];
        
        foreach ($repeater as $item) {
            $itemData = [];
            
            // Include matrix type if present
            $typeId = $item->get('repeater_matrix_type');
            if ($typeId !== null) {
                $itemData['_matrixType'] = (int) $typeId;
            }
            
            // Get field values
            foreach ($item->template->fields as $f) {
                if (strpos($f->name, 'repeater_') === 0) {
                    continue;
                }
                $itemData[$f->name] = $this->formatFieldForExport($f, $item->get($f->name));
            }
            
            $items[] = $itemData;
        }
        
        return $items;
    }
    
    /**
     * Generate a revision hash for conflict detection
     * 
     * @param array $fields Field values
     * @return string SHA256 hash
     */
    private function generateRevisionHash(array $fields): string {
        $json = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return 'sha256:' . hash('sha256', $json);
    }
    
    /**
     * Convert array to YAML format
     * 
     * Simple YAML generator for readable output.
     * 
     * @param array $data
     * @param int $indent Current indentation level
     * @return string
     */
    private function arrayToYaml(array $data, int $indent = 0): string {
        $yaml = '';
        $prefix = str_repeat('  ', $indent);
        
        foreach ($data as $key => $value) {
            if (is_int($key)) {
                // Array item
                if (is_array($value)) {
                    $yaml .= $prefix . "-\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                } else {
                    $yaml .= $prefix . '- ' . $this->yamlValue($value) . "\n";
                }
            } else {
                // Key-value pair
                if (is_array($value) && !empty($value)) {
                    $yaml .= $prefix . $key . ":\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                } elseif (is_array($value)) {
                    $yaml .= $prefix . $key . ": []\n";
                } else {
                    $yaml .= $prefix . $key . ': ' . $this->yamlValue($value) . "\n";
                }
            }
        }
        
        return $yaml;
    }
    
    /**
     * Format a value for YAML output
     * 
     * @param mixed $value
     * @return string
     */
    private function yamlValue($value): string {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            // Multi-line string
            if (strpos($value, "\n") !== false) {
                $lines = explode("\n", $value);
                $result = "|\n";
                foreach ($lines as $line) {
                    $result .= '  ' . $line . "\n";
                }
                return rtrim($result);
            }
            // String that needs quoting
            if (preg_match('/[:#\[\]{}|>&*!?]/', $value) || 
                $value === '' || 
                is_numeric($value)) {
                return '"' . addslashes($value) . '"';
            }
            return $value;
        }
        return (string) $value;
    }
}
