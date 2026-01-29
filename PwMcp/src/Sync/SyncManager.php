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
     * Pull multiple pages matching a selector
     * 
     * Supports pulling by:
     * - ProcessWire selector string
     * - Parent path (pulls all children)
     * - Template name
     * 
     * @param string $selector ProcessWire selector, parent path, or template
     * @param string $format Content format (yaml or json)
     * @param bool $includeParent Include the parent page when pulling by parent
     * @param int $limit Maximum pages to pull (0 = no limit)
     * @return array Results with success/fail counts and details
     */
    public function pullPages(string $selector, string $format = 'yaml', bool $includeParent = true, int $limit = 0): array {
        $results = [
            'success' => true,
            'selector' => $selector,
            'pulled' => 0,
            'skipped' => 0,
            'failed' => 0,
            'pages' => [],
            'errors' => [],
        ];
        
        // Determine the type of selector and build the query
        $pages = $this->resolvePagesFromSelector($selector, $includeParent, $limit);
        
        if ($pages === null || $pages->count() === 0) {
            $results['success'] = false;
            $results['error'] = "No pages found matching: $selector";
            return $results;
        }
        
        $results['totalFound'] = $pages->count();
        
        // Pull each page
        foreach ($pages as $page) {
            $pullResult = $this->pullPage($page->id, $format);
            
            if (isset($pullResult['success']) && $pullResult['success']) {
                $results['pulled']++;
                $results['pages'][] = [
                    'id' => $page->id,
                    'path' => $page->path,
                    'title' => $page->title,
                    'localPath' => $pullResult['localPath'],
                ];
            } elseif (isset($pullResult['error'])) {
                if (strpos($pullResult['error'], 'system page') !== false) {
                    $results['skipped']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'id' => $page->id,
                        'path' => $page->path,
                        'error' => $pullResult['error'],
                    ];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Resolve pages from a flexible selector
     * 
     * Accepts:
     * - Standard ProcessWire selector: "template=blog-post, limit=10"
     * - Parent path: "/medical-negligence-claims/" (pulls children)
     * - Template shorthand: "template=service-page"
     * 
     * @param string $selector The selector string
     * @param bool $includeParent Include parent when using parent path
     * @param int $limit Maximum pages (0 = no limit)
     * @return \ProcessWire\PageArray|null
     */
    private function resolvePagesFromSelector(string $selector, bool $includeParent = true, int $limit = 0) {
        $pages = $this->wire->pages;
        
        // Check if it's a path (starts with /)
        if (strpos($selector, '/') === 0) {
            $parent = $pages->get($selector);
            if (!$parent || !$parent->id) {
                return null;
            }
            
            // Get children
            $childSelector = "parent=$parent->id, include=all";
            if ($limit > 0) {
                $childSelector .= ", limit=$limit";
            }
            $children = $pages->find($childSelector);
            
            // Optionally include the parent
            if ($includeParent) {
                $result = new \ProcessWire\PageArray();
                $result->add($parent);
                $result->import($children);
                return $result;
            }
            
            return $children;
        }
        
        // Check if it's a simple template shorthand
        if (strpos($selector, '=') === false && strpos($selector, ',') === false) {
            // Assume it's a template name
            $selector = "template=$selector";
        }
        
        // Add limit if specified
        if ($limit > 0 && strpos($selector, 'limit=') === false) {
            $selector .= ", limit=$limit";
        }
        
        // Add include=all to get hidden/unpublished too
        if (strpos($selector, 'include=') === false) {
            $selector .= ", include=all";
        }
        
        return $pages->find($selector);
    }
    
    // ========================================================================
    // PUSH OPERATIONS
    // ========================================================================
    
    /**
     * Push local changes back to ProcessWire
     * 
     * Reads local page.yaml and applies changes to the source page.
     * Uses dry-run by default for safety.
     * 
     * @param string $localPath Path to local page directory or yaml file
     * @param bool $dryRun If true, show what would change without applying (default: true)
     * @param bool $force Force push even if remote has changed (dangerous)
     * @return array Result with changes applied or preview
     */
    public function pushPage(string $localPath, bool $dryRun = true, bool $force = false): array {
        // Normalize path - accept either directory or yaml file path
        if (str_ends_with($localPath, '.yaml') || str_ends_with($localPath, '.json')) {
            $localDir = dirname($localPath);
        } else {
            $localDir = rtrim($localPath, '/');
        }
        
        // Make path absolute if relative
        if (strpos($localDir, '/') !== 0) {
            $localDir = $this->wire->config->paths->root . $localDir;
        }
        
        // Check for required files
        $metaPath = $localDir . '/page.meta.json';
        $yamlPath = $localDir . '/page.yaml';
        $jsonPath = $localDir . '/page.json';
        
        if (!file_exists($metaPath)) {
            return ['error' => "Meta file not found: $metaPath"];
        }
        
        // Determine content file
        $contentPath = file_exists($yamlPath) ? $yamlPath : $jsonPath;
        if (!file_exists($contentPath)) {
            return ['error' => "Content file not found (page.yaml or page.json)"];
        }
        
        // Read meta file
        $meta = json_decode(file_get_contents($metaPath), true);
        if (!$meta || !isset($meta['pageId'])) {
            return ['error' => "Invalid meta file"];
        }
        
        // Read content file
        $content = $this->readContentFile($contentPath);
        if (!$content || !isset($content['fields'])) {
            return ['error' => "Invalid content file"];
        }
        
        // Load the page
        $page = $this->wire->pages->get($meta['pageId']);
        if (!$page || !$page->id) {
            return ['error' => "Page not found: {$meta['pageId']}"];
        }
        
        // Verify template matches
        if ($page->template->name !== $meta['template']) {
            return ['error' => "Template mismatch: expected {$meta['template']}, got {$page->template->name}"];
        }
        
        // Check for remote changes (conflict detection)
        $currentFields = $this->extractPageFields($page);
        $currentHash = $this->generateRevisionHash($currentFields);
        
        if ($currentHash !== $meta['revisionHash'] && !$force) {
            return [
                'error' => 'Remote page has changed since last pull',
                'conflict' => true,
                'hint' => 'Pull the latest version first, or use --force to overwrite',
                'localPulledAt' => $meta['pulledAt'],
                'remoteHash' => substr($currentHash, 0, 24) . '...',
                'localHash' => substr($meta['revisionHash'], 0, 24) . '...',
            ];
        }
        
        // Calculate changes
        $changes = $this->calculateChanges($currentFields, $content['fields']);
        
        if (empty($changes)) {
            return [
                'success' => true,
                'message' => 'No changes to push',
                'pageId' => $page->id,
                'pagePath' => $page->path,
            ];
        }
        
        // Dry-run mode - just show what would change
        if ($dryRun) {
            return [
                'dryRun' => true,
                'pageId' => $page->id,
                'pagePath' => $page->path,
                'template' => $page->template->name,
                'changes' => $changes,
                'changeCount' => count($changes),
                'hint' => 'Use --dry-run=0 to apply these changes',
            ];
        }
        
        // Apply changes
        $page->of(false); // Turn off output formatting
        
        foreach ($content['fields'] as $fieldName => $value) {
            // Skip fields that don't exist on the page
            if (!$page->template->hasField($fieldName)) {
                continue;
            }
            
            $field = $this->wire->fields->get($fieldName);
            $this->applyFieldValue($page, $field, $value);
        }
        
        // Save the page
        $page->save();
        
        // Update meta file
        $newFields = $this->extractPageFields($page);
        $newHash = $this->generateRevisionHash($newFields);
        
        $meta['lastPushedAt'] = date('c');
        $meta['revisionHash'] = $newHash;
        $meta['status'] = 'clean';
        
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        return [
            'success' => true,
            'pageId' => $page->id,
            'pagePath' => $page->path,
            'template' => $page->template->name,
            'changes' => $changes,
            'changeCount' => count($changes),
            'pushedAt' => $meta['lastPushedAt'],
        ];
    }
    
    /**
     * Push all local changes in a directory tree
     * 
     * Scans for all page.meta.json files and pushes each page.
     * Uses dry-run by default for safety.
     * 
     * @param string $directory Directory to scan (default: site/syncs)
     * @param bool $dryRun Preview changes without applying (default: true)
     * @param bool $force Force push even if remote changed (dangerous)
     * @return array Results with push status for each page
     */
    public function pushPages(string $directory, bool $dryRun = true, bool $force = false): array {
        // Make path absolute if relative
        if (strpos($directory, '/') !== 0) {
            $directory = $this->wire->config->paths->root . $directory;
        }
        
        $directory = rtrim($directory, '/');
        
        if (!is_dir($directory)) {
            return ['error' => "Directory not found: $directory"];
        }
        
        $results = [
            'success' => true,
            'dryRun' => $dryRun,
            'directory' => $this->getRelativePath($directory),
            'pushed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'conflicts' => 0,
            'noChanges' => 0,
            'pages' => [],
            'errors' => [],
        ];
        
        // Find all page.meta.json files recursively
        $metaFiles = $this->findMetaFiles($directory);
        
        if (empty($metaFiles)) {
            $results['error'] = "No synced pages found in directory";
            $results['success'] = false;
            return $results;
        }
        
        $results['totalFound'] = count($metaFiles);
        
        // Push each page
        foreach ($metaFiles as $metaPath) {
            $localDir = dirname($metaPath);
            $pushResult = $this->pushPage($localDir, $dryRun, $force);
            
            if (isset($pushResult['success']) && $pushResult['success']) {
                if (isset($pushResult['message']) && $pushResult['message'] === 'No changes to push') {
                    $results['noChanges']++;
                } else {
                    $results['pushed']++;
                }
                $results['pages'][] = [
                    'pageId' => $pushResult['pageId'],
                    'path' => $pushResult['pagePath'],
                    'changes' => $pushResult['changes'] ?? [],
                    'status' => isset($pushResult['message']) ? 'clean' : ($dryRun ? 'preview' : 'pushed'),
                ];
            } elseif (isset($pushResult['dryRun']) && $pushResult['dryRun']) {
                // Dry-run with changes
                $results['pushed']++;
                $results['pages'][] = [
                    'pageId' => $pushResult['pageId'],
                    'path' => $pushResult['pagePath'],
                    'changes' => $pushResult['changes'] ?? [],
                    'status' => 'preview',
                ];
            } elseif (isset($pushResult['conflict']) && $pushResult['conflict']) {
                $results['conflicts']++;
                $results['errors'][] = [
                    'localPath' => $this->getRelativePath($localDir),
                    'error' => $pushResult['error'],
                    'type' => 'conflict',
                ];
            } elseif (isset($pushResult['error'])) {
                $results['failed']++;
                $results['errors'][] = [
                    'localPath' => $this->getRelativePath($localDir),
                    'error' => $pushResult['error'],
                    'type' => 'error',
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get sync status of all pulled pages
     * 
     * Checks each pulled page for:
     * - Clean: no local or remote changes
     * - LocalDirty: local file modified since pull
     * - RemoteChanged: ProcessWire page modified since pull  
     * - Conflict: both local and remote changed
     * - Orphan: ProcessWire page deleted
     * 
     * @param string|null $directory Directory to scan (default: site/syncs)
     * @return array Status report for all synced pages
     */
    public function getSyncStatus(?string $directory = null): array {
        $directory = $directory ?: $this->syncRoot;
        
        // Make path absolute if relative
        if (strpos($directory, '/') !== 0) {
            $directory = $this->wire->config->paths->root . $directory;
        }
        
        $directory = rtrim($directory, '/');
        
        if (!is_dir($directory)) {
            return ['error' => "Directory not found: $directory"];
        }
        
        $results = [
            'success' => true,
            'directory' => $this->getRelativePath($directory),
            'summary' => [
                'clean' => 0,
                'localDirty' => 0,
                'remoteChanged' => 0,
                'conflict' => 0,
                'orphan' => 0,
            ],
            'pages' => [],
        ];
        
        // Find all page.meta.json files
        $metaFiles = $this->findMetaFiles($directory);
        
        if (empty($metaFiles)) {
            $results['message'] = "No synced pages found";
            return $results;
        }
        
        $results['totalPages'] = count($metaFiles);
        
        // Check each page
        foreach ($metaFiles as $metaPath) {
            $localDir = dirname($metaPath);
            $status = $this->getPageSyncStatus($metaPath);
            
            $results['summary'][$status['status']]++;
            
            // Only include non-clean pages in the list for brevity
            if ($status['status'] !== 'clean') {
                $results['pages'][] = $status;
            }
        }
        
        return $results;
    }
    
    /**
     * Check sync status of a single page
     * 
     * @param string $metaPath Path to page.meta.json
     * @return array Status info
     */
    private function getPageSyncStatus(string $metaPath): array {
        $localDir = dirname($metaPath);
        
        // Read meta file
        $meta = json_decode(file_get_contents($metaPath), true);
        if (!$meta || !isset($meta['pageId'])) {
            return [
                'localPath' => $this->getRelativePath($localDir),
                'status' => 'orphan',
                'error' => 'Invalid meta file',
            ];
        }
        
        // Load the ProcessWire page
        $page = $this->wire->pages->get($meta['pageId']);
        if (!$page || !$page->id) {
            return [
                'localPath' => $this->getRelativePath($localDir),
                'pageId' => $meta['pageId'],
                'status' => 'orphan',
                'error' => 'Page deleted from ProcessWire',
            ];
        }
        
        // Get current remote hash
        $currentFields = $this->extractPageFields($page);
        $currentHash = $this->generateRevisionHash($currentFields);
        
        $remoteChanged = ($currentHash !== $meta['revisionHash']);
        
        // Check if local file was modified
        $contentPath = file_exists($localDir . '/page.yaml') 
            ? $localDir . '/page.yaml' 
            : $localDir . '/page.json';
        
        $localContent = $this->readContentFile($contentPath);
        $localHash = $this->generateRevisionHash($localContent['fields'] ?? []);
        
        // Compare local content hash against original pull
        // If local differs from what's in meta, it's dirty
        $originalContent = ['fields' => $currentFields];
        $originalLocalHash = $this->generateRevisionHash($currentFields);
        
        // Actually, we need to detect if user edited the file
        // Compare parsed local against what we'd export from remote
        $changes = $this->calculateChanges($currentFields, $localContent['fields'] ?? []);
        $localDirty = !empty($changes);
        
        // Determine status
        if ($remoteChanged && $localDirty) {
            $status = 'conflict';
        } elseif ($localDirty) {
            $status = 'localDirty';
        } elseif ($remoteChanged) {
            $status = 'remoteChanged';
        } else {
            $status = 'clean';
        }
        
        $result = [
            'localPath' => $this->getRelativePath($localDir),
            'pageId' => $meta['pageId'],
            'pagePath' => $page->path,
            'title' => $page->title,
            'status' => $status,
            'pulledAt' => $meta['pulledAt'],
        ];
        
        if ($localDirty) {
            $result['localChanges'] = count($changes);
        }
        if ($remoteChanged) {
            $result['remoteChangedSincePull'] = true;
        }
        
        return $result;
    }
    
    /**
     * Find all page.meta.json files in a directory tree
     * 
     * @param string $directory Root directory to search
     * @return array List of meta file paths
     */
    private function findMetaFiles(string $directory): array {
        $metaFiles = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'page.meta.json') {
                $metaFiles[] = $file->getPathname();
            }
        }
        
        return $metaFiles;
    }
    
    /**
     * Read and parse a content file (YAML or JSON)
     * 
     * @param string $path Path to content file
     * @return array|null Parsed content or null on error
     */
    private function readContentFile(string $path): ?array {
        $content = file_get_contents($path);
        
        if (str_ends_with($path, '.yaml')) {
            return $this->yamlToArray($content);
        }
        
        return json_decode($content, true);
    }
    
    /**
     * Parse YAML content to array
     * 
     * Simple YAML parser for our structured format.
     * 
     * @param string $yaml YAML content
     * @return array|null Parsed array or null on error
     */
    private function yamlToArray(string $yaml): ?array {
        // Use Symfony YAML if available, otherwise simple parse
        if (class_exists('Symfony\Component\Yaml\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parse($yaml);
        }
        
        // Simple YAML parsing for basic structures
        $lines = explode("\n", $yaml);
        $result = [];
        $stack = [&$result];
        $indentStack = [-1];
        
        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (trim($line) === '' || ltrim($line)[0] === '#') {
                continue;
            }
            
            // Calculate indentation
            $indent = strlen($line) - strlen(ltrim($line));
            $trimmed = trim($line);
            
            // Pop stack until we're at the right level
            while (count($indentStack) > 1 && $indent <= end($indentStack)) {
                array_pop($stack);
                array_pop($indentStack);
            }
            
            // Handle array item
            if (strpos($trimmed, '- ') === 0) {
                $value = substr($trimmed, 2);
                $current = &$stack[count($stack) - 1];
                if (!is_array($current)) {
                    $current = [];
                }
                
                if ($value === '' || strpos($value, ':') !== false) {
                    // Nested object in array
                    $newItem = [];
                    if ($value !== '') {
                        // Parse inline key: value
                        $parts = explode(': ', $value, 2);
                        if (count($parts) === 2) {
                            $newItem[$parts[0]] = $this->parseYamlValue($parts[1]);
                        }
                    }
                    $current[] = $newItem;
                    $stack[] = &$current[count($current) - 1];
                    $indentStack[] = $indent;
                } else {
                    $current[] = $this->parseYamlValue($value);
                }
            }
            // Handle key: value
            elseif (strpos($trimmed, ':') !== false) {
                $colonPos = strpos($trimmed, ':');
                $key = substr($trimmed, 0, $colonPos);
                $value = trim(substr($trimmed, $colonPos + 1));
                
                $current = &$stack[count($stack) - 1];
                
                // Safety check: if current context is a string, we're inside multiline content
                if (is_string($current)) {
                    $current .= ($current !== '' ? "\n" : '') . $trimmed;
                    continue;
                }
                
                if ($value === '' || $value === '|') {
                    // Nested structure or multiline
                    $current[$key] = ($value === '|') ? '' : [];
                    $stack[] = &$current[$key];
                    $indentStack[] = $indent;
                } else {
                    $current[$key] = $this->parseYamlValue($value);
                }
            }
            // Multiline content
            else {
                $current = &$stack[count($stack) - 1];
                if (is_string($current)) {
                    $current .= ($current !== '' ? "\n" : '') . $trimmed;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Parse a YAML scalar value
     * 
     * @param string $value
     * @return mixed
     */
    private function parseYamlValue(string $value) {
        if ($value === 'null' || $value === '~') {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }
        // Remove quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }
        return $value;
    }
    
    /**
     * Calculate changes between current and new field values
     * 
     * @param array $current Current field values
     * @param array $new New field values from local file
     * @return array List of changes
     */
    private function calculateChanges(array $current, array $new): array {
        $changes = [];
        
        foreach ($new as $fieldName => $newValue) {
            $currentValue = $current[$fieldName] ?? null;
            
            // Normalize for comparison
            $currentNorm = $this->normalizeForComparison($currentValue);
            $newNorm = $this->normalizeForComparison($newValue);
            
            if ($currentNorm !== $newNorm) {
                $changes[$fieldName] = [
                    'field' => $fieldName,
                    'type' => $currentValue === null ? 'add' : 'modify',
                    'preview' => is_string($newValue) 
                        ? substr(strip_tags($newValue), 0, 100) . (strlen($newValue) > 100 ? '...' : '')
                        : gettype($newValue),
                ];
            }
        }
        
        return $changes;
    }
    
    /**
     * Normalize a value for comparison
     * 
     * @param mixed $value
     * @return string JSON representation for comparison
     */
    private function normalizeForComparison($value): string {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Apply a field value to a page
     * 
     * @param Page $page
     * @param \ProcessWire\Field $field
     * @param mixed $value
     */
    private function applyFieldValue(Page $page, $field, $value): void {
        $fieldName = $field->name;
        $typeName = $field->type->className();
        
        // Handle date fields - convert ISO 8601 back to Unix timestamp
        if ($typeName === 'FieldtypeDatetime' && is_string($value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                $page->set($fieldName, $timestamp);
                return;
            }
        }
        
        // Handle single page reference (new format with _pageRef)
        if (is_array($value) && isset($value['_pageRef']) && $value['_pageRef'] === true) {
            $refId = (int) $value['id'];
            // Validate the referenced page exists
            $refPage = $this->wire->pages->get($refId);
            if ($refPage && $refPage->id) {
                $page->set($fieldName, $refId);
            }
            return;
        }
        
        // Handle array of page references (new format)
        if (is_array($value) && !empty($value) && isset($value[0]['_pageRef'])) {
            $pageIds = [];
            foreach ($value as $ref) {
                $refId = (int) $ref['id'];
                // Validate each referenced page exists
                $refPage = $this->wire->pages->get($refId);
                if ($refPage && $refPage->id) {
                    $pageIds[] = $refId;
                }
            }
            $page->set($fieldName, $pageIds);
            return;
        }
        
        // Handle legacy page reference format (for backwards compatibility)
        if (is_array($value) && isset($value['_ref']) && $value['_ref'] === 'page') {
            $page->set($fieldName, $value['id']);
            return;
        }
        if (is_array($value) && !empty($value) && isset($value[0]['_ref']) && $value[0]['_ref'] === 'page') {
            $pageIds = array_map(fn($p) => $p['id'], $value);
            $page->set($fieldName, $pageIds);
            return;
        }
        
        // Handle files/images - skip for now (Phase 2+)
        if (in_array($typeName, ['FieldtypeFile', 'FieldtypeImage', 'FieldtypeCroppableImage3'])) {
            // Don't modify file fields in this phase
            return;
        }
        
        // Handle repeaters - update existing items by ID
        if (strpos($typeName, 'Repeater') !== false) {
            $this->applyRepeaterValue($page, $field, $value);
            return;
        }
        
        // Simple scalar values
        $page->set($fieldName, $value);
    }
    
    /**
     * Apply changes to repeater/matrix field items
     * 
     * Updates existing items by _itemId. Does NOT add, delete, or reorder.
     * Validates that each item belongs to this page's repeater field.
     * 
     * @param Page $page Parent page
     * @param \ProcessWire\Field $field Repeater field
     * @param array $items Array of item data from YAML
     * @return array Stats about what was updated
     */
    private function applyRepeaterValue(Page $page, $field, $items): array {
        $fieldName = $field->name;
        $stats = ['updated' => 0, 'skipped_missing' => 0, 'skipped_invalid' => 0];
        
        if (!is_array($items)) {
            return $stats;
        }
        
        // Get the current repeater items for this page
        $repeater = $page->get($fieldName);
        if (!$repeater) {
            return $stats;
        }
        
        // Build a set of allowed item IDs (items that belong to this page's repeater)
        $allowedIds = [];
        foreach ($repeater as $existingItem) {
            $allowedIds[$existingItem->id] = $existingItem;
        }
        
        // Process each item from the YAML
        foreach ($items as $itemData) {
            // Must have _itemId
            if (!isset($itemData['_itemId'])) {
                $stats['skipped_invalid']++;
                continue;
            }
            
            $itemId = (int) $itemData['_itemId'];
            
            // Validate: item must belong to this page's repeater field
            // This prevents accidentally updating a random repeater item
            if (!isset($allowedIds[$itemId])) {
                $stats['skipped_missing']++;
                continue;
            }
            
            $repeaterPage = $allowedIds[$itemId];
            $repeaterPage->of(false);
            
            // Update fields on this repeater item
            foreach ($itemData as $key => $val) {
                // Skip metadata fields
                if (strpos($key, '_') === 0) {
                    continue;
                }
                
                // Check if field exists on repeater template
                if (!$repeaterPage->template->hasField($key)) {
                    continue;
                }
                
                $itemField = $this->wire->fields->get($key);
                if ($itemField) {
                    // Recursively apply field value (handles nested types)
                    $this->applyFieldValue($repeaterPage, $itemField, $val);
                } else {
                    // Simple value
                    $repeaterPage->set($key, $val);
                }
            }
            
            $repeaterPage->save();
            $stats['updated']++;
        }
        
        return $stats;
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
        
        // Date/Datetime fields - convert Unix timestamp to ISO 8601
        $fieldType = $field->type->className();
        if ($fieldType === 'FieldtypeDatetime' && is_numeric($value) && $value > 0) {
            return date('Y-m-d', (int) $value);
        }
        
        // Single page reference — store as ID with comment info
        // Format: { _pageRef: true, id: 1816, _comment: "Title @ /path/" }
        // The _comment is for human readability, ignored on push
        if ($value instanceof \ProcessWire\Page) {
            if (!$value->id) {
                return null;
            }
            return [
                '_pageRef' => true,
                'id' => $value->id,
                '_comment' => $value->title . ' @ ' . $value->path,
            ];
        }
        
        // Page array (multi-page reference) — store as array of IDs with comments
        if ($value instanceof \ProcessWire\PageArray && 
            !($value instanceof \ProcessWire\RepeaterPageArray) &&
            strpos(get_class($value), 'Repeater') === false) {
            $refs = [];
            foreach ($value as $p) {
                if (!$p->id) continue;
                $refs[] = [
                    '_pageRef' => true,
                    'id' => $p->id,
                    '_comment' => $p->title . ' @ ' . $p->path,
                ];
            }
            return $refs;
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
     * Includes _itemId for stable matching on push (not position-based).
     * Also includes _sort for human readability.
     * 
     * @param \ProcessWire\Field $field
     * @param \ProcessWire\PageArray $repeater
     * @return array
     */
    private function formatRepeaterForExport($field, $repeater): array {
        $items = [];
        
        foreach ($repeater as $index => $item) {
            $itemData = [];
            
            // Store the repeater item's page ID for stable matching
            // This is critical — matching by position breaks if items are reordered
            $itemData['_itemId'] = $item->id;
            
            // Include matrix type if present
            $typeId = $item->get('repeater_matrix_type');
            if ($typeId !== null) {
                $itemData['_matrixType'] = (int) $typeId;
            }
            
            // Include sort order for human readability (informational only)
            $itemData['_sort'] = $item->sort;
            
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
                    $yaml .= $prefix . '- ' . $this->yamlValue($value, $indent + 1) . "\n";
                }
            } else {
                // Key-value pair
                if (is_array($value) && !empty($value)) {
                    $yaml .= $prefix . $key . ":\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                } elseif (is_array($value)) {
                    $yaml .= $prefix . $key . ": []\n";
                } else {
                    $yaml .= $prefix . $key . ': ' . $this->yamlValue($value, $indent + 1) . "\n";
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
    private function yamlValue($value, int $indent = 1): string {
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
            // Multi-line string - use literal block scalar with proper indentation
            if (strpos($value, "\n") !== false) {
                $lines = explode("\n", $value);
                $linePrefix = str_repeat('  ', $indent);
                $result = "|\n";
                foreach ($lines as $line) {
                    $result .= $linePrefix . $line . "\n";
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
