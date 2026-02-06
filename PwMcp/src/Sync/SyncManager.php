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

// Import PHP 8 string functions into namespace
use function str_ends_with;
use function str_starts_with;

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
     * @param string|null $syncRoot Custom sync root (default: site/assets/pw-mcp)
     */
    public function __construct(ProcessWire $wire, ?string $syncRoot = null) {
        $this->wire = $wire;
        // Use site/assets/pw-mcp/ as workspace (PW convention: assets = data, site = code)
        $this->syncRoot = $syncRoot ?: $wire->config->paths->assets . 'pw-mcp';
        
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
        
        // Generate content snapshot (with external file extraction for rich text)
        $fields = $this->extractPageFields($page, $localPath);
        
        // Generate revision hash for conflict detection
        // Note: For hashing, we need the inline version to compare with remote
        $fieldsForHash = $this->extractPageFields($page);
        $revisionHash = $this->generateRevisionHash($fieldsForHash);
        
        // Build field labels map for YAML comments
        $fieldLabels = $this->getFieldLabelsForTemplate($page->template);
        
        // Create content file first so we can hash it
        $content = ['fields' => $fields];
        
        if ($format === 'yaml') {
            $contentPath = $localPath . '/page.yaml';
            $yamlContent = $this->arrayToYamlWithLabels($content, $fieldLabels);
            file_put_contents($contentPath, $yamlContent);
        } else {
            $contentPath = $localPath . '/page.json';
            $jsonContent = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($contentPath, $jsonContent);
        }
        
        // Compute combined hash of all sync files (page.yaml + external field files)
        $contentHash = $this->computeCombinedContentHash($localPath);
        
        // Create metadata file with both hashes
        $meta = [
            '_readme' => 'DO NOT EDIT - This file is auto-generated. Edit page.yaml and field HTML files instead.',
            'pageId' => $page->id,
            'canonicalPath' => $page->path,
            'template' => $page->template->name,
            'title' => $page->title,
            'pulledAt' => date('c'),
            'lastPushedAt' => null,
            'revisionHash' => $revisionHash,
            'contentHash' => $contentHash, // Hash of the actual file content
            'status' => 'clean',
        ];
        
        $metaPath = $localPath . '/page.meta.json';
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
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
     * @param array $excludeKeys Field keys to skip when applying (e.g. ["body", "matrix→Body[2]"])
     * @return array Result with changes applied or preview
     */
    public function pushPage(string $localPath, bool $dryRun = true, bool $force = false, array $excludeKeys = []): array {
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
            // Calculate what changed remotely (between when we pulled and now)
            $pulledFields = $this->readContentFile($contentPath);
            $remoteChanges = [];
            if ($pulledFields && isset($pulledFields['fields'])) {
                // Resolve file references in pulled content for comparison
                $resolvedPulled = $this->resolveFileReferences($pulledFields['fields'], $localDir);
                $remoteChanges = $this->calculateChanges($resolvedPulled, $currentFields);
            }
            
            // Also calculate local changes (what user wants to push)
            $resolvedLocal = $this->resolveFileReferences($content['fields'], $localDir);
            $localChanges = $this->calculateChanges($currentFields, $resolvedLocal);
            
            return [
                'error' => 'Remote page has changed since last pull',
                'conflict' => true,
                'hint' => 'Re-export to get latest version, or force import to overwrite remote changes',
                'localPulledAt' => $meta['pulledAt'],
                'remoteHash' => substr($currentHash, 0, 24) . '...',
                'localHash' => substr($meta['revisionHash'], 0, 24) . '...',
                'remoteChanges' => $remoteChanges,  // What changed in ProcessWire
                'localChanges' => $localChanges,    // What user wants to change
            ];
        }
        
        // Resolve _file references in content before comparing
        $resolvedFields = $this->resolveFileReferences($content['fields'], $localDir);
        
        // Calculate changes
        $changes = $this->calculateChanges($currentFields, $resolvedFields);
        
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
            // Skip top-level fields that the user excluded from import
            if (!empty($excludeKeys) && in_array($fieldName, $excludeKeys)) {
                continue;
            }
            
            $field = $this->wire->fields->get($fieldName);
            $this->applyFieldValue($page, $field, $value, $localDir, $excludeKeys);
        }
        
        // Save the page
        $page->save();
        
        // Update local files to match what's now in ProcessWire
        // Use localDir to preserve external file structure (_file references)
        $newFields = $this->extractPageFields($page, $localDir);
        
        // Generate revision hash from inline content (for remote comparison)
        $fieldsForHash = $this->extractPageFields($page);
        $newHash = $this->generateRevisionHash($fieldsForHash);
        
        $newContent = ['fields' => $newFields];
        
        // Determine format and update content file
        $isYaml = file_exists($contentPath) && str_ends_with($contentPath, '.yaml');
        if ($isYaml) {
            $fieldLabels = $this->getFieldLabelsForTemplate($page->template);
            $newFileContent = $this->arrayToYamlWithLabels($newContent, $fieldLabels);
        } else {
            $newFileContent = json_encode($newContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($contentPath, $newFileContent);
        
        // Compute combined hash including external field files
        $newContentHash = $this->computeCombinedContentHash($localDir);
        
        // Update meta file
        $meta['lastPushedAt'] = date('c');
        $meta['revisionHash'] = $newHash;
        $meta['contentHash'] = $newContentHash;
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
        
        // Check if local files were modified by user
        // Use combined hash to detect changes in page.yaml + external field files
        $currentFileHash = $this->computeCombinedContentHash($localDir);
        
        // Determine content file path
        $contentPath = file_exists($localDir . '/page.yaml') 
            ? $localDir . '/page.yaml' 
            : $localDir . '/page.json';
        
        // If meta has contentHash, use it for accurate local change detection
        if (isset($meta['contentHash'])) {
            $localDirty = ($currentFileHash !== $meta['contentHash']);
        } else {
            // Fallback for older pulls without contentHash - use field comparison
            $localContent = $this->readContentFile($contentPath);
            $resolvedFields = $this->resolveFileReferences($localContent['fields'] ?? [], $localDir);
            $changes = $this->calculateChanges($currentFields, $resolvedFields);
            $localDirty = !empty($changes);
        }
        
        // If dirty, calculate actual changes for reporting
        $changes = [];
        if ($localDirty) {
            $localContent = $this->readContentFile($contentPath);
            $resolvedFields = $this->resolveFileReferences($localContent['fields'] ?? [], $localDir);
            $changes = $this->calculateChanges($currentFields, $resolvedFields);
        }
        
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
        
        if ($localDirty && !empty($changes)) {
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
        $symfonyYamlClass = 'Symfony\Component\Yaml\Yaml';
        if (class_exists($symfonyYamlClass)) {
            return $symfonyYamlClass::parse($yaml);
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
            
            // Handle array item (both "- value" and standalone "-")
            if ($trimmed === '-' || strpos($trimmed, '- ') === 0) {
                // Get value after "- " if present, otherwise empty
                $value = $trimmed === '-' ? '' : substr($trimmed, 2);
                $current = &$stack[count($stack) - 1];
                if (!is_array($current)) {
                    $current = [];
                }
                
                if ($value === '' || strpos($value, ':') !== false) {
                    // Nested object in array (or empty item that will be filled by following lines)
                    $newItem = [];
                    if ($value !== '' && strpos($value, ':') !== false) {
                        // Parse inline key: value (e.g., "- _itemId: 123")
                        $parts = explode(': ', $value, 2);
                        if (count($parts) === 2) {
                            $newItem[$parts[0]] = $this->parseYamlValue($parts[1]);
                        }
                    }
                    $current[] = $newItem;
                    $stack[] = &$current[count($current) - 1];
                    $indentStack[] = $indent;
                } else {
                    // Simple scalar value in array
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
        // Empty array/object inline syntax
        if ($value === '[]') {
            return [];
        }
        if ($value === '{}') {
            return [];
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
                // Check if this is a matrix/repeater field - expand into sub-field entries
                $matrixChanges = $this->getMatrixFieldChanges($fieldName, $newValue, $currentValue);
                
                if (!empty($matrixChanges)) {
                    // Add each sub-field as its own entry with breadcrumb name
                    foreach ($matrixChanges as $subFieldName => $subChange) {
                        $breadcrumb = "{$fieldName}→{$subFieldName}";
                        $changes[$breadcrumb] = [
                            'field' => $breadcrumb,
                            'parentField' => $fieldName,
                            'subField' => preg_replace('/\[\d+\]$/', '', $subFieldName),
                            'type' => 'modify',
                            'preview' => $subChange['preview'],
                            'newValue' => $subChange['newValue'] ?? null,
                            'oldValue' => $subChange['oldValue'] ?? null,
                            'itemIndex' => $subChange['itemIndex'] ?? null,
                            'itemId' => $subChange['itemId'] ?? null,
                            'matrixType' => $subChange['matrixType'] ?? null,
                            'typeSlug' => $subChange['typeSlug'] ?? null,
                        ];
                    }
                } else {
                    // Regular field - use standard preview
                    $changes[$fieldName] = [
                        'field' => $fieldName,
                        'parentField' => null,
                        'type' => $currentValue === null ? 'add' : 'modify',
                        'preview' => $this->generateChangePreview($newValue, $currentValue),
                        'newValue' => $newValue,
                        'oldValue' => $currentValue,
                    ];
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Get individual field changes within a matrix/repeater field
     * 
     * @param string $parentField Parent field name
     * @param mixed $newValue New matrix/repeater value
     * @param mixed $currentValue Current matrix/repeater value
     * @return array Array of sub-field changes, or empty if not a matrix/repeater
     */
    private function getMatrixFieldChanges(string $parentField, $newValue, $currentValue): array {
        // Must be arrays
        if (!is_array($newValue)) {
            return [];
        }
        
        $firstItem = reset($newValue);
        if (!is_array($firstItem)) {
            return [];
        }
        
        // Check if it's a matrix/repeater (items have _itemId or _matrixType)
        if (!isset($firstItem['_itemId']) && !isset($firstItem['_matrixType'])) {
            return [];
        }
        
        $currentArray = is_array($currentValue) ? $currentValue : [];
        
        // Build lookup of current items by _itemId
        $currentById = [];
        foreach ($currentArray as $item) {
            if (is_array($item) && isset($item['_itemId'])) {
                $currentById[$item['_itemId']] = $item;
            }
        }
        
        // Build matrix type lookup (numeric ID → label) if this is a matrix field
        $matrixTypeLabels = [];
        $field = $this->wire->fields->get($parentField);
        if ($field && $field instanceof \ProcessWire\RepeaterMatrixField) {
            // getMatrixTypes('type', 'label') returns [1 => 'Body', 7 => 'Breakout Box', ...]
            $matrixTypeLabels = $field->getMatrixTypes('type', 'label');
            // Fall back to names if labels are empty
            $matrixTypeNames = $field->getMatrixTypes('type', 'name');
            foreach ($matrixTypeLabels as $typeId => $label) {
                if (empty($label) && isset($matrixTypeNames[$typeId])) {
                    $matrixTypeLabels[$typeId] = ucwords(str_replace('_', ' ', $matrixTypeNames[$typeId]));
                }
            }
        }
        
        // Find each specific field change in each item - list them individually
        $result = [];
        $itemIndex = 0;
        
        foreach ($newValue as $newItem) {
            if (!is_array($newItem) || !isset($newItem['_itemId'])) continue;
            
            $itemId = $newItem['_itemId'];
            $currentItem = $currentById[$itemId] ?? [];
            $itemIndex++;
            
            // Resolve matrix type to human-readable label and slug (for file naming)
            $matrixTypeId = $newItem['_matrixType'] ?? null;
            $matrixType = null;
            $typeSlug = null;
            if ($matrixTypeId !== null && isset($matrixTypeLabels[$matrixTypeId])) {
                $matrixType = $matrixTypeLabels[$matrixTypeId];
            } elseif ($matrixTypeId !== null) {
                $matrixType = "Type {$matrixTypeId}";
            }
            if ($matrixTypeId !== null && $field) {
                $typeSlug = $this->getMatrixTypeName($field, (int) $matrixTypeId);
            }
            
            foreach ($newItem as $subFieldName => $subFieldValue) {
                // Skip metadata fields
                if (strpos($subFieldName, '_') === 0) continue;
                
                $currentSubValue = $currentItem[$subFieldName] ?? null;
                $newNorm = $this->normalizeForComparison($subFieldValue);
                $curNorm = $this->normalizeForComparison($currentSubValue);
                
                if ($newNorm !== $curNorm) {
                    // Generate preview snippet based on value type
                    $preview = $this->generateFieldChangePreview($currentSubValue, $subFieldValue);
                    
                    // Use item index for unique key: "Body[1]", "Body[2]", etc.
                    $key = "{$subFieldName}[{$itemIndex}]";
                    $result[$key] = [
                        'preview' => $preview,
                        'newValue' => $subFieldValue,
                        'oldValue' => $currentSubValue,
                        'itemIndex' => $itemIndex,
                        'itemId' => $itemId,
                        'matrixType' => $matrixType,
                        'typeSlug' => $typeSlug,
                    ];
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Generate a human-readable preview of a change
     * 
     * For simple strings: shows truncated text
     * For arrays (matrix/repeater): shows which specific fields changed
     * For page references: shows page titles/paths
     * 
     * @param mixed $newValue The new value
     * @param mixed $currentValue The current value (for comparison context)
     * @return string Human-readable preview
     */
    private function generateChangePreview($newValue, $currentValue): string {
        // Simple string - show truncated preview
        if (is_string($newValue)) {
            $text = strip_tags($newValue);
            return strlen($text) > 100 ? substr($text, 0, 100) . '...' : $text;
        }
        
        // Null or empty
        if ($newValue === null || $newValue === '') {
            return '(empty)';
        }
        
        // Boolean
        if (is_bool($newValue)) {
            return $newValue ? 'true' : 'false';
        }
        
        // Numeric
        if (is_numeric($newValue)) {
            return (string) $newValue;
        }
        
        // Array - could be matrix, repeater, page reference, or other complex field
        if (is_array($newValue)) {
            $itemCount = count($newValue);
            $currentArray = is_array($currentValue) ? $currentValue : [];
            
            // Check if it's a matrix/repeater (items have _itemId or _matrixType)
            $firstItem = reset($newValue);
            if (is_array($firstItem)) {
                if (isset($firstItem['_itemId']) || isset($firstItem['_matrixType'])) {
                    // Build lookup of current items by _itemId for comparison
                    $currentById = [];
                    foreach ($currentArray as $item) {
                        if (is_array($item) && isset($item['_itemId'])) {
                            $currentById[$item['_itemId']] = $item;
                        }
                    }
                    
                    // Find which specific fields changed in which items, with content snippets
                    $changedDetails = []; // fieldName => ['count' => N, 'snippet' => '...']
                    foreach ($newValue as $newItem) {
                        if (!is_array($newItem) || !isset($newItem['_itemId'])) continue;
                        
                        $itemId = $newItem['_itemId'];
                        $currentItem = $currentById[$itemId] ?? [];
                        
                        // Compare each field in this item
                        foreach ($newItem as $fieldName => $fieldValue) {
                            // Skip metadata fields
                            if (strpos($fieldName, '_') === 0) continue;
                            
                            $currentFieldValue = $currentItem[$fieldName] ?? null;
                            $newNorm = $this->normalizeForComparison($fieldValue);
                            $curNorm = $this->normalizeForComparison($currentFieldValue);
                            
                            if ($newNorm !== $curNorm) {
                                if (!isset($changedDetails[$fieldName])) {
                                    $changedDetails[$fieldName] = ['count' => 0, 'snippet' => ''];
                                }
                                $changedDetails[$fieldName]['count']++;
                                
                                // Capture a snippet from the first change we find for this field
                                if (empty($changedDetails[$fieldName]['snippet']) && is_string($fieldValue)) {
                                    $text = strip_tags($fieldValue);
                                    $changedDetails[$fieldName]['snippet'] = strlen($text) > 80 
                                        ? substr($text, 0, 80) . '...' 
                                        : $text;
                                }
                            }
                        }
                    }
                    
                    if (empty($changedDetails)) {
                        return "No field changes detected";
                    }
                    
                    // Format with breadcrumb style: "→fieldname\nsnippet"
                    $parts = [];
                    foreach ($changedDetails as $fieldName => $info) {
                        $count = $info['count'];
                        $snippet = $info['snippet'];
                        $countNote = $count > 1 ? " ({$count} items)" : "";
                        
                        if ($snippet) {
                            $parts[] = "→{$fieldName}{$countNote}\n{$snippet}";
                        } else {
                            $parts[] = "→{$fieldName}{$countNote}";
                        }
                    }
                    
                    // Join with double newlines for separation between fields
                    return implode("\n\n", $parts);
                }
                
                // Page reference array (items have 'id' and maybe '_pageRef')
                if (isset($firstItem['_pageRef']) || isset($firstItem['id'])) {
                    $titles = [];
                    foreach ($newValue as $ref) {
                        if (isset($ref['_comment'])) {
                            // Extract title from comment like "John Browne @ /path/"
                            $comment = $ref['_comment'];
                            $atPos = strpos($comment, ' @ ');
                            $titles[] = $atPos !== false ? substr($comment, 0, $atPos) : $comment;
                        } elseif (isset($ref['id'])) {
                            $titles[] = "ID:{$ref['id']}";
                        }
                    }
                    $titleList = implode(', ', array_slice($titles, 0, 3));
                    if (count($titles) > 3) {
                        $titleList .= ' +' . (count($titles) - 3) . ' more';
                    }
                    return "{$itemCount} page(s): {$titleList}";
                }
            }
            
            // Generic array - show count
            return "{$itemCount} item(s)";
        }
        
        // Fallback
        return gettype($newValue);
    }
    
    /**
     * Generate a simple, useful preview of what changed
     * 
     * Shows meaningful info for simple changes, minimal for complex ones.
     * 
     * @param mixed $oldValue The old/current value
     * @param mixed $newValue The new value
     * @return string Simple change description
     */
    private function generateFieldChangePreview($oldValue, $newValue): string {
        $oldEmpty = ($oldValue === null || $oldValue === '' || $oldValue === []);
        $newEmpty = ($newValue === null || $newValue === '' || $newValue === []);
        
        // Cleared
        if (!$oldEmpty && $newEmpty) {
            return 'cleared';
        }
        
        // Added
        if ($oldEmpty && !$newEmpty) {
            // Check for option field format (single option with id/_label)
            if (is_array($newValue) && isset($newValue['id']) && isset($newValue['_label'])) {
                return 'added: ' . $newValue['_label'];
            }
            // Check for option field array format
            if (is_array($newValue) && !empty($newValue) && isset($newValue[0]['_label'])) {
                $labels = array_map(fn($opt) => $opt['_label'], $newValue);
                return 'added: ' . implode(', ', $labels);
            }
            // For simple values, show what was added
            if (is_array($newValue) && $this->isSimpleScalarArray($newValue)) {
                return 'added: ' . implode(', ', $newValue);
            }
            return 'added';
        }
        
        // Option field changes (single option with id/_label)
        if (is_array($oldValue) && is_array($newValue) &&
            isset($oldValue['id']) && isset($oldValue['_label']) &&
            isset($newValue['id']) && isset($newValue['_label'])) {
            return $oldValue['_label'] . ' → ' . $newValue['_label'];
        }
        
        // Option field changes (array of options with id/_label)
        if (is_array($oldValue) && is_array($newValue) &&
            !empty($oldValue) && !empty($newValue) &&
            isset($oldValue[0]['_label']) && isset($newValue[0]['_label'])) {
            $oldLabels = array_map(fn($opt) => $opt['_label'], $oldValue);
            $newLabels = array_map(fn($opt) => $opt['_label'], $newValue);
            return implode(', ', $oldLabels) . ' → ' . implode(', ', $newLabels);
        }
        
        // Simple arrays of scalars (option IDs like [1] → [2])
        if (is_array($oldValue) && is_array($newValue) &&
            $this->isSimpleScalarArray($oldValue) && $this->isSimpleScalarArray($newValue)) {
            return implode(', ', $oldValue) . ' → ' . implode(', ', $newValue);
        }
        
        // Numeric changes
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return "{$oldValue} → {$newValue}";
        }
        
        // String/text changes - show a snippet of the new content
        if (is_string($newValue) && !empty($newValue)) {
            $text = strip_tags($newValue);
            $text = trim(preg_replace('/\s+/', ' ', $text)); // Normalize whitespace
            return strlen($text) > 100 ? substr($text, 0, 100) . '...' : $text;
        }
        
        // Everything else - field name is enough
        return '';
    }
    
    /**
     * Check if array contains only scalar values
     */
    private function isSimpleScalarArray(array $arr): bool {
        if (empty($arr)) return false;
        foreach ($arr as $val) {
            if (!is_scalar($val)) return false;
        }
        return true;
    }
    
    /**
     * Normalize a value for comparison
     * 
     * Handles equivalence of empty values:
     * - null, [], "", and 0 for certain field types should be treated consistently
     * - Empty arrays and null are equivalent for comparison purposes
     * - Nested arrays are recursively normalized
     * - Keys are sorted to ensure consistent comparison regardless of order
     * 
     * @param mixed $value
     * @return string JSON representation for comparison
     */
    private function normalizeForComparison($value): string {
        $normalized = $this->normalizeEmptyValues($value);
        $normalized = $this->stripDisplayOnlyFields($normalized);
        $sorted = $this->sortKeysRecursive($normalized);
        return json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Strip display-only fields that shouldn't affect comparison
     * 
     * Removes _label, _comment, and similar metadata fields that are
     * for human readability but don't represent actual content changes.
     * 
     * @param mixed $value
     * @return mixed
     */
    private function stripDisplayOnlyFields($value) {
        if (!is_array($value)) {
            return $value;
        }
        
        // Check if this is an option field format (has id and _label)
        if (isset($value['id']) && isset($value['_label'])) {
            return $value['id'];  // Just compare the ID
        }
        
        $result = [];
        foreach ($value as $key => $val) {
            // Skip display-only keys
            if ($key === '_label' || $key === '_comment') {
                continue;
            }
            $result[$key] = $this->stripDisplayOnlyFields($val);
        }
        
        return $result;
    }
    
    /**
     * Recursively sort array keys for consistent comparison
     * 
     * @param mixed $value
     * @return mixed
     */
    private function sortKeysRecursive($value) {
        if (!is_array($value)) {
            return $value;
        }
        
        // Check if it's an associative array (has string keys)
        $isAssoc = !$this->isSequentialArray($value);
        
        // Recursively sort nested arrays
        $result = [];
        foreach ($value as $key => $val) {
            $result[$key] = $this->sortKeysRecursive($val);
        }
        
        // Sort by keys if associative
        if ($isAssoc) {
            ksort($result);
        }
        
        return $result;
    }
    
    /**
     * Recursively normalize empty values
     * 
     * Treats null, empty arrays, and empty strings as equivalent null.
     * For file/image arrays, empty array becomes null.
     * 
     * @param mixed $value
     * @return mixed Normalized value
     */
    private function normalizeEmptyValues($value) {
        // Null stays null
        if ($value === null) {
            return null;
        }
        
        // Empty string becomes null
        if ($value === '') {
            return null;
        }
        
        // Empty array becomes null
        if (is_array($value) && empty($value)) {
            return null;
        }
        
        // For arrays, recursively normalize and check if result is all nulls
        if (is_array($value)) {
            $normalized = [];
            $allNull = true;
            
            foreach ($value as $key => $val) {
                $normVal = $this->normalizeEmptyValues($val);
                $normalized[$key] = $normVal;
                if ($normVal !== null) {
                    $allNull = false;
                }
            }
            
            // If array only contains null values, treat whole array as null
            // But only for simple arrays, not for structured data with keys
            if ($allNull && $this->isSequentialArray($value)) {
                return null;
            }
            
            return $normalized;
        }
        
        // Scalar values pass through
        return $value;
    }
    
    /**
     * Check if array is sequential (numeric keys 0, 1, 2...)
     * 
     * @param array $arr
     * @return bool
     */
    private function isSequentialArray(array $arr): bool {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
    
    /**
     * Apply a field value to a page
     * 
     * @param Page $page
     * @param \ProcessWire\Field $field
     * @param mixed $value
     * @param string|null $localDir Base path for reading external files
     * @param array $excludeKeys Field keys to skip (e.g. matrix→Body[2]) - used for repeater sub-fields
     */
    private function applyFieldValue(Page $page, $field, $value, ?string $localDir = null, array $excludeKeys = []): void {
        $fieldName = $field->name;
        /** @var string $typeName */
        $typeName = $field->type->className();
        
        // Handle external file reference (_file)
        if ($localDir && is_array($value) && isset($value['_file'])) {
            $fileContent = $this->readExternalField($localDir, $value['_file']);
            if ($fileContent !== null) {
                $value = $fileContent;
            }
        }
        
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
        
        // Handle Options fields (single option with id/_label)
        if (is_array($value) && isset($value['id']) && isset($value['_label'])) {
            $page->set($fieldName, [(int) $value['id']]);
            return;
        }
        
        // Handle Options fields (array of options with id/_label)
        if (is_array($value) && !empty($value) && isset($value[0]['id']) && isset($value[0]['_label'])) {
            $optionIds = array_map(fn($opt) => (int) $opt['id'], $value);
            $page->set($fieldName, $optionIds);
            return;
        }
        
        // Handle files/images - skip for now (Phase 2+)
        // Check if it's any file/image type (including namespaced class names and variants)
        if (strpos($typeName, 'FieldtypeFile') !== false || 
            strpos($typeName, 'FieldtypeImage') !== false ||
            strpos($typeName, 'FieldtypeCroppable') !== false) {
            // Don't modify file fields in this phase
            return;
        }
        
        // Also check the current page value - if it's a Pagefiles/Pageimages, skip
        $currentValue = $page->get($fieldName);
        if ($currentValue instanceof \ProcessWire\Pagefiles || 
            $currentValue instanceof \ProcessWire\Pageimages) {
            // Don't modify file fields in this phase
            return;
        }
        
        // Handle repeaters - update existing items by ID
        if (strpos($typeName, 'Repeater') !== false) {
            $this->applyRepeaterValue($page, $field, $value, $localDir, $excludeKeys);
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
     * @param string|null $localDir Base path for reading external files
     * @param array $excludeKeys Field keys to skip (e.g. matrix→Body[2]) - sub-fields excluded from import
     * @return array Stats about what was updated
     */
    private function applyRepeaterValue(Page $page, $field, $items, ?string $localDir = null, array $excludeKeys = []): array {
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
        
        // Pre-normalize excluded keys for case-insensitive comparison
        $excludeNormalized = !empty($excludeKeys) ? array_map('strtolower', $excludeKeys) : [];
        
        $itemIndex = 0;
        // Process each item from the YAML
        foreach ($items as $itemData) {
            // Must have _itemId
            if (!isset($itemData['_itemId'])) {
                $stats['skipped_invalid']++;
                continue;
            }
            
            $itemId = (int) $itemData['_itemId'];
            $itemIndex++;
            
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
                
                // Skip this sub-field if it was excluded from import (e.g. matrix→Body[2])
                $changeKey = $fieldName . '→' . $key . '[' . $itemIndex . ']';
                if (!empty($excludeNormalized) && in_array(strtolower($changeKey), $excludeNormalized)) {
                    continue;
                }
                
                // Check if field exists on repeater template
                if (!$repeaterPage->template->hasField($key)) {
                    continue;
                }
                
                $itemField = $this->wire->fields->get($key);
                if ($itemField) {
                    // Recursively apply field value (handles nested types and _file references)
                    $this->applyFieldValue($repeaterPage, $itemField, $val, $localDir, $excludeKeys);
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
     * @param string|null $localPath Base path for writing external files (null = inline only)
     * @return array Field name => value pairs
     */
    private function extractPageFields(Page $page, ?string $localPath = null): array {
        $fields = [];
        
        foreach ($page->template->fields as $field) {
            $name = $field->name;
            $value = $page->get($name);
            
            // Skip system fields
            if (strpos($name, '_') === 0 && $name !== '_title') {
                continue;
            }
            
            $fields[$name] = $this->formatFieldForExport($field, $value, $localPath);
        }
        
        return $fields;
    }
    
    /**
     * Format a field value for YAML/JSON export
     * 
     * @param \ProcessWire\Field $field
     * @param mixed $value
     * @param string|null $localPath Base path for writing external files (null = inline only)
     * @param array|null $context Context for naming (e.g., ['itemId' => 1933] for matrix items)
     * @return mixed
     */
    private function formatFieldForExport($field, $value, ?string $localPath = null, ?array $context = null) {
        // Null/empty
        if ($value === null || $value === '') {
            return null;
        }
        
        // Rich text fields - extract to external HTML file if localPath provided
        if ($localPath && $this->isRichTextField($field) && is_string($value) && strlen($value) > 0) {
            $fieldName = strtolower($field->name);
            
            if ($context && isset($context['itemId'])) {
                // Matrix/Repeater item: matrix/[itemId]-{typeName}-{fieldName}.html (flat)
                $prefix = '[' . $context['itemId'] . ']';
                if (!empty($context['typeName'])) {
                    $prefix .= '-' . $context['typeName'];
                }
                $subDir = 'matrix';
                $filename = $prefix . '-' . $fieldName . '.html';
            } else {
                // Page-level field: fields/{fieldName}.html
                $subDir = 'fields';
                $filename = $fieldName . '.html';
            }
            
            $relativePath = $this->writeExternalField($localPath, $subDir, $filename, $value);
            return ['_file' => $relativePath];
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
            return $this->formatRepeaterForExport($field, $value, $localPath);
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
        
        // SelectableOptionArray (Options fields) - return ID with label comment
        if ($value instanceof \ProcessWire\SelectableOptionArray) {
            $options = [];
            foreach ($value as $option) {
                $options[] = [
                    'id' => $option->id,
                    '_label' => $option->title ?: $option->value,  // Read-only, shows what ID means
                ];
            }
            // For single option, return just the one item
            if (count($options) === 1) {
                return $options[0];
            }
            return $options ?: null;
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
     * Rich text fields within items are extracted to matrix/[itemId]-{typeName}-{fieldName}.html
     * 
     * @param \ProcessWire\Field $field
     * @param \ProcessWire\PageArray $repeater
     * @param string|null $localPath Base path for writing external files
     * @return array
     */
    private function formatRepeaterForExport($field, $repeater, ?string $localPath = null): array {
        $items = [];
        
        foreach ($repeater as $index => $item) {
            $itemData = [];
            
            // Store the repeater item's page ID for stable matching
            // This is critical — matching by position breaks if items are reordered
            $itemData['_itemId'] = $item->id;
            
            // Include matrix type if present and get type name for folder naming
            $typeId = $item->get('repeater_matrix_type');
            $typeName = null;
            if ($typeId !== null) {
                $itemData['_matrixType'] = (int) $typeId;
                // Try to get the human-readable type name
                $typeName = $this->getMatrixTypeName($field, (int) $typeId);
            }
            
            // Include sort order for human readability (informational only)
            $itemData['_sort'] = $item->sort;
            
            // Context for external file naming (matrix items)
            // Use matrix type name if available, otherwise fall back to field name
            $context = [
                'itemId' => $item->id,
                'typeName' => $typeName ?? strtolower($field->name),
            ];
            
            // Get field values
            foreach ($item->template->fields as $f) {
                if (strpos($f->name, 'repeater_') === 0) {
                    continue;
                }
                $itemData[$f->name] = $this->formatFieldForExport($f, $item->get($f->name), $localPath, $context);
            }
            
            $items[] = $itemData;
        }
        
        return $items;
    }
    
    /**
     * Check if a field is a rich text field (CKEditor/TinyMCE)
     * 
     * Only fields using a rich text editor contain HTML that can break YAML parsing.
     * Plain textarea fields (without CKEditor/TinyMCE) are kept inline.
     * 
     * @param \ProcessWire\Field $field
     * @return bool
     */
    private function isRichTextField($field): bool {
        $typeName = $field->type->className();
        
        // Must be a textarea-type field
        if (!in_array($typeName, ['FieldtypeTextarea', 'FieldtypeTextareaLanguage'])) {
            return false;
        }
        
        // Check the inputfield class for rich text editors
        $inputfieldClass = $field->get('inputfieldClass');
        if ($inputfieldClass) {
            // CKEditor or TinyMCE indicate rich text
            if (stripos($inputfieldClass, 'CKEditor') !== false || 
                stripos($inputfieldClass, 'TinyMCE') !== false) {
                return true;
            }
        }
        
        // Also check contentType setting (1 = HTML, 0 = text)
        $contentType = $field->get('contentType');
        if ($contentType === 1) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get the human-readable name for a matrix type
     * 
     * @param \ProcessWire\Field $field The repeater matrix field
     * @param int $typeId The matrix type ID
     * @return string|null The type name (slug format) or null if not found
     */
    private function getMatrixTypeName($field, int $typeId): ?string {
        // RepeaterMatrix stores type info in field settings
        // Type names are stored as matrix{N}_name where N is the type ID
        $typeName = $field->get("matrix{$typeId}_name");
        
        if ($typeName) {
            // Convert to lowercase slug format
            return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $typeName));
        }
        
        return null;
    }
    
    /**
     * Write a field's content to an external file
     * 
     * Used for rich text fields to prevent YAML parsing issues.
     * 
     * @param string $basePath Base path for the page's sync directory
     * @param string $subDir Subdirectory (e.g., 'fields' or 'matrix')
     * @param string $filename Filename (e.g., 'body.html')
     * @param string $content The HTML content to write
     * @return string Relative path to the file (e.g., 'fields/body.html')
     */
    private function writeExternalField(string $basePath, string $subDir, string $filename, string $content): string {
        $dir = $basePath . '/' . $subDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filePath = $dir . '/' . $filename;
        file_put_contents($filePath, $content);
        return $subDir . '/' . $filename;
    }
    
    /**
     * Read content from an external field file
     * 
     * @param string $basePath Base path for the page's sync directory
     * @param string $relativePath Relative path from YAML _file reference
     * @return string|null File content or null if not found
     */
    private function readExternalField(string $basePath, string $relativePath): ?string {
        $filePath = $basePath . '/' . $relativePath;
        if (file_exists($filePath)) {
            return file_get_contents($filePath);
        }
        return null;
    }
    
    /**
     * Resolve _file references in field data to actual content
     * 
     * Recursively walks the fields array and replaces any _file references
     * with the actual file content. This is needed for accurate change detection.
     * 
     * @param array $fields Field data from YAML
     * @param string $localDir Base path for resolving file references
     * @return array Fields with _file references replaced by actual content
     */
    private function resolveFileReferences(array $fields, string $localDir): array {
        $resolved = [];
        
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                // Check if this is a _file reference
                if (isset($value['_file'])) {
                    $content = $this->readExternalField($localDir, $value['_file']);
                    $resolved[$key] = $content ?? '';
                } else {
                    // Recursively resolve nested arrays (for repeaters/matrix)
                    $resolved[$key] = $this->resolveFileReferences($value, $localDir);
                }
            } else {
                $resolved[$key] = $value;
            }
        }
        
        return $resolved;
    }
    
    /**
     * Compute a combined hash of all sync files (page.yaml + external field files)
     * 
     * Used for detecting local changes across all related files.
     * 
     * @param string $localPath Base path for the page's sync directory
     * @return string MD5 hash of combined content
     */
    private function computeCombinedContentHash(string $localPath): string {
        $hashContent = '';
        
        // Add page.yaml content
        $yamlPath = $localPath . '/page.yaml';
        if (file_exists($yamlPath)) {
            $hashContent .= file_get_contents($yamlPath);
        }
        
        // Add external field files (fields/*.html)
        $fieldsDir = $localPath . '/fields';
        if (is_dir($fieldsDir)) {
            $files = glob($fieldsDir . '/*.html');
            sort($files); // Ensure consistent order
            foreach ($files as $file) {
                $hashContent .= file_get_contents($file);
            }
        }
        
        // Add matrix field files (matrix/{itemId}/*.html) - recursive
        $matrixDir = $localPath . '/matrix';
        if (is_dir($matrixDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($matrixDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            $files = [];
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'html') {
                    $files[] = $file->getPathname();
                }
            }
            sort($files); // Ensure consistent order
            foreach ($files as $file) {
                $hashContent .= file_get_contents($file);
            }
        }
        
        return md5($hashContent);
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
     * Convert array to YAML with field label comments
     * 
     * Adds human-readable comments above fields in the 'fields' section.
     * 
     * @param array $data The data array
     * @param array $fieldLabels Map of field names to labels
     * @return string YAML content with comments
     */
    private function arrayToYamlWithLabels(array $data, array $fieldLabels): string {
        $yaml = '';
        
        foreach ($data as $key => $value) {
            if ($key === 'fields' && is_array($value)) {
                // Special handling for fields section - add comments
                $yaml .= "fields:\n";
                $isFirst = true;
                foreach ($value as $fieldName => $fieldValue) {
                    // Add blank line before each field (except first) for readability
                    if (!$isFirst) {
                        $yaml .= "\n";
                    }
                    $isFirst = false;
                    
                    // Add label comment if available
                    if (isset($fieldLabels[$fieldName]) && $fieldLabels[$fieldName] !== $fieldName) {
                        $yaml .= "  # " . $fieldLabels[$fieldName] . "\n";
                    }
                    
                    // Output the field
                    if (is_array($fieldValue) && !empty($fieldValue)) {
                        $yaml .= "  " . $fieldName . ":\n";
                        $yaml .= $this->arrayToYaml($fieldValue, 2);
                    } elseif (is_array($fieldValue)) {
                        $yaml .= "  " . $fieldName . ": []\n";
                    } else {
                        $yaml .= "  " . $fieldName . ': ' . $this->yamlValue($fieldValue, 2) . "\n";
                    }
                }
            } else {
                // Standard handling for other sections
                if (is_array($value) && !empty($value)) {
                    $yaml .= $key . ":\n";
                    $yaml .= $this->arrayToYaml($value, 1);
                } elseif (is_array($value)) {
                    $yaml .= $key . ": []\n";
                } else {
                    $yaml .= $key . ': ' . $this->yamlValue($value, 1) . "\n";
                }
            }
        }
        
        return $yaml;
    }
    
    /**
     * Get field labels for a template
     * 
     * @param \ProcessWire\Template $template
     * @return array Map of field name => label
     */
    private function getFieldLabelsForTemplate($template): array {
        $labels = [];
        foreach ($template->fields as $field) {
            $label = $field->label ?: $field->name;
            $labels[$field->name] = $label;
        }
        return $labels;
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
    
    // ========================================================================
    // PHASE 3: PAGE CREATION & PUBLISHING
    // ========================================================================
    
    /**
     * Create a new page scaffold locally
     * 
     * Generates page.meta.json (with new: true) and page.yaml with
     * empty fields based on the template definition.
     * 
     * @param string $template Template name for the new page
     * @param string $parentPath Parent page path (e.g., "/services/")
     * @param string $pageName URL-safe page name (slug)
     * @param string|null $title Optional page title
     * @return array Result with created file paths
     */
    public function createPageScaffold(string $template, string $parentPath, string $pageName, ?string $title = null): array {
        // Validate template exists
        $templateObj = $this->wire->templates->get($template);
        if (!$templateObj) {
            return ['error' => "Template not found: $template"];
        }
        
        // Validate parent exists
        $parent = $this->wire->pages->get($parentPath);
        if (!$parent || !$parent->id) {
            return ['error' => "Parent page not found: $parentPath"];
        }
        
        // Sanitize page name
        $pageName = $this->wire->sanitizer->pageName($pageName);
        if (empty($pageName)) {
            return ['error' => "Invalid page name"];
        }
        
        // Check if page already exists
        $existingPath = rtrim($parentPath, '/') . '/' . $pageName . '/';
        $existing = $this->wire->pages->get($existingPath);
        if ($existing && $existing->id) {
            return ['error' => "Page already exists: $existingPath"];
        }
        
        // Create local directory
        $localPath = $this->syncRoot . rtrim($parentPath, '/') . '/' . $pageName;
        if (is_dir($localPath)) {
            return ['error' => "Local directory already exists: $localPath"];
        }
        
        mkdir($localPath, 0755, true);
        
        // Generate title if not provided
        $title = $title ?: ucwords(str_replace('-', ' ', $pageName));
        
        // Create metadata file (marked as new)
        $meta = [
            '_readme' => 'DO NOT EDIT - This file is auto-generated. Edit page.yaml and field HTML files instead.',
            'pageId' => null,
            'new' => true,
            'canonicalPath' => $existingPath,
            'template' => $template,
            'parentId' => $parent->id,
            'parentPath' => $parent->path,
            'title' => $title,
            'createdAt' => date('c'),
            'status' => 'new',
        ];
        
        $metaPath = $localPath . '/page.meta.json';
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Create content file with empty fields based on template
        $fields = $this->getTemplateFieldDefaults($templateObj);
        $fields['title'] = $title;
        
        $content = ['fields' => $fields];
        $fieldLabels = $this->getFieldLabelsForTemplate($templateObj);
        $contentPath = $localPath . '/page.yaml';
        file_put_contents($contentPath, $this->arrayToYamlWithLabels($content, $fieldLabels));
        
        return [
            'success' => true,
            'new' => true,
            'template' => $template,
            'parentPath' => $parent->path,
            'pageName' => $pageName,
            'title' => $title,
            'localPath' => $this->getRelativePath($localPath),
            'files' => [
                'meta' => $this->getRelativePath($metaPath),
                'content' => $this->getRelativePath($contentPath),
            ],
            'fieldCount' => count($fields),
        ];
    }
    
    /**
     * Get default field values for a template
     * 
     * @param \ProcessWire\Template $template
     * @return array Field name => default value
     */
    private function getTemplateFieldDefaults($template): array {
        $fields = [];
        
        foreach ($template->fields as $field) {
            $name = $field->name;
            
            // Skip system fields
            if (strpos($name, '_') === 0 && $name !== '_title') {
                continue;
            }
            
            // Set appropriate defaults based on field type
            /** @var string $typeName */
            $typeName = $field->type->className();
            
            switch ($typeName) {
                case 'FieldtypeText':
                case 'FieldtypeTextarea':
                case 'FieldtypeTextLanguage':
                case 'FieldtypeTextareaLanguage':
                case 'FieldtypePageTitle':
                case 'FieldtypePageTitleLanguage':
                    $fields[$name] = '';
                    break;
                    
                case 'FieldtypeInteger':
                case 'FieldtypeFloat':
                    $fields[$name] = 0;
                    break;
                    
                case 'FieldtypeCheckbox':
                    $fields[$name] = false;
                    break;
                    
                case 'FieldtypeDatetime':
                    $fields[$name] = null;
                    break;
                    
                case 'FieldtypeFile':
                case 'FieldtypeImage':
                case 'FieldtypeCroppableImage3':
                    $fields[$name] = [];
                    break;
                    
                case 'FieldtypePage':
                    $fields[$name] = null;
                    break;
                    
                default:
                    // For repeaters and other complex types, use empty array
                    if (strpos($typeName, 'Repeater') !== false) {
                        $fields[$name] = [];
                    } else {
                        $fields[$name] = null;
                    }
            }
        }
        
        return $fields;
    }
    
    /**
     * Publish a new page to ProcessWire
     * 
     * Creates the page in ProcessWire from local YAML files.
     * Only works for pages marked with new: true in meta.
     * 
     * @param string $localPath Path to local page directory
     * @param bool $dryRun Preview without creating (default: true)
     * @param bool $unpublished Create as unpublished (default: true)
     * @return array Result with created page info
     */
    public function publishPage(string $localPath, bool $dryRun = true, bool $unpublished = true): array {
        // Normalize path
        if (strpos($localPath, '/') !== 0) {
            $localPath = $this->wire->config->paths->root . $localPath;
        }
        $localPath = rtrim($localPath, '/');
        
        // Check for required files
        $metaPath = $localPath . '/page.meta.json';
        $yamlPath = $localPath . '/page.yaml';
        
        if (!file_exists($metaPath)) {
            return ['error' => "Meta file not found: $metaPath"];
        }
        if (!file_exists($yamlPath)) {
            return ['error' => "Content file not found: $yamlPath"];
        }
        
        // Read meta file
        $meta = json_decode(file_get_contents($metaPath), true);
        if (!$meta) {
            return ['error' => "Invalid meta file"];
        }
        
        // Verify this is a new page
        if (empty($meta['new'])) {
            return ['error' => "Page is not marked as new. Use page:push to update existing pages."];
        }
        
        // Verify parent exists
        $parent = $this->wire->pages->get($meta['parentId'] ?? $meta['parentPath']);
        if (!$parent || !$parent->id) {
            return ['error' => "Parent page not found: " . ($meta['parentPath'] ?? $meta['parentId'])];
        }
        
        // Verify template exists
        $template = $this->wire->templates->get($meta['template']);
        if (!$template) {
            return ['error' => "Template not found: {$meta['template']}"];
        }
        
        // Read content file
        $content = $this->readContentFile($yamlPath);
        if (!$content || !isset($content['fields'])) {
            return ['error' => "Invalid content file"];
        }
        
        // Extract page name from path
        $pageName = basename($localPath);
        
        // Check if page already exists
        $existingPath = $parent->path . $pageName . '/';
        $existing = $this->wire->pages->get($existingPath);
        if ($existing && $existing->id) {
            return ['error' => "Page already exists in ProcessWire: $existingPath"];
        }
        
        // Dry-run mode - just show what would be created
        if ($dryRun) {
            return [
                'dryRun' => true,
                'action' => 'create',
                'template' => $meta['template'],
                'parent' => $parent->path,
                'name' => $pageName,
                'title' => $content['fields']['title'] ?? $meta['title'],
                'path' => $existingPath,
                'unpublished' => $unpublished,
                'fieldCount' => count($content['fields']),
                'hint' => 'Use --dry-run=0 to create this page',
            ];
        }
        
        // Create the page using ProcessWire's Pages API
        // $pages->add() is the preferred method as it handles all initialization
        $page = $this->wire->pages->add($template, $parent, $pageName, [
            'title' => $content['fields']['title'] ?? $meta['title'],
        ]);
        
        // Set as unpublished if requested (after creation to ensure proper status handling)
        if ($unpublished) {
            $page->addStatus(Page::statusUnpublished);
            $page->save();
        }
        
        // Apply field values
        $page->of(false);
        foreach ($content['fields'] as $fieldName => $value) {
            if ($fieldName === 'title') continue; // Already set
            
            if (!$page->template->hasField($fieldName)) {
                continue;
            }
            
            $field = $this->wire->fields->get($fieldName);
            if ($field) {
                $this->applyFieldValue($page, $field, $value, $localPath);
            }
        }
        
        $page->save();
        
        // Update meta file with real page ID
        $meta['pageId'] = $page->id;
        $meta['new'] = false;
        $meta['canonicalPath'] = $page->path;
        $meta['publishedAt'] = date('c');
        $meta['status'] = 'clean';
        $meta['revisionHash'] = $this->generateRevisionHash($this->extractPageFields($page));
        
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Update content file to match what's in ProcessWire
        $fields = $this->extractPageFields($page);
        $newContent = ['fields' => $fields];
        $fieldLabels = $this->getFieldLabelsForTemplate($page->template);
        file_put_contents($yamlPath, $this->arrayToYamlWithLabels($newContent, $fieldLabels));
        
        return [
            'success' => true,
            'action' => 'created',
            'pageId' => $page->id,
            'path' => $page->path,
            'template' => $page->template->name,
            'title' => $page->title,
            'unpublished' => $unpublished,
            'localPath' => $this->getRelativePath($localPath),
        ];
    }
    
    /**
     * Bulk publish new pages in a directory
     * 
     * Finds all pages marked with new: true and publishes them.
     * 
     * @param string $directory Directory to scan
     * @param bool $dryRun Preview without creating (default: true)
     * @param bool $unpublished Create as unpublished (default: true)
     * @return array Results with created page info
     */
    public function publishPages(string $directory, bool $dryRun = true, bool $unpublished = true): array {
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
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'pages' => [],
            'errors' => [],
        ];
        
        // Find all page.meta.json files
        $metaFiles = $this->findMetaFiles($directory);
        
        if (empty($metaFiles)) {
            $results['message'] = "No pages found in directory";
            return $results;
        }
        
        // Filter to only new pages
        $newPages = [];
        foreach ($metaFiles as $metaPath) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if ($meta && !empty($meta['new'])) {
                $newPages[] = $metaPath;
            }
        }
        
        if (empty($newPages)) {
            $results['message'] = "No new pages to publish (all pages already exist in ProcessWire)";
            $results['totalScanned'] = count($metaFiles);
            return $results;
        }
        
        $results['totalNew'] = count($newPages);
        
        // Publish each new page
        foreach ($newPages as $metaPath) {
            $localDir = dirname($metaPath);
            $publishResult = $this->publishPage($localDir, $dryRun, $unpublished);
            
            if (isset($publishResult['success']) && $publishResult['success']) {
                $results['created']++;
                $results['pages'][] = [
                    'path' => $publishResult['path'],
                    'title' => $publishResult['title'],
                    'pageId' => $publishResult['pageId'],
                    'status' => 'created',
                ];
            } elseif (isset($publishResult['dryRun']) && $publishResult['dryRun']) {
                $results['created']++;
                $results['pages'][] = [
                    'path' => $publishResult['path'],
                    'title' => $publishResult['title'],
                    'status' => 'preview',
                ];
            } elseif (isset($publishResult['error'])) {
                if (strpos($publishResult['error'], 'already exists') !== false) {
                    $results['skipped']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'localPath' => $this->getRelativePath($localDir),
                        'error' => $publishResult['error'],
                    ];
                }
            }
        }
        
        return $results;
    }
    
    // ========================================================================
    // SYNC RECONCILIATION
    // ========================================================================
    
    /**
     * Reconcile local sync directories with ProcessWire
     * 
     * Detects and fixes:
     * - Path drift: page path changed in ProcessWire, local folder needs moving
     * - Orphans: page deleted in ProcessWire, local folder is stale
     * - New pages: local folders with new: true that haven't been published
     * 
     * @param string|null $directory Directory to reconcile (default: site/syncs)
     * @param bool $dryRun Preview changes without applying (default: true)
     * @return array Reconciliation report
     */
    public function reconcile(?string $directory = null, bool $dryRun = true): array {
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
            'dryRun' => $dryRun,
            'directory' => $this->getRelativePath($directory),
            'scanned' => 0,
            'pathDrift' => [],
            'orphans' => [],
            'newPages' => [],
            'clean' => 0,
            'actions' => [],
        ];
        
        // Find all page.meta.json files
        $metaFiles = $this->findMetaFiles($directory);
        $results['scanned'] = count($metaFiles);
        
        if (empty($metaFiles)) {
            $results['message'] = "No synced pages found";
            return $results;
        }
        
        foreach ($metaFiles as $metaPath) {
            $localDir = dirname($metaPath);
            $meta = json_decode(file_get_contents($metaPath), true);
            
            if (!$meta) {
                $results['errors'][] = [
                    'path' => $this->getRelativePath($localDir),
                    'error' => 'Invalid meta file',
                ];
                continue;
            }
            
            // Skip new pages (not yet published)
            if (!empty($meta['new'])) {
                $results['newPages'][] = [
                    'localPath' => $this->getRelativePath($localDir),
                    'template' => $meta['template'],
                    'title' => $meta['title'] ?? basename($localDir),
                ];
                continue;
            }
            
            // Get page from ProcessWire by ID
            $pageId = $meta['pageId'] ?? null;
            if (!$pageId) {
                $results['errors'][] = [
                    'path' => $this->getRelativePath($localDir),
                    'error' => 'No pageId in meta file',
                ];
                continue;
            }
            
            $page = $this->wire->pages->get($pageId);
            
            // Check if page still exists
            if (!$page || !$page->id) {
                // Page was deleted - this is an orphan
                $results['orphans'][] = [
                    'localPath' => $this->getRelativePath($localDir),
                    'pageId' => $pageId,
                    'title' => $meta['title'] ?? 'Unknown',
                    'lastKnownPath' => $meta['canonicalPath'] ?? 'Unknown',
                ];
                
                if (!$dryRun) {
                    // Mark as orphan in meta
                    $meta['status'] = 'orphan';
                    $meta['orphanedAt'] = date('c');
                    file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $results['actions'][] = "Marked orphan: " . $this->getRelativePath($localDir);
                }
                continue;
            }
            
            // Check if path has drifted
            $currentPath = $page->path;
            $storedPath = $meta['canonicalPath'] ?? null;
            
            if ($storedPath && $currentPath !== $storedPath) {
                // Path has changed - need to move local folder
                $newLocalPath = $this->syncRoot . rtrim($currentPath, '/');
                
                $results['pathDrift'][] = [
                    'pageId' => $pageId,
                    'title' => $page->title,
                    'oldPath' => $storedPath,
                    'newPath' => $currentPath,
                    'oldLocalPath' => $this->getRelativePath($localDir),
                    'newLocalPath' => $this->getRelativePath($newLocalPath),
                ];
                
                if (!$dryRun) {
                    // Create new directory structure
                    $newParentDir = dirname($newLocalPath);
                    if (!is_dir($newParentDir)) {
                        mkdir($newParentDir, 0755, true);
                    }
                    
                    // Move the folder
                    if (rename($localDir, $newLocalPath)) {
                        // Update meta file with new path
                        $meta['canonicalPath'] = $currentPath;
                        file_put_contents(
                            $newLocalPath . '/page.meta.json',
                            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        );
                        $results['actions'][] = "Moved: {$storedPath} → {$currentPath}";
                    } else {
                        $results['errors'][] = [
                            'path' => $this->getRelativePath($localDir),
                            'error' => "Failed to move folder to $newLocalPath",
                        ];
                    }
                }
                continue;
            }
            
            // Page is in sync
            $results['clean']++;
        }
        
        // Add summary
        $results['summary'] = [
            'clean' => $results['clean'],
            'pathDrift' => count($results['pathDrift']),
            'orphans' => count($results['orphans']),
            'newPages' => count($results['newPages']),
        ];
        
        if ($dryRun && (count($results['pathDrift']) > 0 || count($results['orphans']) > 0)) {
            $results['hint'] = 'Use --dry-run=0 to apply these changes';
        }
        
        return $results;
    }
}
