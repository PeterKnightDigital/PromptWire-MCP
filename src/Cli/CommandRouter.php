<?php
/**
 * PromptWire Command Router
 * 
 * Routes CLI commands to their appropriate handlers and returns
 * structured data suitable for JSON output. This is the main
 * orchestrator for all PromptWire CLI operations.
 * 
 * @package     PromptWire
 * @subpackage  Cli
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

namespace PromptWire\Cli;

/**
 * Routes and executes CLI commands for PromptWire
 * 
 * This class handles all command routing and execution. Each public
 * command corresponds to an MCP tool that can be invoked from Cursor.
 * 
 * Available Commands:
 *   - health         : System health check and site info
 *   - list-templates : List all non-system templates
 *   - get-template   : Get details for a specific template
 *   - list-fields    : List all non-system fields
 *   - get-field      : Get details for a specific field
 *   - get-page       : Get a page by ID or path
 *   - query-pages    : Query pages using a selector string
 *   - export-schema  : Export complete site schema
 *   - help           : Show available commands
 */
class CommandRouter {

    /**
     * Directory names that filesInventory() never descends into, regardless
     * of caller-supplied exclude patterns. Contains VCS metadata, IDE state,
     * and dependency caches that should never participate in a ProcessWire
     * site sync, file comparison, or file search. Pruned at the iterator
     * level so the contents are not walked at all.
     *
     * Note: vendor/ is intentionally NOT in this list. Some sites rely on
     * file sync to ship Composer dependencies to production. If you need to
     * exclude vendor/ on a per-site basis, pass it via excludePatterns.
     */
    private const INVENTORY_PRUNE_DIRS = [
        '.git',
        '.svn',
        '.hg',
        '.cursor',
        '.vscode',
        '.idea',
        'node_modules',
        '__pycache__',
        '.next',
        '.cache',
    ];

    /**
     * ProcessWire instance
     * 
     * @var \ProcessWire\ProcessWire
     */
    private $wire;
    
    /**
     * Create a new CommandRouter
     * 
     * @param \ProcessWire\ProcessWire $wire ProcessWire instance
     */
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Route and execute a command
     * 
     * Takes a command name and flags array, routes to the appropriate
     * handler method, and returns the result as an array.
     * 
     * @param string $command Command name (e.g., 'health', 'list-templates')
     * @param array  $flags   Parsed CLI flags including '_positional' for arguments
     * @return array Command result (will be JSON-encoded by caller)
     */
    public function run(string $command, array $flags): array {
        // Get positional arguments (non-flag arguments)
        $positional = $flags['_positional'] ?? [];
        
        switch ($command) {
            case 'health':
                return $this->health();
                
            case 'list-templates':
                return $this->listTemplates();
                
            case 'get-template':
                $name = $positional[0] ?? null;
                if (!$name) {
                    return ['error' => 'Template name required'];
                }
                return $this->getTemplate($name);
                
            case 'list-fields':
                // Check if --include=usage was specified
                $includeUsage = in_array('usage', $flags['include'] ?? []);
                return $this->listFields($includeUsage);
                
            case 'get-field':
                $name = $positional[0] ?? null;
                if (!$name) {
                    return ['error' => 'Field name required'];
                }
                return $this->getField($name);
                
            case 'get-page':
                $idOrPath = $positional[0] ?? null;
                if (!$idOrPath) {
                    return ['error' => 'Page ID or path required'];
                }
                // Check for include flags
                $includes = $flags['include'] ?? [];
                $includeFiles = in_array('files', $includes);
                $includeLabels = in_array('labels', $includes);
                // Check for truncate and summary flags
                $truncate = isset($flags['truncate']) ? (int) $flags['truncate'] : 0;
                $summary = isset($flags['summary']) ? true : false;
                return $this->getPage($idOrPath, $includeFiles, $includeLabels, $truncate, $summary);
                
            case 'query-pages':
                $selector = $positional[0] ?? null;
                if (!$selector) {
                    return ['error' => 'Selector required'];
                }
                return $this->queryPages($selector);
                
            case 'export-schema':
                return $this->exportSchema();
            
            case 'search':
                $query = $positional[0] ?? null;
                if (!$query) {
                    return ['error' => 'Search query required'];
                }
                $limit = isset($flags['limit']) ? (int) $flags['limit'] : 20;
                return $this->searchContent($query, $limit);
            
            case 'search-files':
                $query = $positional[0] ?? null;
                if (!$query) {
                    return ['error' => 'Search query required'];
                }
                $limit = isset($flags['limit']) ? (int) $flags['limit'] : 20;
                return $this->searchFiles($query, $limit);
            
            // ================================================================
            // SYNC COMMANDS (Phase 2)
            // ================================================================
            
            case 'page:pull':
                $idOrPath = $positional[0] ?? null;
                if (!$idOrPath) {
                    return ['error' => 'Page ID or path required'];
                }
                // Use --content-format for sync file format (default: yaml)
                // --format is reserved for CLI output format
                $contentFormat = $flags['content-format'] ?? 'yaml';
                $root = $flags['root'] ?? null;
                return $this->pagePull($idOrPath, $contentFormat, $root);

            // v1.8.3 — Inline YAML export with no filesystem writes. Used by
            // pw_page_pull source=remote so the MCP server can fetch a page's
            // editable content over HTTP and write it into the *local* sync
            // tree, rather than leaving a stray sync directory on production.
            case 'page:export-yaml':
                $idOrPath = $positional[0] ?? null;
                if (!$idOrPath) {
                    return ['error' => 'Page ID or path required'];
                }
                require_once(__DIR__ . '/../Sync/SyncManager.php');
                $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
                return $syncManager->exportPageYaml($idOrPath);
            
            case 'page:push':
                $localPath = $positional[0] ?? null;
                if (!$localPath) {
                    return ['error' => 'Local path to page.yaml or sync directory required'];
                }
                // Default: dry-run is ON for safety
                $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
                $force = isset($flags['force']);
                return $this->pagePush($localPath, $dryRun, $force);
            
            case 'pages:pull':
                $selector = $positional[0] ?? null;
                if (!$selector) {
                    return ['error' => 'Selector, parent path, or template required'];
                }
                $contentFormat = $flags['content-format'] ?? 'yaml';
                $includeParent = !isset($flags['no-parent']);
                $limit = isset($flags['limit']) ? (int) $flags['limit'] : 0;
                return $this->pagesPull($selector, $contentFormat, $includeParent, $limit);
            
            case 'pages:push':
                $directory = $positional[0] ?? 'site/syncs';
                // Default: dry-run is ON for safety
                $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
                $force = isset($flags['force']);
                return $this->pagesPush($directory, $dryRun, $force);
            
            case 'sync:status':
                $directory = $positional[0] ?? null;
                return $this->syncStatus($directory);
            
            case 'sync:reconcile':
                $directory = $positional[0] ?? null;
                $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
                return $this->syncReconcile($directory, $dryRun);
            
            // ================================================================
            // PHASE 3: PAGE CREATION & PUBLISHING
            // ================================================================
            
            case 'page:new':
                $template = $positional[0] ?? null;
                $parentPath = $positional[1] ?? null;
                $pageName = $positional[2] ?? null;
                if (!$template || !$parentPath || !$pageName) {
                    return ['error' => 'Usage: page:new [template] [parent-path] [page-name] [--title="Page Title"]'];
                }
                $title = $flags['title'] ?? null;
                return $this->pageNew($template, $parentPath, $pageName, $title);
            
            case 'page:init':
                $localPath = $positional[0] ?? null;
                if (!$localPath) {
                    return ['error' => 'Local path to page sync directory required'];
                }
                $template = $flags['template'] ?? null;
                return $this->pageInit($localPath, $template);
            
            case 'page:publish':
                $localPath = $positional[0] ?? null;
                if (!$localPath) {
                    return ['error' => 'Local path to new page directory required'];
                }
                $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
                $published = isset($flags['published']);
                return $this->pagePublish($localPath, $dryRun, !$published);
            
            case 'pages:publish':
                $directory = $positional[0] ?? 'site/syncs';
                $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
                $published = isset($flags['published']);
                return $this->pagesPublish($directory, $dryRun, !$published);
                
            // ================================================================
            // PHASE 4: DIRECT WRITE TOOLS
            // ================================================================
            
            case 'matrix:info':
                $pageIdOrPath = $positional[0] ?? null;
                $fieldName = $positional[1] ?? null;
                if (!$pageIdOrPath || !$fieldName) {
                    return ['error' => 'Usage: matrix:info [page-id-or-path] [field-name]'];
                }
                return $this->matrixInfo($pageIdOrPath, $fieldName);
            
            case 'matrix:add':
                $pageIdOrPath = $positional[0] ?? null;
                $fieldName = $positional[1] ?? null;
                $matrixType = $positional[2] ?? null;
                if (!$pageIdOrPath || !$fieldName || !$matrixType) {
                    return ['error' => 'Usage: matrix:add [page-id-or-path] [field-name] [matrix-type] --content=\'{"field":"value"}\''];
                }
                $content = isset($flags['content']) ? json_decode($flags['content'], true) : [];
                $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
                return $this->matrixAdd($pageIdOrPath, $fieldName, $matrixType, $content, $dryRun);
            
            // ================================================================
            // SCHEMA IMPORT (Phase 2)
            // ================================================================

            case 'schema:apply':
                $schemaFile = $positional[0] ?? null;
                if (!$schemaFile) {
                    return ['error' => 'Usage: schema:apply [path-to-schema.json] [--dry-run=0]'];
                }
                $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
                return $this->schemaApply($schemaFile, $dryRun);

            // ================================================================
            // PAGE REFERENCE VALIDATION
            // ================================================================

            case 'page:exists':
                // Accepts a JSON-encoded paths array: --paths='["/foo/","/bar/"]'
                $pathsJson = $flags['paths'] ?? null;
                $paths = $pathsJson ? json_decode($pathsJson, true) : $positional;
                if (empty($paths)) {
                    return ['error' => 'Usage: page:exists --paths=\'["/path/",...]\''];
                }
                return $this->pageExists($paths);

            // ================================================================
            // PHASE 5: DATABASE, LOGS & CACHE TOOLS
            // ================================================================
            
            case 'db-schema':
                $table = $positional[0] ?? null;
                return $this->dbSchema($table);
            
            case 'db-query':
                $sql = $positional[0] ?? null;
                if (!$sql) {
                    return ['error' => 'SQL query required'];
                }
                $limit = isset($flags['limit']) ? (int) $flags['limit'] : 100;
                return $this->dbQuery($sql, $limit);
            
            case 'db-explain':
                $sql = $positional[0] ?? null;
                if (!$sql) {
                    return ['error' => 'SQL query required'];
                }
                return $this->dbExplain($sql);
            
            case 'db-counts':
                return $this->dbCounts();
            
            case 'logs':
                $logName = $positional[0] ?? null;
                $level = $flags['level'] ?? null;
                $text = $flags['text'] ?? null;
                $limit = isset($flags['limit']) ? (int) $flags['limit'] : 50;
                return $this->readLogs($logName, $level, $text, $limit);
            
            case 'last-error':
                return $this->lastError();
            
            case 'clear-cache':
                $target = $positional[0] ?? 'all';
                return $this->clearCache($target);

            case 'maintenance:on':
                $message = $positional[0] ?? null;
                return $this->maintenanceOn($message);

            case 'maintenance:off':
                return $this->maintenanceOff();

            case 'maintenance:status':
                return $this->maintenanceStatus();

            case 'backup:create':
                $description = $positional[0] ?? '';
                $excludeTables = $flags['exclude-tables'] ?? '';
                $includeFiles = !isset($flags['no-files']);
                return $this->backupCreate($description, $excludeTables, $includeFiles);

            case 'backup:list':
                return $this->backupList();

            case 'backup:restore':
                $filename = $positional[0] ?? null;
                if (!$filename) {
                    return ['error' => 'Backup filename required. Use backup:list to see available backups.'];
                }
                return $this->backupRestore($filename);

            case 'backup:delete':
                $filename = $positional[0] ?? null;
                if (!$filename) {
                    return ['error' => 'Backup filename required. Use backup:list to see available backups.'];
                }
                return $this->backupDelete($filename);

            case 'files:push':
                $filesJson = $flags['files'] ?? '[]';
                $dryRun = !isset($flags['confirm']);
                return $this->filesPush($filesJson, $dryRun);

            case 'site:inventory':
                $excludeTemplates = $flags['exclude-templates'] ?? '';
                $includeSystem = isset($flags['include-system']) && $flags['include-system'];
                return $this->siteInventory($excludeTemplates, $includeSystem);

            case 'files:inventory':
                $dirs = $flags['directories'] ?? 'site/templates,site/modules';
                $extensions = $flags['extensions'] ?? 'php,js,css,json,latte,twig,module';
                $excludePatterns = $flags['exclude-patterns'] ?? '';
                $followSymlinks = !(isset($flags['no-follow-symlinks']) && $flags['no-follow-symlinks']);
                return $this->filesInventory($dirs, $extensions, $excludePatterns, $followSymlinks);

            // ================================================================
            // v1.9.0 — READ-ONLY DIAGNOSTIC TOOLS
            // ================================================================
            // All four are site-aware via runOnSite() on the MCP side and
            // backward compatible (additive only). They feed v1.10+ writeable
            // tools (template fieldgroup pushes, user sync, etc.) by giving
            // the operator a single round-trip to inspect module install
            // state, resolve names → ids, or compare a template across
            // local/remote before deciding to push.

            case 'modules:list':
                $classes = isset($flags['classes']) && $flags['classes'] !== ''
                    ? array_map('trim', explode(',', $flags['classes']))
                    : [];
                return $this->modulesList($classes);

            case 'users:list':
                $includeAll = in_array('all', $flags['include'] ?? [], true);
                return $this->usersList($includeAll);

            case 'resolve':
                // Accept either flag form (--type --names) or a JSON blob via
                // --input. JSON wins when both are present so callers can pass
                // very long name lists without hitting OS argv limits.
                if (isset($flags['input']) && $flags['input'] !== '') {
                    $payload = json_decode($flags['input'], true);
                    if (!is_array($payload)) {
                        return ['error' => 'resolve: --input must be a JSON object like {"type":"field","names":["a","b"]}'];
                    }
                    $type  = $payload['type']  ?? null;
                    $names = $payload['names'] ?? [];
                } else {
                    $type  = $flags['type'] ?? null;
                    $names = isset($flags['names']) && $flags['names'] !== ''
                        ? array_map('trim', explode(',', $flags['names']))
                        : [];
                }
                if (!$type) {
                    return ['error' => 'resolve: --type required (field|template|page|role|permission|user|module)'];
                }
                if (empty($names)) {
                    return ['error' => 'resolve: --names (or --input.names) required and must be non-empty'];
                }
                return $this->resolve($type, $names);

            case 'template:inspect':
                $name = $positional[0] ?? null;
                if (!$name) {
                    return ['error' => 'Template name required'];
                }
                return $this->templateInspect($name);

            // v1.11.0 — fieldgroup-only template edits. Dry-run returns a
            // classified plan (safe / warning / danger conflicts + the
            // projected post-push fieldgroup); dryRun=false applies the
            // plan via $fieldgroup->add/remove/setFieldContextArray +
            // insertBefore for flagGlobal-safe reorder.
            //
            // Two input shapes supported:
            //   1. Flag form  (simple):
            //      --template=blog_post --add=a,b --remove=c --reorder=title,body
            //      (strings only — no per-field context overrides)
            //   2. --input JSON (rich):
            //      --input='{"template":"blog_post","add":[{"name":"x","context":{"required":true,"columnWidth":50}}],"remove":["y"],"dryRun":true}'
            //      (per-field context on adds; matches the MCP tool signature)
            // When both are present --input wins (same rule as `resolve`).
            case 'template:fields-push':
                if (isset($flags['input']) && $flags['input'] !== '') {
                    $payload = json_decode($flags['input'], true);
                    if (!is_array($payload)) {
                        return ['error' => 'template:fields-push: --input must be a JSON object'];
                    }
                    $tname   = $payload['template'] ?? null;
                    $add     = is_array($payload['add']     ?? null) ? $payload['add']     : [];
                    $remove  = is_array($payload['remove']  ?? null) ? $payload['remove']  : [];
                    $reorder = is_array($payload['reorder'] ?? null) ? $payload['reorder'] : [];
                    $dryRun  = array_key_exists('dryRun', $payload)
                        ? (bool) $payload['dryRun']
                        : true;
                    $force   = !empty($payload['force']);
                } else {
                    $tname    = $flags['template'] ?? ($positional[0] ?? null);
                    $add      = isset($flags['add']) && $flags['add'] !== ''
                        ? array_values(array_filter(array_map('trim', explode(',', $flags['add']))))
                        : [];
                    $remove   = isset($flags['remove']) && $flags['remove'] !== ''
                        ? array_values(array_filter(array_map('trim', explode(',', $flags['remove']))))
                        : [];
                    $reorder  = isset($flags['reorder']) && $flags['reorder'] !== ''
                        ? array_values(array_filter(array_map('trim', explode(',', $flags['reorder']))))
                        : [];
                    $dryRunRaw = $flags['dry-run'] ?? '1';
                    $dryRun    = !in_array(strtolower((string) $dryRunRaw), ['0', 'false', 'no'], true);
                    $force     = in_array(strtolower((string) ($flags['force'] ?? '0')), ['1', 'true', 'yes'], true);
                }
                if (!$tname) {
                    return ['error' => 'template:fields-push: --template (or --input.template) required'];
                }
                if (empty($add) && empty($remove) && empty($reorder)) {
                    return ['error' => 'template:fields-push: at least one of add, remove, reorder required'];
                }
                return $this->templateFieldsPush($tname, $add, $remove, $reorder, $dryRun, $force);

            // ================================================================
            // v1.10.0 — PAGE ASSETS (site/assets/files/{pageId}/) SYNC
            // ================================================================
            // These commands operate on the page-asset directory directly,
            // not via field iteration. That matters because:
            //
            //   1. Standard FieldtypeFile / FieldtypeImage uploads land in
            //      site/assets/files/{pageId}/ as do their PW-generated
            //      image variations.
            //   2. Custom modules (e.g. MediaHub) also store files in the
            //      same directory keyed by page id, but those files are
            //      not exposed via $page->template->fieldgroup iteration —
            //      so the existing field-aware file:inventory would miss
            //      them entirely.
            //
            // Walking the directory directly catches both. PW image
            // variations (name.WIDTHxHEIGHT[-suffix].ext) are filtered by
            // default because they're regenerated on demand and would
            // produce noisy diffs that the operator does not actually
            // want to sync.

            case 'page-assets:inventory':
                $pageRef           = $positional[0] ?? null;
                $includeVariations = isset($flags['include-variations']) && $flags['include-variations'];
                $allPages          = isset($flags['all-pages']) && $flags['all-pages'];
                $excludeTemplates  = $flags['exclude-templates'] ?? '';
                if ($allPages) {
                    return $this->pageAssetsInventoryAll($includeVariations, $excludeTemplates);
                }
                if (!$pageRef) {
                    return ['error' => 'page-assets:inventory requires a page id/path (or --all-pages for the full site)'];
                }
                return $this->pageAssetsInventory($pageRef, $includeVariations);

            case 'page-assets:download':
                $pageRef  = $positional[0] ?? null;
                $filename = $flags['filename'] ?? null;
                if (!$pageRef || !$filename) {
                    return ['error' => 'page-assets:download requires a page id/path and --filename=NAME'];
                }
                return $this->pageAssetsDownload($pageRef, $filename);

            case 'help':
            default:
                return $this->help();
        }
    }
    
    // ========================================================================
    // COMMAND HANDLERS
    // ========================================================================
    
    /**
     * Health check command
     * 
     * Verifies the ProcessWire connection is working and returns
     * basic site information. This is useful for debugging MCP
     * server configuration.
     * 
     * @return array Health status including PW version, site name, and counts
     */
    private function health(): array {
        $config = $this->wire->config;
        $modules = $this->wire->modules;
        
        // Check if the PromptWire module is installed in ProcessWire
        $moduleLoaded = $modules->isInstalled('PromptWire');
        
        return [
            'status' => 'ok',
            'pwVersion' => $config->version,
            'siteName' => $config->httpHost ?: basename($config->paths->root),
            'moduleLoaded' => $moduleLoaded,
            'counts' => [
                'templates' => $this->wire->templates->getAll()->count(),
                'fields' => $this->wire->fields->getAll()->count(),
                'pages' => $this->wire->pages->count('include=all'),
            ],
            'writesEnabled' => false,  // Phase 1 is read-only
        ];
    }
    
    /**
     * List all non-system templates
     * 
     * Returns an array of templates with their names, labels,
     * field counts, and page counts. System templates are excluded.
     * 
     * @return array Array with 'templates' key containing template list
     */
    private function listTemplates(): array {
        $templates = [];
        
        foreach ($this->wire->templates as $template) {
            // Skip ProcessWire system templates (admin, user, etc.)
            if ($template->flags & \ProcessWire\Template::flagSystem) {
                continue;
            }
            
            $templates[] = [
                'name' => $template->name,
                'label' => $template->label ?: $template->name,
                'fieldCount' => $template->fields->count,
                'numPages' => $this->wire->pages->count("template={$template->name}, include=all"),
            ];
        }
        
        return ['templates' => $templates];
    }
    
    /**
     * Get detailed information about a specific template
     * 
     * Returns the template's fields, family settings (parent/child rules),
     * and access control configuration.
     * 
     * @param string $name Template name
     * @return array Template details or error
     */
    private function getTemplate(string $name): array {
        $template = $this->wire->templates->get($name);
        
        if (!$template) {
            return ['error' => "Template not found: $name"];
        }
        
        // Get list of field names in template order
        $fields = [];
        foreach ($template->fields as $field) {
            $fields[] = $field->name;
        }
        
        return [
            'name' => $template->name,
            'label' => $template->label ?: $template->name,
            'fields' => $fields,
            'familySettings' => [
                'allowPageNum' => (bool) $template->allowPageNum,
                'allowChildren' => $template->noChildren ? false : true,
                'childTemplates' => $template->childTemplates ?: [],
                'parentTemplates' => $template->parentTemplates ?: [],
            ],
            'access' => [
                'useRoles' => (bool) $template->useRoles,
                'roles' => $template->useRoles ? $this->getTemplateRoles($template) : [],
            ],
        ];
    }
    
    /**
     * Get roles that have access to a template
     * 
     * @param \ProcessWire\Template $template Template to check
     * @return array List of role names with access
     */
    private function getTemplateRoles($template): array {
        $roles = [];
        foreach ($this->wire->roles as $role) {
            if ($template->hasRole($role)) {
                $roles[] = $role->name;
            }
        }
        return $roles;
    }
    
    /**
     * List all non-system fields
     * 
     * Returns basic information about each field. Optionally includes
     * usage information (which templates use each field) when
     * --include=usage is specified.
     * 
     * @param bool $includeUsage Include template usage for each field
     * @return array Array with 'fields' key containing field list
     */
    private function listFields(bool $includeUsage = false): array {
        $fields = [];
        
        foreach ($this->wire->fields as $field) {
            // Skip ProcessWire system fields
            if ($field->flags & \ProcessWire\Field::flagSystem) {
                continue;
            }
            
            $fieldData = [
                'name' => $field->name,
                'type' => $field->type->className(),  // e.g., "FieldtypeText"
                'label' => $field->label ?: $field->name,
            ];
            
            // Optionally include which templates use this field
            // (This requires scanning all templates, so it's opt-in)
            if ($includeUsage) {
                $fieldData['usedBy'] = $this->getFieldUsage($field);
            }
            
            $fields[] = $fieldData;
        }
        
        return ['fields' => $fields];
    }
    
    /**
     * Get list of templates that use a field
     * 
     * Scans all templates to find which ones contain the given field.
     * 
     * @param \ProcessWire\Field $field Field to check
     * @return array List of template names
     */
    private function getFieldUsage($field): array {
        $usedBy = [];
        foreach ($this->wire->templates as $template) {
            if ($template->fields->has($field)) {
                $usedBy[] = $template->name;
            }
        }
        return $usedBy;
    }
    
    /**
     * Get detailed information about a specific field
     * 
     * Returns the field's type, inputfield class, settings, and
     * which templates use it.
     * 
     * @param string $name Field name
     * @return array Field details or error
     */
    private function getField(string $name): array {
        $field = $this->wire->fields->get($name);
        
        if (!$field) {
            return ['error' => "Field not found: $name"];
        }
        
        // Get inputfield class (the admin input component)
        $inputfield = $field->getInputfield(new \ProcessWire\NullPage());
        $inputfieldClass = $inputfield ? $inputfield->className() : null;
        
        return [
            'name' => $field->name,
            'type' => $field->type->className(),
            'inputfield' => $field->get('inputfieldClass') ?: $inputfieldClass,
            'label' => $field->label ?: $field->name,
            'description' => $field->description ?: null,
            'required' => (bool) $field->required,
            'templates' => $this->getFieldUsage($field),
            'settings' => $this->getFieldSettings($field),
        ];
    }
    
    /**
     * Get relevant settings for a field
     * 
     * Extracts common field settings like maxlength, min/max, etc.
     * Only includes settings that have values.
     * 
     * @param \ProcessWire\Field $field Field to get settings from
     * @return array Settings that are set for this field
     */
    private function getFieldSettings($field): array {
        $settings = [];
        
        // List of common settings to check
        $commonSettings = [
            'maxlength', 'minlength', 'size', 'rows', 'cols',
            'defaultValue', 'placeholder', 'pattern',
            'min', 'max', 'step',
        ];
        
        foreach ($commonSettings as $setting) {
            $value = $field->get($setting);
            if ($value !== null && $value !== '') {
                $settings[$setting] = $value;
            }
        }
        
        return $settings;
    }
    
    /**
     * Get a page by ID or path
     * 
     * Retrieves a page and all its field values. By default, file/image
     * fields return counts and filenames; use --include=files for full metadata.
     * Use --include=labels for field labels and descriptions.
     * Use --truncate=N to limit text content to N characters.
     * Use --summary to return field structure only (no content values).
     * 
     * @param int|string $idOrPath      Page ID (numeric) or path (e.g., "/about/")
     * @param bool       $includeFiles  Include full file/image metadata
     * @param bool       $includeLabels Include field labels and descriptions
     * @param int        $truncate      Truncate text fields to N characters (0 = no truncation)
     * @param bool       $summary       Return field structure only, no content
     * @return array Page data or error
     */
    private function getPage($idOrPath, bool $includeFiles = false, bool $includeLabels = false, int $truncate = 0, bool $summary = false): array {
        // Determine if input is an ID or path
        if (is_numeric($idOrPath)) {
            $page = $this->wire->pages->get((int) $idOrPath);
        } else {
            $page = $this->wire->pages->get($idOrPath);
        }
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $idOrPath"];
        }
        
        // Build field values array
        $fields = [];
        foreach ($page->template->fields as $field) {
            // Summary mode: return field type info only, no values
            if ($summary) {
                $fields[$field->name] = [
                    '_type' => $field->type->className(),
                    '_label' => $field->label ?: $field->name,
                ];
                continue;
            }
            
            $value = $page->get($field->name);
            $formattedValue = $this->formatFieldValue($field, $value, $includeFiles, $truncate);
            
            // Optionally wrap value with field metadata
            if ($includeLabels) {
                $fields[$field->name] = [
                    '_label' => $field->label ?: $field->name,
                    '_description' => $field->description ?: null,
                    '_type' => $field->type->className(),
                    '_value' => $formattedValue,
                ];
            } else {
                $fields[$field->name] = $formattedValue;
            }
        }
        
        return [
            'id' => $page->id,
            'name' => $page->name,
            'path' => $page->path,
            'url' => $page->url,
            'template' => $page->template->name,
            'status' => $page->status,
            'statusName' => $this->getStatusName($page->status),
            'parent' => [
                'id' => $page->parent->id,
                'path' => $page->parent->path,
                'title' => $page->parent->title,
            ],
            'numChildren' => $page->numChildren,
            'created' => date('c', $page->created),
            'modified' => date('c', $page->modified),
            'createdUser' => $page->createdUser ? $page->createdUser->name : null,
            'modifiedUser' => $page->modifiedUser ? $page->modifiedUser->name : null,
            'fields' => $fields,
        ];
    }
    
    /**
     * Convert page status number to readable name(s)
     * 
     * @param int $status Page status flags
     * @return string|array Status name(s)
     */
    private function getStatusName(int $status) {
        $statuses = [];
        
        // Check common status flags
        if ($status === 1) {
            return 'published';
        }
        
        if ($status & \ProcessWire\Page::statusHidden) {
            $statuses[] = 'hidden';
        }
        if ($status & \ProcessWire\Page::statusUnpublished) {
            $statuses[] = 'unpublished';
        }
        if ($status & \ProcessWire\Page::statusLocked) {
            $statuses[] = 'locked';
        }
        if ($status & \ProcessWire\Page::statusTrash) {
            $statuses[] = 'trash';
        }
        if ($status & \ProcessWire\Page::statusDraft) {
            $statuses[] = 'draft';
        }
        
        return empty($statuses) ? 'published' : implode(', ', $statuses);
    }
    
    /**
     * Format a field value for JSON output
     * 
     * Handles various ProcessWire field types and converts them to
     * JSON-serializable formats. Complex types like pages, files,
     * and repeaters are converted to structured arrays.
     * 
     * @param \ProcessWire\Field $field        Field definition
     * @param mixed              $value        Field value from page
     * @param bool               $includeFiles Include file/image details
     * @param int                $truncate     Truncate text to N characters (0 = no truncation)
     * @return mixed Formatted value suitable for JSON encoding
     */
    private function formatFieldValue($field, $value, bool $includeFiles = false, int $truncate = 0) {
        $type = $field->type->className();
        
        // Handle null/empty values
        if ($value === null || $value === '') {
            return null;
        }
        
        // Handle single page reference
        if ($value instanceof \ProcessWire\Page) {
            return [
                'id' => $value->id,
                'title' => $value->title,
                'path' => $value->path,
            ];
        }
        
        // Handle repeater/matrix fields BEFORE generic PageArray
        // RepeaterMatrixPageArray extends PageArray (not RepeaterPageArray), so we check class name
        if ($value instanceof \ProcessWire\RepeaterPageArray || 
            (is_object($value) && $value instanceof \ProcessWire\PageArray && 
             strpos(get_class($value), 'Repeater') !== false)) {
            return $this->formatRepeaterItems($field, $value, $includeFiles, $truncate);
        }
        
        // Handle page array (multi-page reference)
        if ($value instanceof \ProcessWire\PageArray) {
            $pages = [];
            foreach ($value as $p) {
                $pages[] = [
                    'id' => $p->id,
                    'title' => $p->title,
                    'path' => $p->path,
                ];
            }
            return $pages;
        }
        
        // Handle images and files
        if ($value instanceof \ProcessWire\Pagefiles || $value instanceof \ProcessWire\Pageimages) {
            $count = $value->count();
            
            // Always include count and filenames
            $filenames = [];
            foreach ($value as $file) {
                $filenames[] = $file->name;
            }
            
            // By default, return count + filenames (lightweight)
            if (!$includeFiles) {
                return [
                    '_count' => $count,
                    '_files' => $filenames,
                ];
            }
            
            // Return full file metadata when requested with --include=files
            $files = [];
            foreach ($value as $file) {
                $fileData = [
                    'filename' => $file->name,
                    'basename' => $file->basename,
                    'url' => $file->url,
                    'httpUrl' => $file->httpUrl,
                    'size' => $file->filesize,
                    'sizeStr' => \ProcessWire\wireBytesStr($file->filesize),
                    'description' => $file->description ?: null,
                    'modified' => date('c', $file->modified),
                ];
                
                // Add image-specific properties
                if ($file instanceof \ProcessWire\Pageimage) {
                    $fileData['width'] = $file->width;
                    $fileData['height'] = $file->height;
                    $fileData['ratio'] = round($file->width / max($file->height, 1), 2);
                }
                
                $files[] = $fileData;
            }
            
            return [
                '_count' => $count,
                '_files' => $filenames,
                '_details' => $files,
            ];
        }
        
        // Handle generic WireArray
        if ($value instanceof \ProcessWire\WireArray) {
            return $value->getArray();
        }
        
        // Default: return as-is (strings, numbers, booleans)
        // Apply truncation to strings if requested
        if ($truncate > 0 && is_string($value) && strlen($value) > $truncate) {
            // Strip HTML tags before truncating for cleaner output
            $plainText = strip_tags($value);
            $totalLength = strlen($plainText);
            
            if ($totalLength > $truncate) {
                $remaining = $totalLength - $truncate;
                return substr($plainText, 0, $truncate) . "... [truncated at {$truncate} chars. {$remaining} remaining]";
            }
            return $plainText;
        }
        
        return $value;
    }
    
    /**
     * Format repeater/matrix items for output
     * 
     * Handles both regular Repeater fields and RepeaterMatrix fields.
     * For RepeaterMatrix, includes the type ID and type label.
     * 
     * @param \ProcessWire\Field     $field        Parent field definition
     * @param \ProcessWire\PageArray $repeater     Repeater items
     * @param bool                   $includeFiles Include file/image details
     * @param int                    $truncate     Truncate text to N characters (0 = no truncation)
     * @return array Formatted repeater with count and items
     */
    private function formatRepeaterItems($field, $repeater, bool $includeFiles = false, int $truncate = 0): array {
        $items = [];
        
        // Get matrix type labels if this is a RepeaterMatrix field
        $matrixTypes = $this->getMatrixTypeLabels($field);
        
        foreach ($repeater as $item) {
            // Get the matrix type info
            $typeId = $item->get('repeater_matrix_type');
            
            $itemData = [];
            
            // Include type info for RepeaterMatrix
            if ($typeId !== null) {
                $itemData['_typeId'] = (int) $typeId;
                $itemData['_typeLabel'] = $matrixTypes[$typeId] ?? "Type $typeId";
            }
            
            // Get all field values for this repeater item
            foreach ($item->template->fields as $f) {
                // Skip internal repeater fields
                if (strpos($f->name, 'repeater_') === 0) {
                    continue;
                }
                $itemData[$f->name] = $this->formatFieldValue($f, $item->get($f->name), $includeFiles, $truncate);
            }
            
            $items[] = $itemData;
        }
        
        return [
            '_count' => count($items),
            '_items' => $items,
        ];
    }
    
    /**
     * Get matrix type labels from a RepeaterMatrix field
     * 
     * RepeaterMatrix fields have configured types with labels.
     * This extracts them for display in output.
     * 
     * @param \ProcessWire\Field $field Field to get types from
     * @return array Associative array of typeId => label
     */
    private function getMatrixTypeLabels($field): array {
        $labels = [];
        
        // Method 1: Try the fieldtype's getMatrixTypes method
        $fieldtype = $field->get('type');
        if ($fieldtype && method_exists($fieldtype, 'getMatrixTypes')) {
            $types = $fieldtype->getMatrixTypes($field);
            if ($types) {
                foreach ($types as $typeId => $typeInfo) {
                    if (is_array($typeInfo)) {
                        $labels[$typeId] = $typeInfo['label'] ?? $typeInfo['name'] ?? "Type $typeId";
                    } elseif (is_object($typeInfo)) {
                        $labels[$typeId] = $typeInfo->label ?? $typeInfo->name ?? "Type $typeId";
                    }
                }
                if (!empty($labels)) {
                    return $labels;
                }
            }
        }
        
        // Method 2: Access matrix types from field data directly
        // RepeaterMatrix stores types as 'matrix1_name', 'matrix1_label', etc.
        for ($i = 1; $i <= 20; $i++) {
            $name = $field->get("matrix{$i}_name");
            $label = $field->get("matrix{$i}_label");
            if ($name || $label) {
                $labels[$i] = $label ?: $name ?: "Type $i";
            }
        }
        
        return $labels;
    }
    
    /**
     * Query pages using a ProcessWire selector
     * 
     * Finds pages matching the given selector string. Automatically
     * adds include=all to find unpublished/hidden pages too.
     * 
     * @param string $selector ProcessWire selector string
     * @return array Matching pages with count
     */
    private function queryPages(string $selector): array {
        // Add include=all if not specified to find unpublished pages too
        if (strpos($selector, 'include=') === false) {
            $selector .= ', include=all';
        }
        
        $pages = $this->wire->pages->find($selector);
        $results = [];
        
        foreach ($pages as $page) {
            $results[] = [
                'id' => $page->id,
                'name' => $page->name,
                'path' => $page->path,
                'template' => $page->template->name,
                'title' => $page->title,
            ];
        }
        
        return ['pages' => $results, 'count' => count($results)];
    }
    
    /**
     * Search page content across text fields
     * 
     * Searches title, body, summary and other common text fields
     * for pages containing the search term. Returns matching pages
     * with a content snippet.
     * 
     * @param string $query Search term
     * @param int    $limit Maximum results (default 20)
     * @return array Matching pages with snippets
     */
    private function searchContent(string $query, int $limit = 20): array {
        $results = [];
        
        // Find text-based fields to search
        $textFields = [];
        foreach ($this->wire->fields as $field) {
            $typeName = $field->type->className();
            // Include text, textarea, and similar text-based fields
            if (in_array($typeName, [
                'FieldtypeText',
                'FieldtypeTextarea',
                'FieldtypeTextLanguage',
                'FieldtypeTextareaLanguage',
                'FieldtypePageTitle',
                'FieldtypePageTitleLanguage',
            ])) {
                $textFields[] = $field->name;
            }
        }
        
        if (empty($textFields)) {
            return ['error' => 'No text fields found to search'];
        }
        
        // Build OR selector for all text fields
        // Using %= for case-insensitive contains
        $selectors = [];
        foreach ($textFields as $fieldName) {
            $selectors[] = "{$fieldName}%=" . $this->wire->sanitizer->selectorValue($query);
        }
        
        // Combine with OR groups
        $selector = implode('|', $selectors) . ", limit={$limit}, include=all";
        
        $pages = $this->wire->pages->find($selector);
        
        foreach ($pages as $page) {
            // Find which field matched and get a snippet
            $matchedField = null;
            $snippet = null;
            
            foreach ($textFields as $fieldName) {
                $value = $page->get($fieldName);
                if ($value && stripos($value, $query) !== false) {
                    $matchedField = $fieldName;
                    // Get snippet around the match (strip HTML, limit chars)
                    $plainText = strip_tags($value);
                    $pos = stripos($plainText, $query);
                    $start = max(0, $pos - 50);
                    $snippet = ($start > 0 ? '...' : '') . 
                               substr($plainText, $start, 150) . 
                               (strlen($plainText) > $start + 150 ? '...' : '');
                    break;
                }
            }
            
            $results[] = [
                'id' => $page->id,
                'title' => $page->title,
                'path' => $page->path,
                'template' => $page->template->name,
                'matchedField' => $matchedField,
                'snippet' => $snippet,
            ];
        }
        
        return [
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ];
    }
    
    /**
     * Search files and images across the site
     * 
     * Searches for files by filename pattern, extension, or description.
     * Returns matching files with their parent page context.
     * 
     * @param string $query Filename pattern or extension (e.g., "logo", ".pdf")
     * @param int    $limit Maximum results (default 20)
     * @return array Matching files with page context
     */
    private function searchFiles(string $query, int $limit = 20): array {
        $results = [];
        $count = 0;
        
        // Find file/image fields
        $fileFields = [];
        foreach ($this->wire->fields as $field) {
            $typeName = $field->type->className();
            if (in_array($typeName, [
                'FieldtypeFile',
                'FieldtypeImage',
                'FieldtypeCroppableImage3',
            ])) {
                $fileFields[] = $field->name;
            }
        }
        
        if (empty($fileFields)) {
            return ['error' => 'No file/image fields found'];
        }
        
        // Determine if searching by extension
        $isExtensionSearch = strpos($query, '.') === 0;
        $searchPattern = strtolower($query);
        
        // Search all pages with file fields
        $fieldList = implode('|', $fileFields);
        $pages = $this->wire->pages->find("({$fieldList}!=''), include=all");
        
        foreach ($pages as $page) {
            if ($count >= $limit) break;
            
            foreach ($fileFields as $fieldName) {
                if ($count >= $limit) break;
                
                $files = $page->get($fieldName);
                if (!$files || !($files instanceof \ProcessWire\Pagefiles)) continue;
                
                foreach ($files as $file) {
                    if ($count >= $limit) break;
                    
                    $filename = strtolower($file->name);
                    $description = strtolower($file->description ?: '');
                    
                    $matches = false;
                    if ($isExtensionSearch) {
                        // Match by extension (e.g., ".pdf")
                        $matches = substr($filename, -strlen($searchPattern)) === $searchPattern;
                    } else {
                        // Match by filename or description
                        $matches = strpos($filename, $searchPattern) !== false || 
                                   strpos($description, $searchPattern) !== false;
                    }
                    
                    if ($matches) {
                        $fileData = [
                            'filename' => $file->name,
                            'url' => $file->url,
                            'size' => $file->filesize,
                            'sizeStr' => \ProcessWire\wireBytesStr($file->filesize),
                            'description' => $file->description ?: null,
                            'field' => $fieldName,
                            'page' => [
                                'id' => $page->id,
                                'title' => $page->title,
                                'path' => $page->path,
                            ],
                        ];
                        
                        // Add image dimensions if applicable
                        if ($file instanceof \ProcessWire\Pageimage) {
                            $fileData['width'] = $file->width;
                            $fileData['height'] = $file->height;
                        }
                        
                        $results[] = $fileData;
                        $count++;
                    }
                }
            }
        }
        
        return [
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ];
    }
    
    // ========================================================================
    // SYNC COMMANDS
    // ========================================================================
    
    /**
     * Pull a page into local sync directory
     * 
     * Creates a mirrored directory structure with page.meta.json
     * (identity/sync state) and page.yaml (editable content).
     * 
     * @param int|string $idOrPath Page ID or path
     * @param string $format Content format (yaml or json)
     * @param string|null $root Custom sync root directory
     * @return array Result with file paths
     */
    private function pagePull($idOrPath, string $format = 'yaml', ?string $root = null): array {
        // Load sync manager
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire, $root);
        return $syncManager->pullPage($idOrPath, $format);
    }
    
    /**
     * Push local changes back to ProcessWire
     * 
     * Reads local page.yaml and applies changes to the source page.
     * Dry-run mode is on by default for safety.
     * 
     * @param string $localPath Path to local sync directory or page.yaml
     * @param bool $dryRun Show changes without applying (default: true)
     * @param bool $force Force push even if remote has changed
     * @return array Result with changes applied or preview
     */
    private function pagePush(string $localPath, bool $dryRun = true, bool $force = false): array {
        // Load sync manager
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->pushPage($localPath, $dryRun, $force);
    }
    
    /**
     * Pull multiple pages matching a selector
     * 
     * Supports:
     * - ProcessWire selector: "template=blog-post"
     * - Parent path: "/medical-negligence-claims/"
     * - Template shorthand: "blog-post"
     * 
     * @param string $selector Selector, parent path, or template
     * @param string $format Content format (yaml or json)
     * @param bool $includeParent Include parent when pulling by path
     * @param int $limit Maximum pages to pull
     * @return array Results with pulled/skipped/failed counts
     */
    private function pagesPull(string $selector, string $format = 'yaml', bool $includeParent = true, int $limit = 0): array {
        // Load sync manager
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->pullPages($selector, $format, $includeParent, $limit);
    }
    
    /**
     * Push all local changes in a directory tree
     * 
     * Scans for all page.meta.json files and pushes each page.
     * Dry-run mode is on by default for safety.
     * 
     * @param string $directory Directory to scan (default: site/syncs)
     * @param bool $dryRun Show changes without applying (default: true)
     * @param bool $force Force push even if remote has changed
     * @return array Results with push status for each page
     */
    private function pagesPush(string $directory, bool $dryRun = true, bool $force = false): array {
        // Load sync manager
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->pushPages($directory, $dryRun, $force);
    }
    
    /**
     * Check sync status of all pulled pages
     * 
     * Reports which pages have local changes, remote changes,
     * conflicts, or are orphaned.
     * 
     * @param string|null $directory Directory to scan (default: site/syncs)
     * @return array Status report for all synced pages
     */

    /**
     * Check whether a list of page paths exist on this site.
     * Used by pw_validate_refs to confirm page references are resolvable.
     *
     * @param array $paths Absolute PW page paths e.g. ["/services/foo/", "/about/"]
     * @return array { results: { "/path/": { exists, id, title, published, template } } }
     */
    private function pageExists(array $paths): array {
        $results = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            $page = $this->wire->pages->get($path);
            if ($page && $page->id) {
                $results[$path] = [
                    'exists'    => true,
                    'id'        => $page->id,
                    'title'     => (string) $page->title,
                    'published' => !$page->isUnpublished(),
                    'template'  => (string) $page->template,
                ];
            } else {
                $results[$path] = ['exists' => false];
            }
        }
        return ['results' => $results];
    }

    private function syncStatus(?string $directory = null): array {
        // Load sync manager
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->getSyncStatus($directory);
    }
    
    /**
     * Reconcile local sync directories with ProcessWire
     * 
     * Detects and fixes path drift (page moved/renamed) and orphans
     * (page deleted in ProcessWire but local folder remains).
     * 
     * @param string|null $directory Directory to scan (default: site/syncs)
     * @param bool $dryRun Preview changes without applying (default: true)
     * @return array Reconciliation report
     */
    private function syncReconcile(?string $directory = null, bool $dryRun = true): array {
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->reconcile($directory, $dryRun);
    }
    
    // ========================================================================
    // PHASE 3: PAGE CREATION & PUBLISHING
    // ========================================================================
    
    /**
     * Create a new page scaffold locally
     * 
     * Generates page.meta.json and page.yaml for a new page
     * that can be published to ProcessWire.
     * 
     * @param string $template Template name
     * @param string $parentPath Parent page path
     * @param string $pageName URL-safe page name
     * @param string|null $title Optional page title
     * @return array Result with created file paths
     */
    private function pageNew(string $template, string $parentPath, string $pageName, ?string $title = null): array {
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->createPageScaffold($template, $parentPath, $pageName, $title);
    }
    
    /**
     * Initialise or repair page.meta.json for a sync directory
     */
    private function pageInit(string $localPath, ?string $template = null): array {
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->initPageMeta($localPath, $template);
    }
    
    /**
     * Publish a new page to ProcessWire
     * 
     * Creates the page from local YAML files.
     * Only works for pages marked with new: true.
     * 
     * @param string $localPath Path to local page directory
     * @param bool $dryRun Preview without creating (default: true)
     * @param bool $unpublished Create as unpublished (default: true)
     * @return array Result with created page info
     */
    private function pagePublish(string $localPath, bool $dryRun = true, bool $unpublished = true): array {
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->publishPage($localPath, $dryRun, $unpublished);
    }
    
    /**
     * Bulk publish new pages
     * 
     * Finds all pages marked with new: true and publishes them.
     * 
     * @param string $directory Directory to scan
     * @param bool $dryRun Preview without creating (default: true)
     * @param bool $unpublished Create as unpublished (default: true)
     * @return array Results with created page info
     */
    private function pagesPublish(string $directory, bool $dryRun = true, bool $unpublished = true): array {
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PromptWire\Sync\SyncManager($this->wire);
        return $syncManager->publishPages($directory, $dryRun, $unpublished);
    }
    
    // ========================================================================
    // PHASE 4: DIRECT WRITE TOOLS
    // ========================================================================
    
    /**
     * Get detailed information about a matrix/repeater field structure
     * 
     * Discovers all matrix types, their fields, and any nested repeaters.
     * Useful for understanding field structure before adding content.
     * 
     * @param int|string $pageIdOrPath Page ID or path
     * @param string $fieldName Matrix/repeater field name
     * @return array Field structure information
     */
    private function matrixInfo($pageIdOrPath, string $fieldName): array {
        // Get the page
        if (is_numeric($pageIdOrPath)) {
            $page = $this->wire->pages->get((int) $pageIdOrPath);
        } else {
            $page = $this->wire->pages->get($pageIdOrPath);
        }
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $pageIdOrPath"];
        }
        
        // Verify the field exists on this page's template
        $field = $this->wire->fields->get($fieldName);
        if (!$field) {
            return ['error' => "Field not found: $fieldName"];
        }
        
        if (!$page->template->hasField($field)) {
            // List available fields on this template
            $availableFields = [];
            foreach ($page->template->fields as $f) {
                $availableFields[] = $f->name;
            }
            return [
                'error' => "Field '$fieldName' is not on template '{$page->template->name}'",
                'availableFields' => $availableFields,
            ];
        }
        
        $fieldType = $field->type->className();
        $isMatrix = ($fieldType === 'FieldtypeRepeaterMatrix');
        $isRepeater = ($fieldType === 'FieldtypeRepeater');
        
        if (!$isMatrix && !$isRepeater) {
            return [
                'error' => "Field '$fieldName' is not a matrix or repeater field",
                'fieldType' => $fieldType,
            ];
        }
        
        $result = [
            'page' => [
                'id' => $page->id,
                'path' => $page->path,
                'template' => $page->template->name,
            ],
            'field' => [
                'name' => $fieldName,
                'type' => $isMatrix ? 'RepeaterMatrix' : 'Repeater',
                'label' => $field->label ?: $fieldName,
            ],
        ];
        
        if ($isMatrix) {
            // Get all matrix types
            $types = [];
            for ($i = 1; $i <= 20; $i++) {
                $typeName = $field->get("matrix{$i}_name");
                $typeLabel = $field->get("matrix{$i}_label");
                
                if ($typeName) {
                    $typeInfo = [
                        'id' => $i,
                        'name' => $typeName,
                        'label' => $typeLabel ?: $typeName,
                        'fields' => [],
                        'nestedRepeaters' => [],
                    ];
                    
                    // Get fields for this matrix type from its template
                    // Matrix items use a shared template, fields are determined by config
                    // Note: matrix{$i}_fields contains field IDs, not names
                    $typeFields = $field->get("matrix{$i}_fields");
                    if ($typeFields) {
                        // typeFields can be array or space-separated string of field IDs
                        $fieldIds = is_array($typeFields) ? $typeFields : explode(' ', $typeFields);
                        foreach ($fieldIds as $fid) {
                            $fid = trim($fid);
                            if (!$fid) continue;
                            
                            // Get field by ID (could be numeric ID or name)
                            $subField = $this->wire->fields->get((int)$fid ?: $fid);
                            if ($subField) {
                                $subFieldType = $subField->type->className();
                                $fieldInfo = [
                                    'name' => $subField->name,  // Use actual field name
                                    'type' => $subFieldType,
                                    'label' => $subField->label ?: $subField->name,
                                ];
                                
                                // Check for nested repeaters
                                if ($subFieldType === 'FieldtypeRepeater' || $subFieldType === 'FieldtypeRepeaterMatrix') {
                                    $nestedFields = $this->getRepeaterFields($subField);
                                    $typeInfo['nestedRepeaters'][$subField->name] = [
                                        'type' => $subFieldType === 'FieldtypeRepeaterMatrix' ? 'RepeaterMatrix' : 'Repeater',
                                        'fields' => $nestedFields,
                                    ];
                                }
                                
                                $typeInfo['fields'][] = $fieldInfo;
                            }
                        }
                    }
                    
                    $types[] = $typeInfo;
                }
            }
            $result['matrixTypes'] = $types;
        } else {
            // Regular repeater - get its fields
            $result['fields'] = $this->getRepeaterFields($field);
        }
        
        // Get current item count
        $matrix = $page->get($fieldName);
        $result['currentItemCount'] = $matrix ? $matrix->count() : 0;
        
        return $result;
    }
    
    /**
     * Get fields from a repeater field's template
     * 
     * @param \ProcessWire\Field $field Repeater field
     * @return array List of field info
     */
    private function getRepeaterFields($field): array {
        $fields = [];
        
        // Get the repeater's template
        $templateId = $field->get('template_id');
        if ($templateId) {
            $template = $this->wire->templates->get($templateId);
            if ($template) {
                foreach ($template->fields as $f) {
                    // Skip internal repeater fields
                    if (strpos($f->name, 'repeater_') === 0) continue;
                    
                    $fields[] = [
                        'name' => $f->name,
                        'type' => $f->type->className(),
                        'label' => $f->label ?: $f->name,
                    ];
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Add a new matrix item to a page
     * 
     * Creates a new repeater/matrix item with the specified type and content.
     * Uses ProcessWire's native API to ensure proper item creation.
     * 
     * @param int|string $pageIdOrPath Page ID or path
     * @param string $fieldName Matrix/repeater field name
     * @param string $matrixType Matrix type name (e.g., 'faq', 'body', 'cta')
     * @param array $content Field values for the new item
     * @param bool $dryRun Preview without creating (default: true)
     * @return array Result with created item info
     */
    private function matrixAdd($pageIdOrPath, string $fieldName, string $matrixType, array $content, bool $dryRun = true): array {
        // Get the page
        if (is_numeric($pageIdOrPath)) {
            $page = $this->wire->pages->get((int) $pageIdOrPath);
        } else {
            $page = $this->wire->pages->get($pageIdOrPath);
        }
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $pageIdOrPath"];
        }
        
        // Verify the field exists on this page's template
        $field = $this->wire->fields->get($fieldName);
        if (!$field) {
            return ['error' => "Field not found: $fieldName"];
        }
        
        if (!$page->template->hasField($field)) {
            return ['error' => "Field '$fieldName' is not on template '{$page->template->name}'"];
        }
        
        // Verify it's a repeater/matrix field
        $fieldType = $field->type->className();
        if (!in_array($fieldType, ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix'])) {
            return ['error' => "Field '$fieldName' is not a repeater/matrix field (type: $fieldType)"];
        }
        
        // Get the matrix field value
        $matrix = $page->get($fieldName);
        
        // For RepeaterMatrix, verify the type exists
        $isMatrix = ($fieldType === 'FieldtypeRepeaterMatrix');
        $matrixTypeId = null;
        
        if ($isMatrix) {
            // Find the matrix type ID by name
            for ($i = 1; $i <= 20; $i++) {
                $typeName = $field->get("matrix{$i}_name");
                if ($typeName === $matrixType) {
                    $matrixTypeId = $i;
                    break;
                }
            }
            
            if ($matrixTypeId === null) {
                // List available types for helpful error
                $availableTypes = [];
                for ($i = 1; $i <= 20; $i++) {
                    $typeName = $field->get("matrix{$i}_name");
                    if ($typeName) {
                        $availableTypes[] = $typeName;
                    }
                }
                return [
                    'error' => "Matrix type '$matrixType' not found on field '$fieldName'",
                    'availableTypes' => $availableTypes,
                ];
            }
        }
        
        // Dry-run: show what would be created
        if ($dryRun) {
            // Analyze content to detect nested repeaters
            $contentAnalysis = [];
            foreach ($content as $subFieldName => $value) {
                $subField = $this->wire->fields->get($subFieldName);
                $subFieldType = $subField ? $subField->type->className() : 'unknown';
                $isNestedRepeater = in_array($subFieldType, ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix']);
                
                if ($isNestedRepeater && is_array($value) && !empty($value)) {
                    $firstItem = reset($value);
                    if (is_array($firstItem)) {
                        $contentAnalysis[$subFieldName] = [
                            'type' => 'nested_repeater',
                            'itemCount' => count($value),
                            'subFields' => array_keys($firstItem),
                        ];
                        continue;
                    }
                }
                
                $contentAnalysis[$subFieldName] = [
                    'type' => 'simple',
                    'fieldType' => $subFieldType,
                    'preview' => is_string($value) ? substr($value, 0, 50) . (strlen($value) > 50 ? '...' : '') : gettype($value),
                ];
            }
            
            return [
                'success' => true,
                'dryRun' => true,
                'message' => 'Matrix item would be created (dry-run mode)',
                'page' => [
                    'id' => $page->id,
                    'path' => $page->path,
                    'title' => $page->title,
                ],
                'field' => $fieldName,
                'matrixType' => $matrixType,
                'matrixTypeId' => $matrixTypeId,
                'contentAnalysis' => $contentAnalysis,
                'currentItemCount' => $matrix->count(),
                'newItemPosition' => $matrix->count() + 1,
            ];
        }
        
        // Create the new matrix item
        if ($isMatrix) {
            $newItem = $matrix->getNew($matrixType);
            // Explicitly set the matrix type ID (some PW versions need this)
            if ($newItem && $matrixTypeId) {
                $newItem->set('repeater_matrix_type', $matrixTypeId);
            }
        } else {
            // Regular repeater
            $newItem = $matrix->getNew();
        }
        
        if (!$newItem) {
            return ['error' => 'Failed to create new matrix item'];
        }
        
        // Apply content values
        $appliedFields = [];
        $skippedFields = [];
        $nestedRepeaters = [];
        
        foreach ($content as $subFieldName => $value) {
            // Check if this field exists on the item's template
            if (!$newItem->template->hasField($subFieldName)) {
                $skippedFields[] = $subFieldName;
                continue;
            }
            
            // Check if this field is a nested repeater with array content
            $subField = $this->wire->fields->get($subFieldName);
            $subFieldType = $subField ? $subField->type->className() : null;
            $isNestedRepeater = in_array($subFieldType, ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix']);
            
            if ($isNestedRepeater && is_array($value) && !empty($value)) {
                // Check if it's an array of objects (associative arrays)
                $firstItem = reset($value);
                if (is_array($firstItem)) {
                    // This is a nested repeater - we'll populate it after the parent is saved
                    $nestedRepeaters[$subFieldName] = $value;
                    $appliedFields[] = $subFieldName . ' (nested repeater - ' . count($value) . ' items)';
                    continue;
                }
            }
            
            // Regular field - set directly
            $newItem->set($subFieldName, $value);
            $appliedFields[] = $subFieldName;
        }
        
        // Add to matrix and save (must save parent before populating nested repeaters)
        $matrix->add($newItem);
        $page->save($fieldName);
        
        // Now populate any nested repeaters
        $nestedResults = [];
        foreach ($nestedRepeaters as $nestedFieldName => $nestedItems) {
            $nestedRepeater = $newItem->get($nestedFieldName);
            if (!$nestedRepeater) {
                $nestedResults[$nestedFieldName] = ['error' => 'Could not get nested repeater field'];
                continue;
            }
            
            $createdCount = 0;
            foreach ($nestedItems as $itemData) {
                $nestedNewItem = $nestedRepeater->getNew();
                if (!$nestedNewItem) {
                    continue;
                }
                
                // Apply fields to nested item
                foreach ($itemData as $nestedSubField => $nestedValue) {
                    if ($nestedNewItem->template->hasField($nestedSubField)) {
                        $nestedNewItem->set($nestedSubField, $nestedValue);
                    }
                }
                
                $nestedRepeater->add($nestedNewItem);
                $createdCount++;
            }
            
            // Save the nested repeater
            $newItem->save($nestedFieldName);
            $nestedResults[$nestedFieldName] = ['created' => $createdCount];
        }
        
        $result = [
            'success' => true,
            'message' => 'Matrix item created successfully',
            'page' => [
                'id' => $page->id,
                'path' => $page->path,
                'title' => $page->title,
            ],
            'field' => $fieldName,
            'matrixType' => $matrixType,
            'newItem' => [
                'id' => $newItem->id,
                'position' => $matrix->count(),
            ],
            'appliedFields' => $appliedFields,
            'skippedFields' => $skippedFields,
        ];
        
        if (!empty($nestedResults)) {
            $result['nestedRepeaters'] = $nestedResults;
        }
        
        return $result;
    }
    
    /**
     * Apply a schema JSON file to this ProcessWire installation
     *
     * Reads the schema from a JSON file (written by the Node.js MCP tool)
     * and applies it — creating or updating fields and templates.
     *
     * @param string $schemaFile Absolute or relative path to schema JSON file
     * @param bool   $dryRun     Preview without applying (default: true)
     * @return array Import results per field and template
     */
    private function schemaApply(string $schemaFile, bool $dryRun = true): array
    {
        // Resolve relative paths against PW root
        if (!file_exists($schemaFile)) {
            $pwPath = rtrim($this->wire->config->paths->root, '/');
            $resolved = $pwPath . '/' . ltrim($schemaFile, '/');
            if (file_exists($resolved)) {
                $schemaFile = $resolved;
            } else {
                return ['error' => "Schema file not found: $schemaFile"];
            }
        }

        $json = file_get_contents($schemaFile);
        if (!$json) {
            return ['error' => "Could not read schema file: $schemaFile"];
        }

        $schema = json_decode($json, true);
        if (!is_array($schema)) {
            return ['error' => 'Invalid JSON in schema file'];
        }

        require_once(__DIR__ . '/../Schema/SchemaImporter.php');
        $importer = new \PromptWire\Schema\SchemaImporter($this->wire);
        return $importer->apply($schema, $dryRun);
    }

    /**
     * Export complete site schema
     * 
     * Exports all fields and templates as a structured schema.
     * Useful for documentation and understanding site structure.
     * 
     * @return array Complete schema with fields and templates
     */
    private function exportSchema(): array {
        // Load schema exporter classes
        require_once(__DIR__ . '/../Schema/FieldExporter.php');
        require_once(__DIR__ . '/../Schema/TemplateExporter.php');
        require_once(__DIR__ . '/../Schema/SchemaExporter.php');
        
        $exporter = new \PromptWire\Schema\SchemaExporter($this->wire);
        return $exporter->export();
    }
    
    // ========================================================================
    // PHASE 5: DATABASE, LOGS & CACHE
    // ========================================================================

    /**
     * Inspect database schema
     *
     * Lists all tables with their columns, types, keys, and nullable flags.
     * Optionally filter to a single table for detailed view.
     *
     * @param string|null $table Optional table name to inspect
     * @return array Schema information
     */
    private function dbSchema(?string $table = null): array {
        $db = $this->wire->database;
        $dbName = $this->wire->config->dbName;

        if ($table) {
            $stmt = $db->prepare(
                "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
                 ORDER BY ORDINAL_POSITION"
            );
            $stmt->execute([':db' => $dbName, ':tbl' => $table]);
            $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($columns)) {
                return ['error' => "Table not found: $table"];
            }

            $idxStmt = $db->prepare(
                "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list, NON_UNIQUE
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
                 GROUP BY INDEX_NAME, NON_UNIQUE"
            );
            $idxStmt->execute([':db' => $dbName, ':tbl' => $table]);
            $indexes = $idxStmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'table' => $table,
                'columns' => $columns,
                'indexes' => $indexes,
            ];
        }

        // All tables overview
        $stmt = $db->prepare(
            "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH,
                    AUTO_INCREMENT, TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db
             ORDER BY TABLE_NAME"
        );
        $stmt->execute([':db' => $dbName]);
        $tables = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'database' => $dbName,
            'tableCount' => count($tables),
            'tables' => $tables,
        ];
    }

    /**
     * Execute a read-only SELECT query
     *
     * Only SELECT statements are allowed. Mutations are rejected.
     *
     * @param string $sql SQL query
     * @param int $limit Maximum rows to return
     * @return array Query results
     */
    private function dbQuery(string $sql, int $limit = 100): array {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $sql)));

        // Block anything that isn't a SELECT or starts with a mutation keyword
        $forbidden = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
                       'CREATE', 'REPLACE', 'RENAME', 'GRANT', 'REVOKE', 'LOCK',
                       'UNLOCK', 'CALL', 'LOAD', 'SET '];
        foreach ($forbidden as $keyword) {
            if (strpos($normalized, $keyword) === 0) {
                return ['error' => "Only SELECT queries are allowed (blocked: $keyword)"];
            }
        }

        if (strpos($normalized, 'SELECT') !== 0 && strpos($normalized, 'SHOW') !== 0 &&
            strpos($normalized, 'DESCRIBE') !== 0 && strpos($normalized, 'EXPLAIN') !== 0) {
            return ['error' => 'Only SELECT, SHOW, DESCRIBE, and EXPLAIN statements are allowed'];
        }

        // Inject LIMIT if not present
        if (strpos($normalized, 'LIMIT') === false && strpos($normalized, 'SELECT') === 0) {
            $sql = rtrim($sql, '; ') . " LIMIT $limit";
        }

        try {
            $db = $this->wire->database;
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'query' => $sql,
                'rowCount' => count($rows),
                'rows' => $rows,
            ];
        } catch (\PDOException $e) {
            return [
                'error' => 'Query failed: ' . $e->getMessage(),
                'query' => $sql,
            ];
        }
    }

    /**
     * Run EXPLAIN on a query for performance analysis
     *
     * @param string $sql SQL query to explain
     * @return array EXPLAIN output
     */
    private function dbExplain(string $sql): array {
        $normalized = strtoupper(trim($sql));
        if (strpos($normalized, 'SELECT') !== 0) {
            return ['error' => 'EXPLAIN only works with SELECT queries'];
        }

        try {
            $db = $this->wire->database;
            $stmt = $db->prepare("EXPLAIN $sql");
            $stmt->execute();
            $plan = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'query' => $sql,
                'plan' => $plan,
            ];
        } catch (\PDOException $e) {
            return [
                'error' => 'EXPLAIN failed: ' . $e->getMessage(),
                'query' => $sql,
            ];
        }
    }

    /**
     * Get row counts for core ProcessWire tables
     *
     * Returns approximate row counts from information_schema plus
     * exact counts for key content tables.
     *
     * @return array Table row counts
     */
    private function dbCounts(): array {
        $db = $this->wire->database;
        $dbName = $this->wire->config->dbName;

        $coreTables = [
            'pages', 'fields', 'templates', 'fieldgroups', 'fieldgroups_fields',
            'modules', 'session', 'caches',
        ];

        $counts = [];
        foreach ($coreTables as $tbl) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM `$tbl`");
                $stmt->execute();
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $counts[$tbl] = (int) $row['cnt'];
            } catch (\PDOException $e) {
                $counts[$tbl] = null;
            }
        }

        // Also count field data tables (field_*)
        $stmt = $db->prepare(
            "SELECT TABLE_NAME, TABLE_ROWS
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME LIKE 'field_%'
             ORDER BY TABLE_ROWS DESC
             LIMIT 20"
        );
        $stmt->execute([':db' => $dbName]);
        $fieldTables = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'coreTables' => $counts,
            'topFieldTables' => $fieldTables,
        ];
    }

    /**
     * Read and filter ProcessWire log entries
     *
     * Reads from the PW log system (site/assets/logs/). Supports
     * filtering by log name, level, and text pattern.
     *
     * @param string|null $logName Log file name (e.g. 'errors', 'messages', 'exceptions')
     * @param string|null $level Filter by level (error, warning, info)
     * @param string|null $text Text pattern to search within entries
     * @param int $limit Maximum entries to return
     * @return array Log entries
     */
    private function readLogs(?string $logName = null, ?string $level = null, ?string $text = null, int $limit = 50): array {
        $logsPath = $this->wire->config->paths->assets . 'logs/';

        if (!is_dir($logsPath)) {
            return ['error' => 'Logs directory not found'];
        }

        // List available logs if no name specified
        if (!$logName) {
            $files = glob($logsPath . '*.txt');
            $available = [];
            foreach ($files as $file) {
                $name = basename($file, '.txt');
                $available[] = [
                    'name' => $name,
                    'size' => filesize($file),
                    'sizeStr' => $this->formatBytes(filesize($file)),
                    'modified' => date('c', filemtime($file)),
                ];
            }
            return [
                'availableLogs' => $available,
                'hint' => 'Specify a log name to read entries. Common logs: errors, messages, exceptions',
            ];
        }

        $logFile = $logsPath . $logName . '.txt';
        if (!file_exists($logFile)) {
            // Try without .txt extension
            $logFile = $logsPath . $logName;
            if (!file_exists($logFile)) {
                return ['error' => "Log file not found: $logName"];
            }
        }

        // Read lines from end of file (most recent first)
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return ['error' => "Could not read log file: $logName"];
        }

        $lines = array_reverse($lines);

        $entries = [];
        foreach ($lines as $line) {
            if (count($entries) >= $limit) break;

            $entry = $this->parseLogLine($line);
            if (!$entry) continue;

            if ($level && stripos($entry['level'] ?? '', $level) === false) continue;
            if ($text && stripos($entry['message'], $text) === false) continue;

            $entries[] = $entry;
        }

        return [
            'log' => $logName,
            'count' => count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * Parse a single ProcessWire log line
     *
     * PW log format: "2024-01-15 10:30:45 [user] message text"
     *
     * @param string $line Raw log line
     * @return array|null Parsed entry or null if unparseable
     */
    private function parseLogLine(string $line): ?array {
        // PW log format: "YYYY-MM-DD HH:MM:SS \t URL \t message"
        // or simply a timestamped line
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\t(.*)$/s', $line, $m)) {
            $timestamp = trim($m[1]);
            $rest = trim($m[2]);

            // The rest may be tab-separated: URL \t message
            $parts = explode("\t", $rest, 2);
            $url = count($parts) > 1 ? trim($parts[0]) : null;
            $message = count($parts) > 1 ? trim($parts[1]) : trim($parts[0]);

            // Derive level from common keywords in the message
            $levelGuess = 'info';
            $msgLower = strtolower($message);
            if (strpos($msgLower, 'error') !== false || strpos($msgLower, 'fatal') !== false ||
                strpos($msgLower, 'exception') !== false) {
                $levelGuess = 'error';
            } elseif (strpos($msgLower, 'warning') !== false || strpos($msgLower, 'deprecated') !== false) {
                $levelGuess = 'warning';
            }

            return [
                'timestamp' => $timestamp,
                'url' => $url,
                'message' => $message,
                'level' => $levelGuess,
            ];
        }

        // Fallback: return raw line
        if (strlen(trim($line)) > 0) {
            return [
                'timestamp' => null,
                'url' => null,
                'message' => trim($line),
                'level' => 'info',
            ];
        }

        return null;
    }

    /**
     * Get the most recent error from logs
     *
     * Scans errors.txt and exceptions.txt for the latest entry.
     *
     * @return array Most recent error or "no errors" message
     */
    private function lastError(): array {
        $logsPath = $this->wire->config->paths->assets . 'logs/';
        $errorLogs = ['errors', 'exceptions'];
        $latest = null;
        $latestTimestamp = 0;

        foreach ($errorLogs as $logName) {
            $logFile = $logsPath . $logName . '.txt';
            if (!file_exists($logFile)) continue;

            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (empty($lines)) continue;

            // Get last non-empty line
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $entry = $this->parseLogLine($lines[$i]);
                if ($entry && $entry['message']) {
                    $ts = $entry['timestamp'] ? strtotime($entry['timestamp']) : 0;
                    if ($ts >= $latestTimestamp) {
                        $latestTimestamp = $ts;
                        $latest = $entry;
                        $latest['source'] = $logName;
                    }
                    break;
                }
            }
        }

        if (!$latest) {
            return ['message' => 'No errors found in log files'];
        }

        return [
            'lastError' => $latest,
            'hint' => 'Use pw_logs with the log name for more context',
        ];
    }

    /**
     * Clear ProcessWire caches
     *
     * Supports selective or full cache clearing.
     *
     * @param string $target Cache target: all, modules, templates, compiled, wire-cache
     * @return array Result with what was cleared
     */
    private function clearCache(string $target = 'all'): array {
        $cleared = [];

        $validTargets = ['all', 'modules', 'templates', 'compiled', 'wire-cache'];
        if (!in_array($target, $validTargets)) {
            return [
                'error' => "Invalid cache target: $target",
                'validTargets' => $validTargets,
            ];
        }

        if ($target === 'all' || $target === 'modules') {
            $this->wire->modules->resetCache();
            $cleared[] = 'modules';
        }

        if ($target === 'all' || $target === 'templates') {
            // Clear FileCompiler cache for templates
            $compiledPath = $this->wire->config->paths->assets . 'cache/FileCompiler/';
            if (is_dir($compiledPath)) {
                $this->removeDir($compiledPath);
                $cleared[] = 'templates (FileCompiler)';
            } else {
                $cleared[] = 'templates (no compiled files found)';
            }
        }

        if ($target === 'all' || $target === 'compiled') {
            // Clear all compiled caches
            $cacheDirs = ['cache/FileCompiler/', 'cache/Latte/', 'cache/Page/'];
            foreach ($cacheDirs as $dir) {
                $fullPath = $this->wire->config->paths->assets . $dir;
                if (is_dir($fullPath)) {
                    $this->removeDir($fullPath);
                    $cleared[] = $dir;
                }
            }
        }

        if ($target === 'all' || $target === 'wire-cache') {
            // Clear WireCache (database-backed cache)
            $this->wire->cache->deleteAll();
            $cleared[] = 'wire-cache (database)';
        }

        return [
            'success' => true,
            'target' => $target,
            'cleared' => $cleared,
        ];
    }

    /**
     * Recursively remove directory contents (preserves the directory itself)
     *
     * @param string $dir Directory path
     */
    private function removeDir(string $dir): void {
        if (!is_dir($dir)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
    }

    /**
     * Format byte size to human-readable string
     *
     * @param int $bytes Size in bytes
     * @return string Formatted string (e.g., "1.5 MB")
     */
    private function formatBytes(int $bytes): string {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    // ========================================================================
    // SITE SYNC — MAINTENANCE MODE
    // ========================================================================

    /**
     * Enable maintenance mode.
     *
     * Creates a flag file that the PromptWire module checks on every
     * front-end request.  Superusers and API endpoint requests are
     * not affected.
     *
     * @param string|null $message Optional custom message (reserved for future use)
     * @return array Status
     */
    private function maintenanceOn(?string $message = null): array {
        $flagFile = $this->wire->config->paths->assets . 'cache/maintenance.flag';
        $data = [
            'enabledAt' => date('c'),
            'enabledBy' => 'PromptWire CLI',
            'message'   => $message,
        ];

        $dir = dirname($flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($flagFile, json_encode($data, JSON_PRETTY_PRINT));

        return [
            'success'     => true,
            'maintenance' => true,
            'flagFile'    => $flagFile,
            'enabledAt'   => $data['enabledAt'],
            'message'     => $message,
            'note'        => 'Front-end visitors will see the maintenance page. Superusers and API requests are unaffected.',
        ];
    }

    /**
     * Disable maintenance mode.
     *
     * Removes the flag file so normal front-end rendering resumes.
     *
     * @return array Status
     */
    private function maintenanceOff(): array {
        $flagFile = $this->wire->config->paths->assets . 'cache/maintenance.flag';

        if (!file_exists($flagFile)) {
            return [
                'success'     => true,
                'maintenance' => false,
                'note'        => 'Maintenance mode was not enabled.',
            ];
        }

        unlink($flagFile);

        return [
            'success'     => true,
            'maintenance' => false,
            'disabledAt'  => date('c'),
            'note'        => 'Maintenance mode disabled. Front-end is live.',
        ];
    }

    /**
     * Check whether maintenance mode is active.
     *
     * @return array Current status
     */
    private function maintenanceStatus(): array {
        $flagFile = $this->wire->config->paths->assets . 'cache/maintenance.flag';

        if (!file_exists($flagFile)) {
            return [
                'maintenance' => false,
                'note'        => 'Site is live.',
            ];
        }

        $data = json_decode(file_get_contents($flagFile), true) ?: [];

        return [
            'maintenance' => true,
            'enabledAt'   => $data['enabledAt'] ?? 'unknown',
            'enabledBy'   => $data['enabledBy'] ?? 'unknown',
            'message'     => $data['message'] ?? null,
            'note'        => 'Front-end visitors see the maintenance page. Superusers and API requests are unaffected.',
        ];
    }

    // ========================================================================
    // SITE SYNC — BACKUP & RESTORE
    // ========================================================================

    /**
     * Create a backup of the database (and optionally key files).
     *
     * Uses ProcessWire's built-in WireDatabaseBackup for the SQL dump,
     * then optionally creates a zip of site/templates and site/modules.
     *
     * @param string $description  Human-readable label for this backup
     * @param string $excludeTables  Comma-separated table names to skip
     * @param bool   $includeFiles   Whether to also back up template/module files
     * @return array
     */
    private function backupCreate(string $description = '', string $excludeTables = '', bool $includeFiles = true): array {
        $config  = $this->wire->config;
        $database = $this->wire->database;

        $backupDir = $config->paths->assets . 'backups/promptwire/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
            $htaccess = $backupDir . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "# Deny all web access to backup files\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
            }
        }

        $timestamp = date('Y-m-d_His');
        $label = $description ?: 'PromptWire backup';

        $backup = $database->backups();
        $backup->setPath($backupDir);
        $backup->setDatabase($database);
        $backup->setDatabaseConfig($config);

        $options = [
            'filename'    => "db-{$timestamp}.sql",
            'description' => $label,
        ];

        if ($excludeTables) {
            $options['excludeTables'] = array_map('trim', explode(',', $excludeTables));
        }

        $sqlFile = $backup->backup($options);
        $errors  = $backup->errors();

        if (!$sqlFile) {
            return [
                'success' => false,
                'error'   => 'Database backup failed: ' . implode('; ', $errors),
            ];
        }

        $result = [
            'success'  => true,
            'database' => [
                'file' => basename($sqlFile),
                'size' => filesize($sqlFile),
                'path' => $sqlFile,
            ],
        ];

        if ($includeFiles && class_exists('ZipArchive')) {
            $zipPath = $backupDir . "files-{$timestamp}.zip";
            $zip = new \ZipArchive();

            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $dirs = [
                    'site/templates' => $config->paths->site . 'templates',
                    'site/modules'   => $config->paths->site . 'modules',
                ];

                foreach ($dirs as $prefix => $dirPath) {
                    if (!is_dir($dirPath)) continue;
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $relativePath = $prefix . '/' . substr($file->getRealPath(), strlen(realpath($dirPath)) + 1);
                            $zip->addFile($file->getRealPath(), $relativePath);
                        }
                    }
                }

                $zip->close();
                $result['files'] = [
                    'file' => basename($zipPath),
                    'size' => filesize($zipPath),
                    'path' => $zipPath,
                ];
            } else {
                $result['files'] = ['warning' => 'Could not create file backup zip.'];
            }
        } elseif ($includeFiles) {
            $result['files'] = ['warning' => 'ZipArchive not available — file backup skipped.'];
        }

        $result['timestamp']   = $timestamp;
        $result['description'] = $label;

        return $result;
    }

    /**
     * List available PromptWire backups.
     *
     * @return array
     */
    private function backupList(): array {
        $backupDir = $this->wire->config->paths->assets . 'backups/promptwire/';

        if (!is_dir($backupDir)) {
            return ['backups' => [], 'count' => 0, 'path' => $backupDir];
        }

        $database = $this->wire->database;
        $backup   = $database->backups();
        $backup->setPath($backupDir);

        $groups = [];
        $files  = scandir($backupDir);
        sort($files);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fullPath = $backupDir . $file;

            if (preg_match('/^db-(\d{4}-\d{2}-\d{2}_\d{6})\.sql$/', $file, $m)) {
                $ts = $m[1];
                if (!isset($groups[$ts])) $groups[$ts] = ['timestamp' => $ts];
                $info = $backup->getFileInfo($fullPath);
                $groups[$ts]['database'] = [
                    'file'        => $file,
                    'size'        => filesize($fullPath),
                    'description' => $info['description'] ?? '',
                    'valid'       => $info['valid'] ?? false,
                    'tables'      => $info['numTables'] ?? null,
                ];
            } elseif (preg_match('/^files-(\d{4}-\d{2}-\d{2}_\d{6})\.zip$/', $file, $m)) {
                $ts = $m[1];
                if (!isset($groups[$ts])) $groups[$ts] = ['timestamp' => $ts];
                $groups[$ts]['files'] = [
                    'file' => $file,
                    'size' => filesize($fullPath),
                ];
            }
        }

        krsort($groups);

        return [
            'backups' => array_values($groups),
            'count'   => count($groups),
            'path'    => $backupDir,
        ];
    }

    /**
     * Restore a database backup.
     *
     * Accepts either a full filename (db-2026-04-21_231500.sql) or
     * just the timestamp portion (2026-04-21_231500).
     *
     * @param string $filename
     * @return array
     */
    private function backupRestore(string $filename): array {
        $backupDir = $this->wire->config->paths->assets . 'backups/promptwire/';

        if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $filename)) {
            $filename = "db-{$filename}.sql";
        }

        $fullPath = $backupDir . basename($filename);

        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => "Backup file not found: {$filename}"];
        }

        $database = $this->wire->database;
        $backup   = $database->backups();
        $backup->setPath($backupDir);
        $backup->setDatabase($database);
        $backup->setDatabaseConfig($this->wire->config);

        $success = $backup->restore($fullPath);
        $errors  = $backup->errors();

        if (!$success) {
            return [
                'success' => false,
                'error'   => 'Restore failed: ' . implode('; ', $errors),
            ];
        }

        return [
            'success'  => true,
            'restored' => basename($fullPath),
            'note'     => 'Database restored. File backup (if any) must be restored manually or via pw_site_sync.',
        ];
    }

    /**
     * Delete a backup (both SQL and zip if they share the same timestamp).
     *
     * @param string $filename  Full filename or timestamp
     * @return array
     */
    private function backupDelete(string $filename): array {
        $backupDir = $this->wire->config->paths->assets . 'backups/promptwire/';

        $timestamp = $filename;
        if (preg_match('/^(?:db|files)-(\d{4}-\d{2}-\d{2}_\d{6})/', $filename, $m)) {
            $timestamp = $m[1];
        }

        $deleted = [];
        $candidates = ["db-{$timestamp}.sql", "files-{$timestamp}.zip"];

        foreach ($candidates as $candidate) {
            $path = $backupDir . $candidate;
            if (file_exists($path) && unlink($path)) {
                $deleted[] = $candidate;
            }
        }

        if (empty($deleted)) {
            return ['success' => false, 'error' => "No backup files found for: {$filename}"];
        }

        return [
            'success' => true,
            'deleted' => $deleted,
        ];
    }

    // ========================================================================
    // SITE SYNC — FILE PUSH
    // ========================================================================

    /**
     * Write one or more files to the site.
     *
     * Accepts a JSON array of {relativePath, contentBase64} objects.
     * Validates that paths are within allowed directories and backs up
     * any files being overwritten.
     *
     * @param string $filesJson  JSON-encoded array of files to write
     * @param bool   $dryRun     If true, report what would happen without writing
     * @return array
     */
    private function filesPush(string $filesJson, bool $dryRun = true): array {
        $files = json_decode($filesJson, true);
        if (!is_array($files) || empty($files)) {
            return ['success' => false, 'error' => 'No files provided. Expected JSON array of {relativePath, contentBase64}.'];
        }

        $pwRoot = rtrim($this->wire->config->paths->root, '/') . '/';
        $allowedPrefixes = ['site/templates/', 'site/modules/', 'site/init.php', 'site/ready.php', 'site/finished.php'];

        $results   = [];
        $written   = 0;
        $skipped   = 0;
        $backedUp  = 0;

        $backupDir = '';
        if (!$dryRun) {
            $backupDir = $this->wire->config->paths->assets . 'backups/promptwire/file-push-' . date('Y-m-d_His') . '/';
        }

        foreach ($files as $file) {
            $relPath = $file['relativePath'] ?? '';
            $content = $file['contentBase64'] ?? '';

            if (!$relPath) {
                $results[] = ['path' => $relPath, 'status' => 'error', 'reason' => 'Missing relativePath'];
                $skipped++;
                continue;
            }

            $allowed = false;
            foreach ($allowedPrefixes as $prefix) {
                if (strpos($relPath, $prefix) === 0) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                $results[] = ['path' => $relPath, 'status' => 'denied', 'reason' => 'Path outside allowed directories'];
                $skipped++;
                continue;
            }

            if (strpos($relPath, '..') !== false) {
                $results[] = ['path' => $relPath, 'status' => 'denied', 'reason' => 'Path traversal not allowed'];
                $skipped++;
                continue;
            }

            $fullPath = $pwRoot . $relPath;
            $exists = file_exists($fullPath);

            if ($dryRun) {
                $results[] = [
                    'path'   => $relPath,
                    'status' => $exists ? 'would_overwrite' : 'would_create',
                    'size'   => strlen(base64_decode($content)),
                ];
                $written++;
                continue;
            }

            if ($exists && $backupDir) {
                $backupPath = $backupDir . $relPath;
                $backupParent = dirname($backupPath);
                if (!is_dir($backupParent)) mkdir($backupParent, 0755, true);
                copy($fullPath, $backupPath);
                $backedUp++;
            }

            $dir = dirname($fullPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $decoded = base64_decode($content);
            if ($decoded === false) {
                $results[] = ['path' => $relPath, 'status' => 'error', 'reason' => 'Invalid base64 content'];
                $skipped++;
                continue;
            }

            file_put_contents($fullPath, $decoded);
            $results[] = [
                'path'   => $relPath,
                'status' => $exists ? 'overwritten' : 'created',
                'size'   => strlen($decoded),
            ];
            $written++;
        }

        return [
            'success'  => $skipped === 0,
            'dryRun'   => $dryRun,
            'written'  => $written,
            'skipped'  => $skipped,
            'backedUp' => $backedUp,
            'backupDir'=> $backupDir ?: null,
            'files'    => $results,
        ];
    }

    // ========================================================================
    // SITE SYNC — INVENTORY & COMPARISON
    // ========================================================================

    /**
     * Build a compact inventory of every page on the site.
     *
     * Returns path, template, modified timestamp, and a content hash for
     * each page.  The hash is an MD5 of the page's serialised field values
     * so two sites can be compared without transferring full content.
     *
     * @param string $excludeTemplates  Comma-separated template names or
     *                                  wildcards (e.g. "user,license_*")
     * @param bool   $includeSystem     Include system pages (admin, trash)
     * @return array Page inventory manifest
     */
    /**
     * Normalise a field value for inclusion in the content hash.
     *
     * Goals (in order of priority):
     *   1. Same logical content → same hash, regardless of which site it
     *      came from. PW's internal ordering, output formatters, and
     *      database row positions must NOT leak into the hash.
     *   2. Cheap to compute (siteInventory walks every page in the site;
     *      anything more expensive than O(n) per field would dominate
     *      the request).
     *   3. Forward-compatible. Add new field-type branches here without
     *      changing existing branches' output, so previously-stable
     *      hashes don't shift between releases.
     *
     * Normalisations applied:
     *   - PageArray         → sorted, pipe-joined paths (was: array of
     *                         paths in PW storage order, which differs
     *                         between sites that pulled the same content).
     *   - Pagefiles/images  → sorted "basename:size" pairs (was:
     *                         storage-order array; storage order is
     *                         observable in some queries but not stable
     *                         across reseeds or admin re-uploads).
     *   - Datetime fields   → ISO 8601 UTC string. PW emits dates as
     *                         either Unix epoch ints OR formatted
     *                         strings depending on the field's
     *                         outputFormat — that single decision was
     *                         responsible for ~half the phantom diffs
     *                         seen during the peterknight.digital
     *                         migration.
     *   - Boolean ints      → preserved as 0/1 (don't coerce, keeps
     *                         existing hashes stable for non-affected
     *                         field types).
     *
     * @param \ProcessWire\Field $field The field metadata (used to detect
     *                                  Datetime so we don't incorrectly
     *                                  ISO-format an integer Page reference).
     * @param mixed              $value Raw value from $page->get($name).
     * @return mixed Hash-stable representation.
     */
    private function normaliseValueForHash(\ProcessWire\Field $field, $value) {
        if ($value === null || $value === '') {
            return null;
        }

        // Datetime: always normalise to ISO 8601 UTC. PW returns these as
        // either an integer epoch or a formatted string depending on the
        // field's outputFormat — both are valid and produce different
        // hashes from each other for the same logical timestamp.
        if ($field->type instanceof \ProcessWire\FieldtypeDatetime) {
            $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
            return $ts > 0 ? gmdate('c', $ts) : (string) $value;
        }

        // PageArray: sort by path and pipe-join. Pipe is illegal in PW
        // page paths so it's a safe separator. Sort is critical:
        // PageArray storage order is set by the underlying selector or
        // sort field, and two sites that pulled the same content can
        // legitimately store it in different orders.
        if ($value instanceof \ProcessWire\PageArray) {
            $paths = $value->explode('path');
            sort($paths, SORT_STRING);
            return implode('|', $paths);
        }

        // Pagefiles / Pageimages: sort by basename for determinism.
        // basename:size still detects content changes but no longer
        // depends on upload order or PW's internal sort field.
        if ($value instanceof \ProcessWire\Pagefiles || $value instanceof \ProcessWire\Pageimages) {
            $entries = [];
            foreach ($value as $f) {
                $entries[] = $f->basename . ':' . $f->filesize();
            }
            sort($entries, SORT_STRING);
            return $entries;
        }

        if (is_object($value)) {
            return (string) $value;
        }

        return $value;
    }

    private function siteInventory(string $excludeTemplates = '', bool $includeSystem = false): array {
        $excludeList = array_filter(array_map('trim', explode(',', $excludeTemplates)));

        $systemTemplates = ['admin', 'user', 'role', 'permission'];
        $selector = 'include=all';
        if (!$includeSystem) {
            foreach ($systemTemplates as $st) {
                $selector .= ", template!=$st";
            }
            $selector .= ', has_parent!=2'; // exclude admin branch
        }

        $allPages = $this->wire->pages->find($selector);
        $pages = [];

        foreach ($allPages as $page) {
            $templateName = $page->template->name;

            if ($this->matchesExcludeList($templateName, $excludeList)) {
                continue;
            }

            // Build a content hash from all custom field values.
            //
            // v1.8.4 — every value is normalised to a representation that is
            // (a) deterministic (no internal ordering leaks), (b) timezone- &
            // outputFormat-agnostic, and (c) stable across PW point releases.
            // Without these normalisations identical pages on two sites can
            // produce different hashes purely because of date output format
            // or PageArray storage order — that's the "phantom diff" bug
            // the operator hit while comparing peterknight.digital local vs
            // production after a fresh sync.
            $fieldData = [];
            foreach ($page->template->fieldgroup as $field) {
                if ($field->name === 'title') {
                    $fieldData['title'] = (string) $page->title;
                    continue;
                }
                $value = $page->get($field->name);
                $fieldData[$field->name] = $this->normaliseValueForHash($field, $value);
            }

            // Stable key order so two sites whose field positions differ
            // (e.g. one has been re-ordered in the admin) still hash the
            // same content to the same value.
            ksort($fieldData);

            $contentHash = md5(json_encode(
                $fieldData,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            // v1.8.4 — modified / created emitted as UTC ISO 8601 so two
            // sites with different server timezones produce identical
            // strings for the same physical timestamp. The diff engine
            // compares strings; without UTC normalisation, a local box on
            // BST and a production server on UTC would phantom-flag every
            // page as "modified" purely due to formatting.
            $pages[] = [
                'id'          => $page->id,
                'path'        => $page->path,
                'template'    => $templateName,
                'status'      => $page->status,
                'modified'    => gmdate('c', $page->modified),
                'created'     => gmdate('c', $page->created),
                'contentHash' => $contentHash,
            ];
        }

        // Stable page order so two inventories taken back-to-back from the
        // same site (or one local + one remote) produce a deterministic
        // ordering for downstream diff tools that don't sort themselves.
        usort($pages, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return [
            'siteName'    => $this->wire->config->httpHost ?: basename($this->wire->config->paths->root),
            'generatedAt' => gmdate('c'),
            'pageCount'   => count($pages),
            'excluded'    => $excludeList,
            'pages'       => $pages,
        ];
    }

    /**
     * Build a manifest of files in specified site directories.
     *
     * Scans directories (relative to PW root) and returns each file's
     * relative path, size, MD5 hash, and modification time so two sites
     * can be compared without transferring file contents.
     *
     * @param string $directories      Comma-separated directory paths
     * @param string $extensions       Comma-separated file extensions to include
     * @param string $excludePatterns  Comma-separated glob patterns to skip
     * @param bool   $followSymlinks   Whether to follow symlinked directories (default true).
     *                                 Symlinked module folders (e.g. shared modules under
     *                                 development) appear as their own physical files in the
     *                                 inventory; without this flag they were silently skipped
     *                                 in v1.7.x and earlier.
     * @return array File inventory manifest
     */
    private function filesInventory(
        string $directories = 'site/templates,site/modules',
        string $extensions = 'php,js,css,json,latte,twig,module',
        string $excludePatterns = '',
        bool $followSymlinks = true
    ): array {
        $rootPath = $this->wire->config->paths->root;
        $dirList = array_filter(array_map('trim', explode(',', $directories)));
        $extList = array_filter(array_map('trim', explode(',', $extensions)));
        $excludes = array_filter(array_map('trim', explode(',', $excludePatterns)));

        $files = [];
        $visitedRealPaths = []; // loop guard when following symlinks

        $iteratorFlags = \RecursiveDirectoryIterator::SKIP_DOTS;
        if ($followSymlinks) {
            $iteratorFlags |= \RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
        }

        foreach ($dirList as $dir) {
            $absDir = $rootPath . ltrim($dir, '/');
            if (!is_dir($absDir)) {
                continue;
            }

            $baseIterator = new \RecursiveDirectoryIterator($absDir, $iteratorFlags);

            $prunedIterator = new \RecursiveCallbackFilterIterator(
                $baseIterator,
                function ($current) {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), self::INVENTORY_PRUNE_DIRS, true);
                    }
                    return true;
                }
            );

            $iterator = new \RecursiveIteratorIterator(
                $prunedIterator,
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDir()) continue;

                $ext = strtolower($fileInfo->getExtension());
                if (!empty($extList) && !in_array($ext, $extList)) continue;

                if ($followSymlinks) {
                    $real = $fileInfo->getRealPath();
                    if ($real !== false) {
                        if (isset($visitedRealPaths[$real])) continue;
                        $visitedRealPaths[$real] = true;
                    }
                }

                $relativePath = $dir . '/' . ltrim(
                    str_replace($absDir, '', $fileInfo->getPathname()),
                    '/'
                );

                if ($this->matchesExcludePatterns($relativePath, $excludes)) continue;

                $files[] = [
                    'relativePath' => $relativePath,
                    'size'         => $fileInfo->getSize(),
                    'md5'          => md5_file($fileInfo->getPathname()),
                    'modified'     => date('c', $fileInfo->getMTime()),
                ];
            }
        }

        usort($files, fn($a, $b) => strcmp($a['relativePath'], $b['relativePath']));

        return [
            'siteName'       => $this->wire->config->httpHost ?: basename($this->wire->config->paths->root),
            'generatedAt'    => date('c'),
            'directories'    => $dirList,
            'extensions'     => $extList,
            'followSymlinks' => $followSymlinks,
            'fileCount'      => count($files),
            'files'          => $files,
        ];
    }

    /**
     * Check if a template name matches an exclusion list (supports wildcards).
     */
    private function matchesExcludeList(string $templateName, array $excludeList): bool {
        foreach ($excludeList as $pattern) {
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $templateName)) return true;
            } elseif ($templateName === $pattern) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a file path matches any exclusion glob pattern.
     */
    private function matchesExcludePatterns(string $path, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path)) return true;
        }
        return false;
    }

    // ========================================================================
    // v1.9.0 — READ-ONLY DIAGNOSTIC HANDLERS
    // ========================================================================

    /**
     * List ProcessWire modules with install state and file location.
     *
     * Default behaviour returns every currently-installed module. Pass an
     * explicit class list to inspect specific modules (installed or not) —
     * useful for "is FormBuilder present on prod?" checks before a deploy.
     *
     * Each entry intentionally exposes only what's needed for a deploy
     * decision: install state, whether the file is on disk, the file path
     * (relative to PW root for portability), and the module's reported
     * version. installError is set when the modules registry knows about a
     * module file that won't load (corrupt, missing dependency, etc.).
     *
     * @param string[] $classes Optional filter — module class names. Empty = all installed.
     * @return array Modules array under 'modules' key.
     */
    private function modulesList(array $classes = []): array {
        $modules = $this->wire->modules;
        $rootPath = $this->wire->config->paths->root;

        // Default = every installed module. ProcessWire's getInstalled()
        // returns [className => moduleObject] (or null for autoload-but-not-
        // loaded entries) — the *keys* are the class names, not the values.
        // v1.9.0 iterated the values and got module objects in $class, which
        // silently failed downstream with "Unable to locate module" because
        // $modules->isInstalled() needs a string. Fixed in v1.9.1.
        if (empty($classes)) {
            $classes = array_keys($modules->getInstalled());
            sort($classes);
        }

        $results = [];
        foreach ($classes as $class) {
            $isInstalled = $modules->isInstalled($class);
            $filePath    = $modules->getModuleFile($class);
            $fileExists  = $filePath && file_exists($filePath);

            // Best-effort version lookup. getModuleInfo() returns a packed int
            // (e.g. 184 for "1.8.4") which formatVersion() unpacks to a dotted
            // string. If the module is uninstallable or info is missing we
            // surface that as installError rather than failing the whole call.
            $version = null;
            $installError = null;
            try {
                $info = $modules->getModuleInfo($class);
                if (isset($info['version'])) {
                    $version = is_numeric($info['version'])
                        ? $modules->formatVersion((int) $info['version'])
                        : (string) $info['version'];
                }
                if (!empty($info['error'])) {
                    $installError = $info['error'];
                }
            } catch (\Throwable $e) {
                $installError = $e->getMessage();
            }

            $entry = [
                'class'       => $class,
                'isInstalled' => $isInstalled,
                'fileExists'  => (bool) $fileExists,
                'filePath'    => $filePath
                    ? ltrim(str_replace($rootPath, '', $filePath), '/')
                    : null,
                'version'     => $version,
            ];
            if ($installError !== null) {
                $entry['installError'] = $installError;
            }

            $results[] = $entry;
        }

        return ['modules' => $results, 'count' => count($results)];
    }

    /**
     * List ProcessWire users with roles and (by default) member_* fields.
     *
     * The default field projection is deliberately narrow: id, name, email,
     * roles, plus any field starting with `member_`. That covers the
     * "additive user sync" use case planned for v1.12 without spilling
     * arbitrary profile data into MCP responses.
     *
     * Pass --include=all to widen to every non-system field on the user
     * template (still skips password / hash columns — those never leave
     * PW's Password fieldtype, which won't serialise to plain JSON anyway).
     *
     * @param bool $includeAll Include every non-system field, not just member_*.
     * @return array Users array under 'users' key.
     */
    private function usersList(bool $includeAll = false): array {
        $results = [];

        // Walk the user PageArray (include=all so unpublished/superuser-only
        // users are visible — same default as listTemplates etc.).
        foreach ($this->wire->users->find('include=all') as $user) {
            $roles = [];
            foreach ($user->roles as $role) {
                $roles[] = $role->name;
            }

            $entry = [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => (string) $user->email,
                'roles' => $roles,
            ];

            // Project user template fields into the entry. Password fields
            // never serialise cleanly so we skip them up front.
            foreach ($user->template->fields as $field) {
                $name = $field->name;
                if ($name === 'pass' || $name === 'roles' || $name === 'email') {
                    continue;
                }
                $isMember = strncmp($name, 'member_', 7) === 0;
                if (!$includeAll && !$isMember) {
                    continue;
                }
                $entry[$name] = $this->formatFieldValue($field, $user->get($name));
            }

            $results[] = $entry;
        }

        return ['users' => $results, 'count' => count($results)];
    }

    /**
     * Bulk-resolve names to ids for a single PW object type.
     *
     * Returns a name → id mapping (or null if the name doesn't exist on this
     * site). The mapping is keyed by the input name verbatim so callers can
     * round-trip easily — even when ProcessWire would normalise the name
     * differently internally.
     *
     * Used by v1.10+ fieldgroup pushes to translate a local field/template
     * name list into the equivalent ids on the remote, without making one
     * HTTP round-trip per name.
     *
     * @param string   $type  One of: field|template|page|role|permission|user|module
     * @param string[] $names Names to resolve.
     * @return array { type, mapping: { name: id|null }, count, missing[] }
     */
    private function resolve(string $type, array $names): array {
        $mapping = [];
        $missing = [];

        foreach ($names as $name) {
            $id = null;

            switch ($type) {
                case 'field':
                    $obj = $this->wire->fields->get($name);
                    $id = $obj ? (int) $obj->id : null;
                    break;

                case 'template':
                    $obj = $this->wire->templates->get($name);
                    $id = $obj ? (int) $obj->id : null;
                    break;

                case 'page':
                    // Names for pages are paths (or numeric ids round-tripping
                    // back). Use ->get() which accepts either.
                    $obj = $this->wire->pages->get($name);
                    $id = ($obj && $obj->id) ? (int) $obj->id : null;
                    break;

                case 'role':
                    $obj = $this->wire->roles->get($name);
                    $id = ($obj && $obj->id) ? (int) $obj->id : null;
                    break;

                case 'permission':
                    $obj = $this->wire->permissions->get($name);
                    $id = ($obj && $obj->id) ? (int) $obj->id : null;
                    break;

                case 'user':
                    $obj = $this->wire->users->get($name);
                    $id = ($obj && $obj->id) ? (int) $obj->id : null;
                    break;

                case 'module':
                    // Modules don't really have ids the way pages do, but the
                    // modules table has an id column. Returning that id keeps
                    // the response shape identical across types.
                    $isInstalled = $this->wire->modules->isInstalled($name);
                    if ($isInstalled) {
                        $info = $this->wire->modules->getModuleInfo($name);
                        $id = isset($info['id']) ? (int) $info['id'] : 0;
                    }
                    break;

                default:
                    return ['error' => "resolve: unknown type '{$type}' (expected field|template|page|role|permission|user|module)"];
            }

            $mapping[$name] = $id;
            if ($id === null) {
                $missing[] = $name;
            }
        }

        return [
            'type'    => $type,
            'mapping' => $mapping,
            'count'   => count($mapping),
            'missing' => $missing,
        ];
    }

    /**
     * Inspect a single template with rich field info.
     *
     * Companion to get-template that returns each field as
     * {name, type, label} instead of just a name string. v1.10's
     * pw_template_fields_push will diff two of these to compute the additive
     * fieldgroup edits required to bring a remote template in line with
     * local.
     *
     * @param string $name Template name.
     * @return array Template details or error.
     */
    private function templateInspect(string $name): array {
        $template = $this->wire->templates->get($name);

        if (!$template) {
            return ['error' => "Template not found: $name"];
        }

        $fields = [];
        foreach ($template->fields as $field) {
            $fields[] = [
                'name'  => $field->name,
                'type'  => $field->type->className(),
                'label' => $field->label ?: $field->name,
            ];
        }

        return [
            'name'   => $template->name,
            'label'  => $template->label ?: $template->name,
            'fields' => $fields,
            'family' => [
                'allowPageNum'    => (bool) $template->allowPageNum,
                'allowChildren'   => $template->noChildren ? false : true,
                'childTemplates'  => $template->childTemplates ?: [],
                'parentTemplates' => $template->parentTemplates ?: [],
            ],
            'access' => [
                'useRoles' => (bool) $template->useRoles,
                'roles'    => $template->useRoles ? $this->getTemplateRoles($template) : [],
            ],
        ];
    }

    /**
     * Per-fieldgroup context settings that PW supports as overrides for a
     * field within a specific template's fieldgroup. Anything NOT in this
     * allow-list surfaces as a warning ("will be stored but may be ignored")
     * rather than a hard failure, so AI callers that pass slightly wrong
     * keys fail gracefully.
     *
     * `template_id`/`parent_id`/`findPagesSelector`/`inputfield` only make
     * sense on FieldtypePage fields; passing them on any other type is a
     * danger-class conflict.
     */
    private const TEMPLATE_FIELDS_PUSH_CONTEXT_KEYS = [
        'label', 'description', 'notes',
        'required',
        'columnWidth',
        'showIf', 'requiredIf',
        'collapsed',
        // FieldtypePage-only context overrides
        'template_id', 'parent_id', 'findPagesSelector', 'inputfield',
    ];

    /**
     * v1.11.0 — fieldgroup-only template edits.
     *
     * Plan/read phase: validates the requested add/remove/reorder operations
     * against the live fieldgroup, computes the projected post-push fieldgroup,
     * and returns a structured plan. Fieldtype changes and field-definition
     * pushes are explicitly out of scope — those belong to `pw_field_push`
     * (v1.12+) and this handler rejects them with a clear pointer.
     *
     * Accepts two input shapes for `$add`:
     *   - strings                         (simple — no context overrides)
     *   - {name, context: {k: v, ...}}    (rich — per-fieldgroup overrides)
     * Mixed in the same array is fine.
     *
     * `$remove` and `$reorder` are always string arrays; per-fieldgroup context
     * is irrelevant to a remove/reorder.
     *
     * Write path is stubbed until the conflict classifier (mcp-server/src/
     * conflicts/) lands its full rule set; until then `--dry-run=0` still
     * only reports what would happen, and the `applied` flag in the result
     * stays `false`. This keeps the scaffolding landable without any risk
     * to production fieldgroups.
     */
    private function templateFieldsPush(
        string $templateName,
        array  $add,
        array  $remove,
        array  $reorder,
        bool   $dryRun,
        bool   $force
    ): array {
        $templates = $this->wire->templates;
        $fields    = $this->wire->fields;

        $template = $templates->get($templateName);
        if (!$template || !$template->id) {
            return ['error' => "Template not found: {$templateName}"];
        }

        $conflicts = [
            'safe'    => [],
            'warning' => [],
            'danger'  => [],
        ];

        // Normalise $add into [{name, context}]. Bare strings get a null
        // context; object entries must have a `name`; anything else is a
        // structural error that lands in the danger bucket without aborting.
        $addNormalized = [];
        foreach ($add as $entry) {
            if (is_string($entry) && $entry !== '') {
                $addNormalized[] = ['name' => $entry, 'context' => null];
                continue;
            }
            if (is_array($entry) && isset($entry['name']) && is_string($entry['name']) && $entry['name'] !== '') {
                $addNormalized[] = [
                    'name'    => $entry['name'],
                    'context' => (isset($entry['context']) && is_array($entry['context'])) ? $entry['context'] : null,
                ];
                continue;
            }
            $conflicts['danger'][] = [
                'op'     => 'add',
                'field'  => '(invalid entry)',
                'why'    => 'Add entry must be either a string (field name) or {name, context?} object.',
                'detail' => ['entry' => $entry],
            ];
        }

        // Snapshot the current fieldgroup. Using `fieldgroup` (not `fields`)
        // so the returned order is the raw fieldgroup order rather than the
        // admin-side sort, which matters for reorder operations.
        //
        // `required` in PW is a field/context property, not a Field::flag*
        // constant. Checking it context-aware (per fieldgroup) because the
        // same field can be required on one template and optional on another.
        $currentFields = [];
        $fieldgroup = $template->fieldgroup;
        foreach ($fieldgroup as $field) {
            $ctx = $fieldgroup->getFieldContextArray($field->id);
            $required = isset($ctx['required'])
                ? (bool) $ctx['required']
                : (bool) $field->required;
            $currentFields[] = [
                'name'     => $field->name,
                'type'     => $field->type ? $field->type->className() : null,
                'label'    => $field->label ?: $field->name,
                'flags'    => (int) $field->flags,
                'required' => $required,
            ];
        }
        $currentNames = array_column($currentFields, 'name');

        // Catalog of fields on this site — needed to validate adds against
        // "does this field definition exist at all?" and to drive the
        // per-field completeness pass (below) that surfaces "this field's
        // definition is incomplete, editors will hit UX issues" warnings.
        $targetCatalog = [];
        foreach ($fields as $f) {
            $targetCatalog[$f->name] = [
                'type'  => $f->type ? $f->type->className() : null,
                'label' => $f->label ?: $f->name,
            ];
        }

        // ================================================================
        // TEMPLATE-LEVEL MODULE OWNERSHIP
        //
        // Fires ONCE per call when the target template is owned by a
        // known module (FormBuilder `form-*`, PW core `admin`/`user`/
        // `role`/`permission`, Repeater storage templates, MediaHub, etc.).
        // Escalates every write op (add/remove/reorder) to danger because
        // the owning module expects specific schema and will silently
        // misbehave if the fieldgroup drifts out-of-band:
        //   - FormBuilder: submissions may drop fields or fail validation
        //   - Repeater: storage pages lose their value scaffolding
        //   - PW core: admin UI breaks, auth breaks, etc.
        // The operator's escape hatches are (a) change the module's
        // settings instead of editing the template directly, or (b)
        // pass --force=1 after confirming the change is safe.
        // ================================================================
        $templateOwner = $this->templateFieldsPushInferTemplateModule($templateName);
        $hasWrites = !empty($addNormalized) || !empty($remove) || !empty($reorder);
        if ($templateOwner !== null && $hasWrites) {
            $conflicts['danger'][] = [
                'op'     => 'template-ownership',
                'field'  => $templateName,
                'why'    => "Template '{$templateName}' is managed by the {$templateOwner} module. Editing its fieldgroup directly may silently break {$templateOwner} — form submissions, repeater storage, admin UI, or module state can all fail without obvious error. Change the module's settings instead, or pass --force=1 after confirming the change is safe.",
                'scope'  => 'module-ownership',
                'module' => $templateOwner,
            ];
        }

        // ================================================================
        // ADD classification (per-field: exists? catalogued? complete?
        // context valid? context keys in allow-list?)
        // ================================================================
        foreach ($addNormalized as $entry) {
            $name    = $entry['name'];
            $context = $entry['context'];

            if (in_array($name, $currentNames, true)) {
                $conflicts['danger'][] = [
                    'op'    => 'add',
                    'field' => $name,
                    'why'   => "Field '{$name}' is already on template '{$templateName}' — add is a no-op or you meant reorder/context-only update.",
                ];
                continue;
            }
            if (!isset($targetCatalog[$name])) {
                $conflicts['danger'][] = [
                    'op'    => 'add',
                    'field' => $name,
                    'why'   => "Field '{$name}' does not exist on this site. Run pw_field_push first (v1.12+) or create the field manually before re-running.",
                ];
                continue;
            }

            $field     = $fields->get($name);
            $fieldType = $field && $field->type ? $field->type->className() : '';

            // Definition-level completeness warnings. We can't FIX these
            // from this tool (field-def editing is v1.12 territory), but
            // we can surface them so the caller knows what editors will
            // actually see once the field is in the fieldgroup.
            foreach ($this->templateFieldsPushCompletenessWarnings($field) as $w) {
                $conflicts['warning'][] = [
                    'op'    => 'add',
                    'field' => $name,
                    'why'   => $w,
                    'scope' => 'field-definition',
                ];
            }

            // Per-fieldgroup context validation. Unknown keys → warning;
            // FieldtypePage-only keys on non-Page fields → danger.
            if (is_array($context)) {
                foreach ($context as $k => $v) {
                    if (!in_array($k, self::TEMPLATE_FIELDS_PUSH_CONTEXT_KEYS, true)) {
                        $conflicts['warning'][] = [
                            'op'    => 'add',
                            'field' => $name,
                            'why'   => "Unknown context key '{$k}' for field '{$name}' — PW will store it but it may not be honoured by any Inputfield.",
                            'scope' => 'context',
                        ];
                        continue;
                    }
                    if (in_array($k, ['template_id', 'parent_id', 'findPagesSelector', 'inputfield'], true)
                        && $fieldType !== 'FieldtypePage'
                    ) {
                        $conflicts['danger'][] = [
                            'op'    => 'add',
                            'field' => $name,
                            'why'   => "Context key '{$k}' only applies to FieldtypePage fields; '{$name}' is {$fieldType}.",
                            'scope' => 'context',
                        ];
                    }
                }
            }

            $conflicts['safe'][] = [
                'op'      => 'add',
                'field'   => $name,
                'type'    => $fieldType,
                'context' => $context,
            ];
        }

        // ================================================================
        // REMOVE classification
        //
        // Severity ladder (first match wins for the danger cases):
        //   1. Not on template                              → danger
        //   2. flagSystem / flagPermanent (PW core field)   → danger
        //   3. Template itself is module-owned (form-*,
        //      repeater_*, etc.)                            → danger
        //   4. Field is flagged required on this template   → danger
        //   5. Field name prefix matches a known module     → warning
        //      (e.g. seoneo_*, form_*) — operator may be
        //      intentionally uninstalling SEOneo or similar,
        //      so not a blocker, but they get told
        //   6. Default                                       → warning
        //      ("will orphan any values stored on existing pages")
        //
        // Module-ownership and core-flag checks exist specifically to
        // protect against silent breakage: FormBuilder form submission,
        // core PW auth, repeater storage, etc. can all fail silently if
        // their backing fields disappear. We escalate to danger so the
        // --force=1 pathway is the only way through.
        // ================================================================
        foreach ($remove as $name) {
            if (!is_string($name) || $name === '') continue;
            if (!in_array($name, $currentNames, true)) {
                $conflicts['danger'][] = [
                    'op'    => 'remove',
                    'field' => $name,
                    'why'   => "Field '{$name}' is not on template '{$templateName}' — nothing to remove.",
                ];
                continue;
            }

            $field = $fields->get($name);
            $entry = null;
            foreach ($currentFields as $f) {
                if ($f['name'] === $name) { $entry = $f; break; }
            }

            if ($field && $entry) {
                $flagDanger = $this->templateFieldsPushCoreFlagDanger($field, $entry);
                if ($flagDanger !== null) {
                    $conflicts['danger'][] = array_merge(
                        ['op' => 'remove', 'field' => $name],
                        $flagDanger
                    );
                    continue;
                }
            }

            if ($entry && $entry['required']) {
                $conflicts['danger'][] = [
                    'op'    => 'remove',
                    'field' => $name,
                    'why'   => "Field '{$name}' is flagged required on template '{$templateName}'. Remove the required flag in the admin first, or pass --force=1.",
                ];
                continue;
            }

            $conflicts['warning'][] = [
                'op'    => 'remove',
                'field' => $name,
                'why'   => "Removing '{$name}' will orphan any values stored on existing pages of template '{$templateName}'. The page-value data remains in the database but becomes unreachable via PW APIs.",
            ];

            if ($field) {
                $fieldOwner = $this->templateFieldsPushInferFieldModule($name);
                if ($fieldOwner !== null) {
                    $conflicts['warning'][] = [
                        'op'     => 'remove',
                        'field'  => $name,
                        'why'    => "Field name '{$name}' matches the {$fieldOwner} module's convention. If {$fieldOwner} is installed and relies on this field being on '{$templateName}', removing it may silently break that module. Use pw_modules_list to confirm whether {$fieldOwner} is active on the target site before proceeding.",
                        'scope'  => 'module-ownership',
                        'module' => $fieldOwner,
                    ];
                }
            }

            $frontendUsage = $this->templateFieldsPushFrontendUsageWarnings($name);
            if ($frontendUsage !== null) {
                $conflicts['warning'][] = array_merge(
                    ['op' => 'remove', 'field' => $name],
                    $frontendUsage
                );
            }
        }

        // ================================================================
        // REORDER classification
        // ================================================================
        if (!empty($reorder)) {
            $addNames = array_map(fn($e) => $e['name'], $addNormalized);
            $currentAfterAddRemove = array_merge(
                array_values(array_filter($currentNames, fn($n) => !in_array($n, $remove, true))),
                array_values(array_filter($addNames, fn($n) => isset($targetCatalog[$n])))
            );
            foreach ($reorder as $name) {
                if (!is_string($name) || $name === '') continue;
                if (!in_array($name, $currentAfterAddRemove, true)) {
                    $conflicts['danger'][] = [
                        'op'    => 'reorder',
                        'field' => $name,
                        'why'   => "Field '{$name}' is not on template '{$templateName}' (even after the requested add/remove). Drop it from reorder or add it via add.",
                    ];
                }
            }
        }

        // ================================================================
        // PROJECT post-push fieldgroup
        // ================================================================
        $plannedNames = $currentNames;
        foreach ($remove as $name) {
            if (!is_string($name) || $name === '') continue;
            $plannedNames = array_values(array_filter($plannedNames, fn($n) => $n !== $name));
        }
        foreach ($addNormalized as $entry) {
            $name = $entry['name'];
            if (!in_array($name, $plannedNames, true) && isset($targetCatalog[$name])) {
                $plannedNames[] = $name;
            }
        }
        if (!empty($reorder)) {
            $reordered = [];
            foreach ($reorder as $name) {
                if (!is_string($name) || $name === '') continue;
                if (in_array($name, $plannedNames, true) && !in_array($name, $reordered, true)) {
                    $reordered[] = $name;
                }
            }
            foreach ($plannedNames as $name) {
                if (!in_array($name, $reordered, true)) $reordered[] = $name;
            }
            $plannedNames = $reordered;
        }

        // Index the normalised adds by name so plannedFields can surface
        // context in-line with the projected field entries.
        $addByName = [];
        foreach ($addNormalized as $entry) {
            $addByName[$entry['name']] = $entry;
        }

        $plannedFields = [];
        foreach ($plannedNames as $name) {
            $src = null;
            foreach ($currentFields as $f) { if ($f['name'] === $name) { $src = $f; break; } }
            if ($src) {
                $plannedFields[] = [
                    'name'  => $src['name'],
                    'type'  => $src['type'],
                    'label' => $src['label'],
                ];
                continue;
            }
            if (isset($targetCatalog[$name])) {
                $plannedFields[] = [
                    'name'    => $name,
                    'type'    => $targetCatalog[$name]['type'],
                    'label'   => $targetCatalog[$name]['label'],
                    'context' => $addByName[$name]['context'] ?? null,
                ];
            }
        }

        // ================================================================
        // FIELDSET pair integrity — run AFTER projection so the check sees
        // the final planned ordering. A FieldsetTabOpen/FieldsetOpen/
        // FieldsetGroup without its matching `{name}_END` close field (or
        // vice versa) is a danger-class conflict because PW will render
        // the admin UI broken until the pair is restored.
        // ================================================================
        foreach ($this->templateFieldsPushFieldsetPairIssues($plannedFields) as $issue) {
            $conflicts['danger'][] = $issue;
        }

        $hasDanger = !empty($conflicts['danger']);
        $applied   = false;

        // `operations` echoes back what the caller asked for in the
        // normalised rich form so the MCP tool can display the plan
        // without re-normalising.
        $operationsEcho = [
            'add'     => array_values($addNormalized),
            'remove'  => array_values(array_filter($remove, fn($n) => is_string($n) && $n !== '')),
            'reorder' => array_values(array_filter($reorder, fn($n) => is_string($n) && $n !== '')),
        ];

        if ($dryRun) {
            return [
                'template'            => $templateName,
                'operations'          => $operationsEcho,
                'currentFieldgroup'   => $currentFields,
                'plannedFieldgroup'   => $plannedFields,
                'conflicts'           => $conflicts,
                'conflictsSummary'    => [
                    'safe'    => count($conflicts['safe']),
                    'warning' => count($conflicts['warning']),
                    'danger'  => count($conflicts['danger']),
                ],
                'dryRun'              => true,
                'applied'             => $applied,
            ];
        }

        // ================================================================
        // WRITE PATH (Phase 3)
        // ================================================================
        //
        // Danger conflicts block writes unless --force=1 is passed. This
        // is the last line of defense before we mutate schema: core-flag
        // dangers, template-ownership dangers, required-field-removal
        // dangers, fieldset-pair dangers, and add-with-bad-context
        // dangers all flow through the same gate.
        if ($hasDanger && !$force) {
            return [
                'error'            => 'template:fields-push has danger-class conflicts; pass --force=1 to override after reviewing.',
                'conflicts'        => $conflicts,
                'conflictsSummary' => [
                    'safe'    => count($conflicts['safe']),
                    'warning' => count($conflicts['warning']),
                    'danger'  => count($conflicts['danger']),
                ],
                'applied'          => false,
            ];
        }

        // Execute in this order (matters for context-setter success and
        // reorder correctness):
        //   1. remove fields (frees up slots)
        //   2. add fields   (uses the resolved Field objects)
        //   3. save         (context-setter needs the field on the
        //                   fieldgroup first, so save before step 4)
        //   4. apply context overrides
        //   5. save
        //   6. reorder      (rebuilds the ordered list)
        //   7. save
        //
        // Each step tracks what was actually applied so that if a later
        // step throws, the response still shows the audit trail of
        // completed writes. That makes recovery straightforward — the
        // operator can see exactly where the failure occurred.

        $audit = [
            'removed'   => [],
            'added'     => [],
            'contextSet' => [],
            'reordered' => false,
            'saves'     => 0,
        ];

        try {
            // 1. Remove
            foreach ($remove as $name) {
                if (!is_string($name) || $name === '') continue;
                $f = $fieldgroup->getField($name);
                if ($f) {
                    $fieldgroup->remove($f);
                    $audit['removed'][] = $name;
                }
            }

            // 2. Add
            $resolvedAdds = [];
            foreach ($addNormalized as $entry) {
                $name = $entry['name'];
                $f = $fields->get($name);
                if (!$f || !$f->id) continue;
                if (!$fieldgroup->has($f)) {
                    $fieldgroup->add($f);
                    $audit['added'][] = $name;
                }
                $resolvedAdds[] = ['field' => $f, 'context' => $entry['context']];
            }

            // 3. First save — locks in add/remove before context setters
            $fieldgroup->save();
            $audit['saves']++;

            // 4. Context overrides (only for new adds; existing-field
            // context updates are out of scope for v1.11).
            $contextDirty = false;
            foreach ($resolvedAdds as $added) {
                if (!is_array($added['context'])) continue;
                $fieldgroup->setFieldContextArray($added['field']->id, $added['context']);
                $audit['contextSet'][] = $added['field']->name;
                $contextDirty = true;
            }
            if ($contextDirty) {
                $fieldgroup->save();
                $audit['saves']++;
            }

            // 5. Reorder — rearrange in place.
            //
            // The naive approach (remove every field, re-add in desired
            // order) fails as soon as the fieldgroup contains any field
            // flagged `flagGlobal` (4) — PW refuses to remove global
            // fields from ANY fieldgroup, treating the removal as an
            // attempt to detach a site-wide required field. `title` on
            // blog_post is the canonical example (flags=13 = autojoin +
            // global + system).
            //
            // Use WireArray::insertBefore instead. It repositions a
            // member in place without flagging it for removal, so global
            // fields pass through unchallenged. The trick is to iterate
            // the reorder list in REVERSE and for each entry, move it
            // to be before the current first member — after processing
            // reorder[-1] → reorder[0] in order, the desired front-of-
            // list matches the caller's spec. Unlisted fields stay in
            // their current relative order, pushed to the back by the
            // repeated "move to front" operations.
            if (!empty($reorder)) {
                $reverseOrder = array_reverse(array_values(array_filter(
                    $reorder,
                    fn($n) => is_string($n) && $n !== ''
                )));
                foreach ($reverseOrder as $name) {
                    $f = $fieldgroup->getField($name);
                    if (!$f || !$fieldgroup->has($f)) continue;

                    $currentFirst = null;
                    foreach ($fieldgroup as $x) { $currentFirst = $x; break; }
                    if ($currentFirst && $currentFirst !== $f) {
                        $fieldgroup->insertBefore($f, $currentFirst);
                    }
                }
                $fieldgroup->save();
                $audit['saves']++;
                $audit['reordered'] = true;
            }

            $applied = true;
        } catch (\Exception $e) {
            return [
                'error'   => 'template:fields-push write path failed mid-operation: ' . $e->getMessage(),
                'audit'   => $audit,
                'hint'    => 'Partial writes may have landed. Run template:fields-push --dry-run=1 against the target to inspect the current state before retrying.',
                'applied' => false,
            ];
        }

        // Re-snapshot the fieldgroup so the response shows the actual
        // post-save state rather than trusting the projection.
        $postFields = [];
        $freshTemplate = $this->wire->templates->get($templateName);
        $freshFg = $freshTemplate ? $freshTemplate->fieldgroup : $fieldgroup;
        foreach ($freshFg as $f) {
            $ctx = $freshFg->getFieldContextArray($f->id);
            $postFields[] = [
                'name'     => $f->name,
                'type'     => $f->type ? $f->type->className() : null,
                'label'    => $f->label ?: $f->name,
                'flags'    => (int) $f->flags,
                'required' => isset($ctx['required']) ? (bool) $ctx['required'] : (bool) $f->required,
                'context'  => $ctx ?: null,
            ];
        }

        return [
            'template'            => $templateName,
            'operations'          => $operationsEcho,
            'beforeFieldgroup'    => $currentFields,
            'afterFieldgroup'     => $postFields,
            'plannedFieldgroup'   => $plannedFields,
            'conflicts'           => $conflicts,
            'conflictsSummary'    => [
                'safe'    => count($conflicts['safe']),
                'warning' => count($conflicts['warning']),
                'danger'  => count($conflicts['danger']),
            ],
            'audit'               => $audit,
            'dryRun'              => false,
            'applied'             => $applied,
        ];
    }

    /**
     * Definition-level completeness heuristics. These are warnings, not
     * blockers — the field is already on the site (we confirmed it's in
     * the catalog) so the add operation CAN proceed. The warnings exist
     * so AI callers that create fields with missing dependencies (the
     * classic "Page reference without a parent picker" failure) get told
     * about the gap at plan time rather than discovering it in the admin
     * UI after the push.
     *
     * Returns an array of human-readable warning strings. Empty array
     * means the field looks complete for its fieldtype.
     *
     * This matrix is the v1.11.0 subset; v1.12+ `pw_field_push` will
     * reuse the same rules at field-definition push time.
     */
    private function templateFieldsPushCompletenessWarnings(\ProcessWire\Field $field): array {
        $warnings = [];
        $type = $field->type ? $field->type->className() : '';

        if ($type === 'FieldtypePage') {
            if (empty($field->template_id) && empty($field->parent_id) && empty($field->findPagesSelector)) {
                $warnings[] = "Page reference has no selectable-pages constraint (template_id, parent_id, and findPagesSelector all empty) — editors will see every page in the tree when picking.";
            }
            if (empty($field->inputfield)) {
                $warnings[] = "Page reference has no Inputfield selected — PW will fall back to its default; confirm the picker type (PageListSelect, AsmSelect, etc.) matches your UX intent.";
            }
        }

        if ($type === 'FieldtypeTextarea') {
            if (empty($field->inputfieldClass)) {
                $warnings[] = "Textarea has no Inputfield class set — will render as a plain <textarea>, not CKEditor / TinyMCE / etc.";
            }
        }

        if ($type === 'FieldtypeImage' || $type === 'FieldtypeCroppableImage3' || $type === 'FieldtypeFile') {
            if (empty($field->extensions)) {
                $warnings[] = "File/Image field has no allowed extensions set — editors may be unable to upload anything, or the field may accept unintended file types.";
            }
        }

        if ($type === 'FieldtypeRepeater' || $type === 'FieldtypeRepeaterMatrix') {
            if (empty($field->template_id)) {
                $warnings[] = "Repeater has no template_id set for its repeater pages — field will be non-functional until configured.";
            }
            if (empty($field->parent_id)) {
                $warnings[] = "Repeater has no parent_id set for its repeater pages — field will be non-functional until configured.";
            }
        }

        return $warnings;
    }

    /**
     * Fieldset pair integrity check for the projected post-push fieldgroup.
     *
     * PW fieldsets follow a name convention: a `FieldsetTabOpen` /
     * `FieldsetOpen` / `FieldsetGroup` field named `X` is closed by a
     * `FieldsetClose` named `X_END`. Breaking the pair (either member
     * present without the other, or close BEFORE open) leaves the admin
     * UI rendering in an inconsistent state.
     *
     * Returns an array of issue objects (already in conflicts['danger']
     * shape) — caller just appends.
     */
    private function templateFieldsPushFieldsetPairIssues(array $plannedFields): array {
        $issues = [];
        $names  = array_column($plannedFields, 'name');
        $nameSet = array_flip($names);
        $openerTypes = ['FieldtypeFieldsetOpen', 'FieldtypeFieldsetTabOpen', 'FieldtypeFieldsetGroup'];

        foreach ($plannedFields as $idx => $f) {
            $name = $f['name'] ?? '';
            $type = $f['type'] ?? '';
            if ($name === '' || $type === '') continue;

            if (in_array($type, $openerTypes, true)) {
                $expectedClose = $name . '_END';
                if (!isset($nameSet[$expectedClose])) {
                    $issues[] = [
                        'op'    => 'fieldset-pair',
                        'field' => $name,
                        'why'   => "Fieldset opener '{$name}' ({$type}) has no matching '{$expectedClose}' in the planned fieldgroup. Template admin UI will be broken until the close is added.",
                    ];
                    continue;
                }
                $closeIdx = array_search($expectedClose, $names, true);
                if ($closeIdx !== false && $closeIdx < $idx) {
                    $issues[] = [
                        'op'    => 'fieldset-pair',
                        'field' => $name,
                        'why'   => "Fieldset close '{$expectedClose}' appears BEFORE its opener '{$name}' in the planned fieldgroup. Reorder is broken — the close must come after the open.",
                    ];
                }
            }

            if ($type === 'FieldtypeFieldsetClose') {
                if (substr($name, -4) !== '_END') continue;
                $expectedOpen = substr($name, 0, -4);
                if (!isset($nameSet[$expectedOpen])) {
                    $issues[] = [
                        'op'    => 'fieldset-pair',
                        'field' => $name,
                        'why'   => "Fieldset close '{$name}' has no matching opener '{$expectedOpen}' in the planned fieldgroup. Either also add the opener or remove the close.",
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Check the PW core flags that forbid deletion. `flagSystem` (8) marks
     * fields core to PW's operation; `flagPermanent` (512) marks fields
     * that PW itself refuses to delete. Either case is a hard danger for
     * a `remove` operation, and no amount of `--force=1` should coerce
     * the handler into applying the change without the operator also
     * clearing the flag in the admin first.
     *
     * Returns the danger payload (why + scope + detail) or null when the
     * field has neither flag set.
     */
    private function templateFieldsPushCoreFlagDanger(\ProcessWire\Field $field, array $currentFieldEntry): ?array {
        $flags = (int) $field->flags;
        if ($flags & \ProcessWire\Field::flagSystem) {
            return [
                'why'    => "Field '{$field->name}' is flagged system (flagSystem=8) — it is core to ProcessWire. Removing it will break PW itself. Remove the system flag in the admin first if you really need to delete this field.",
                'scope'  => 'core-flag',
                'detail' => ['flags' => $flags, 'flagBit' => 'flagSystem'],
            ];
        }
        if ($flags & \ProcessWire\Field::flagPermanent) {
            return [
                'why'    => "Field '{$field->name}' is flagged permanent (flagPermanent=512) — PW refuses to delete it. Remove the permanent flag in the admin first if you really need to delete this field.",
                'scope'  => 'core-flag',
                'detail' => ['flags' => $flags, 'flagBit' => 'flagPermanent'],
            ];
        }
        return null;
    }

    /**
     * Infer whether a TEMPLATE name belongs to a known PW module.
     *
     * Module-owned templates (FormBuilder's `form-<name>`, PW's repeater
     * storage templates, MediaHub's own templates, etc.) should never be
     * edited via this tool — the module expects specific schema and will
     * silently misbehave if the fieldgroup is altered out-of-band. Returns
     * the module name when matched, null otherwise.
     *
     * Ordering matters: more specific patterns first (`form-*` before the
     * broader `form_*`, etc.) so matches are unambiguous.
     */
    private function templateFieldsPushInferTemplateModule(string $templateName): ?string {
        $patterns = [
            // FormBuilder uses `form-<slug>` (hyphen form) for forms it
            // creates; some installs also have `form_<slug>` (underscore)
            // in legacy or ported configs.
            'FormBuilder'      => '/^form[-_][a-z0-9_-]+$/i',
            // Repeater and RepeaterMatrix internal storage templates.
            'Repeater'         => '/^repeater_/i',
            // ProcessWire core admin/auth pseudo-templates.
            'ProcessWire core' => '/^(admin|user|role|permission|language)$/i',
            // MediaHub attaches its own templates for media library pages
            // on some installs.
            'MediaHub'         => '/^(mediahub|media_hub)[-_]/i',
            // ProFields table storage templates.
            'ProFields'        => '/^(protable|profields)_/i',
        ];
        foreach ($patterns as $module => $rx) {
            if (preg_match($rx, $templateName)) {
                return $module;
            }
        }
        return null;
    }

    /**
     * Infer whether a FIELD name follows a known module's naming convention.
     *
     * Returns the module name when the name prefix matches, null otherwise.
     * This runs when the removed field is NOT flagged system/permanent and
     * the template is NOT a module-owned template — i.e. the "the field
     * looks module-ish but is sitting on a regular content template" case.
     * That's when we downgrade to warning: the operator may be cleaning up
     * after an uninstall, which is legitimate; we just make sure they're
     * told in case they didn't realize.
     */
    private function templateFieldsPushInferFieldModule(string $fieldName): ?string {
        $prefixes = [
            'form_'      => 'FormBuilder',
            'formrpro_'  => 'FormBuilder Pro',
            'repeater_'  => 'Repeater',
            'mediahub_'  => 'MediaHub',
            'media_hub_' => 'MediaHub',
            'seoneo_'    => 'SEOneo',
            'padloper_'  => 'Padloper',
            'login_'     => 'LoginRegisterPro',
            'register_'  => 'LoginRegisterPro',
            'comment'    => 'CommentsManager',
            'process_'   => 'ProcessWire Process modules',
        ];
        foreach ($prefixes as $prefix => $module) {
            if (str_starts_with($fieldName, $prefix)) {
                return $module;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------
    // v1.11.1 — frontend-usage scan on remove ops
    //
    // Module-ownership awareness (v1.11.0) catches fields that BELONG to a
    // known module (form_*, seoneo_*, mediahub_*, core flagged fields). It
    // cannot catch the broader class of break: a field with no special
    // naming convention (your_email, feedback, member_notes) that happens
    // to be hardcoded in a frontend template's PHP. Removing such a field
    // leaves ProcessWire perfectly healthy but silently breaks the form:
    // $page->your_email = $input->post->your_email becomes a no-op, the
    // assignment targets nothing, and the form "stops working" with no
    // admin-side error and no log entry.
    //
    // This helper scans site/templates/ for references to the field name
    // being removed and emits a warning (not danger) listing file paths,
    // line numbers, and snippets so the operator can decide whether the
    // removal is safe.
    //
    // Fail-open on every error path (missing dir, FS permission, iterator
    // exception). Capped at 200 files and 2 seconds; additional matches
    // beyond MAX_REFS are summarised in the count, not listed.
    // ------------------------------------------------------------------------

    private const TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_FILES = 200;
    private const TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_TIME  = 2.0;
    private const TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_REFS  = 10;

    private function templateFieldsPushFrontendUsageWarnings(string $fieldName): ?array {
        if ($fieldName === '') return null;
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName) !== 1) return null;

        $templatesDir = rtrim($this->wire->config->paths->site, '/') . '/templates';
        if (!is_dir($templatesDir)) return null;

        $start        = microtime(true);
        $filesScanned = 0;
        $totalHits    = 0;
        $references   = [];
        $timedOut     = false;
        $hitFileCap   = false;
        $pattern      = '/\b' . preg_quote($fieldName, '/') . '\b/';
        $allowedExt   = ['php' => true, 'module' => true, 'inc' => true, 'html' => true];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($templatesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (!isset($allowedExt[$ext])) continue;

                if ($filesScanned >= self::TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_FILES) {
                    $hitFileCap = true;
                    break;
                }
                if ((microtime(true) - $start) > self::TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_TIME) {
                    $timedOut = true;
                    break;
                }

                $filesScanned++;

                $contents = @file_get_contents($file->getPathname());
                if ($contents === false) continue;

                if (!str_contains($contents, $fieldName)) continue;

                $lines = explode("\n", $contents);
                foreach ($lines as $i => $line) {
                    if (preg_match($pattern, $line)) {
                        $totalHits++;
                        if (count($references) < self::TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_REFS) {
                            $rel = ltrim(str_replace($templatesDir, 'site/templates', $file->getPathname()), '/');
                            $references[] = [
                                'file'    => $rel,
                                'line'    => $i + 1,
                                'snippet' => trim(substr($line, 0, 160)),
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($references)) return null;

        $more = $totalHits - count($references);
        $moreNote = $more > 0 ? " (+ {$more} more occurrence" . ($more === 1 ? '' : 's') . " not listed)" : '';
        $capNote  = ($hitFileCap || $timedOut)
            ? ' Scan was capped at ' . self::TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_FILES . ' files / '
              . self::TEMPLATE_FIELDS_PUSH_FRONTEND_SCAN_MAX_TIME . 's; more references may exist.'
            : '';

        return [
            'scope'         => 'frontend-usage',
            'why'           => "Field '{$fieldName}' is referenced in {$totalHits} location(s) across site/templates/{$moreNote}. "
                             . "Removing it can silently break forms or templates that read/write this field — no ProcessWire error, just lost data. "
                             . "Review the references before proceeding.{$capNote}",
            'references'    => $references,
            'totalHits'     => $totalHits,
            'filesScanned'  => $filesScanned,
            'scanTimedOut'  => $timedOut,
            'scanHitFileCap'=> $hitFileCap,
        ];
    }

    // ========================================================================
    // v1.10.0 — PAGE ASSETS HANDLERS
    // ========================================================================

    /**
     * PW image variation pattern: name.WIDTHxHEIGHT[-suffix].ext
     *
     * Variations are regenerated on demand from originals and listing them in
     * an inventory produces noisy diffs (different cache state on each
     * environment) for files that don't actually need to be synced. They are
     * filtered out by default; pass --include-variations to bypass.
     */
    private const PAGE_ASSET_VARIATION_PATTERN = '/\.\d+x\d+(-[a-z0-9-]+)?\.[a-z]+$/i';

    /**
     * Resolve a page id/path/numeric-id to a [pageId, pagePath] pair, or
     * return an error array. Used by every page-assets handler so the
     * caller can pass either form.
     *
     * Returns ['_error' => string] on failure (the underscore key keeps
     * the shape distinct from a real page-id payload).
     *
     * Cross-environment note: this function only ever runs against the
     * SITE IT IS ON. It does not — and cannot — translate ids between
     * local and remote. The MCP server side (pages/page-assets.ts) takes
     * care of that by always passing the canonical PW path to the remote
     * inventory/upload/delete calls, so each side resolves the page on
     * its own auto-increment sequence and walks its own
     * site/assets/files/{ownId}/ directory. Numeric ids passed to this
     * resolver are interpreted on THIS site only; if a caller hands a
     * remote id to the local CLI they will get the wrong page (or a
     * "Page not found" error if the id doesn't exist locally).
     *
     * @param string $pageRef Numeric id, "/path/", or bare path
     * @return array{pageId:int, pagePath:string}|array{_error:string}
     */
    private function resolvePageRef(string $pageRef): array {
        $page = ctype_digit($pageRef)
            ? $this->wire->pages->get((int) $pageRef)
            : $this->wire->pages->get($pageRef);

        if (!$page || !$page->id) {
            return ['_error' => "Page not found: {$pageRef}"];
        }

        return ['pageId' => (int) $page->id, 'pagePath' => (string) $page->path];
    }

    /**
     * Inventory the on-disk assets for a single page.
     *
     * Walks site/assets/files/{pageId}/ directly rather than going through
     * $page->template->fieldgroup so files placed there by modules outside
     * the normal field flow (MediaHub, custom uploaders, etc.) are still
     * picked up. Default mode skips PW image variations; subdirectories
     * are walked recursively because some modules nest assets one level
     * deep (e.g. MediaHub variants/) and the relative path is the stable
     * identity used for diffing across environments.
     *
     * @param string $pageRef           Numeric id or PW path.
     * @param bool   $includeVariations If true, image variations are listed too.
     * @return array Inventory payload.
     */
    private function pageAssetsInventory(string $pageRef, bool $includeVariations = false): array {
        $resolved = $this->resolvePageRef($pageRef);
        if (isset($resolved['_error'])) {
            return ['error' => $resolved['_error']];
        }

        return [
            'pageId'   => $resolved['pageId'],
            'pagePath' => $resolved['pagePath'],
            'assets'   => $this->buildPageAssetEntries($resolved['pageId'], $includeVariations),
        ];
    }

    /**
     * Inventory page assets for every page on the site that has a
     * site/assets/files/{pageId}/ directory.
     *
     * Used by pw_site_compare to diff the union of pages-with-assets across
     * two environments in one round-trip. The site is walked once (rather
     * than asking the caller to inventory each page individually) because
     * the typical comparison touches dozens to hundreds of pages and the
     * per-call HTTP overhead would dominate the actual file-hash work.
     *
     * Pages whose template matches the exclusion list are skipped — same
     * defaults as siteInventory so the page-assets diff is always a subset
     * of the page-content diff.
     *
     * @param bool   $includeVariations If true, image variations included.
     * @param string $excludeTemplates  Comma-separated template names/wildcards.
     * @return array Pages keyed by canonical PW path.
     */
    private function pageAssetsInventoryAll(bool $includeVariations = false, string $excludeTemplates = ''): array {
        $excludeList = array_filter(array_map('trim', explode(',', $excludeTemplates)));

        $rootPath  = $this->wire->config->paths->root;
        $assetsDir = $rootPath . 'site/assets/files/';
        if (!is_dir($assetsDir)) {
            return [
                'siteName'    => $this->wire->config->httpHost ?: basename($rootPath),
                'generatedAt' => gmdate('c'),
                'pageCount'   => 0,
                'pages'       => [],
            ];
        }

        // Map page-id directories → page paths (skip the directory if the
        // page no longer exists — orphaned asset directories are common
        // after page deletions and shouldn't crash the inventory).
        $pageDirs = [];
        $entries  = @scandir($assetsDir) ?: [];
        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) continue;
            if (!is_dir($assetsDir . $entry)) continue;
            $pageDirs[] = (int) $entry;
        }

        $pages = [];
        foreach ($pageDirs as $pageId) {
            $page = $this->wire->pages->get($pageId);
            if (!$page || !$page->id) continue;
            if ($this->matchesExcludeList($page->template->name, $excludeList)) continue;

            $assets = $this->buildPageAssetEntries($pageId, $includeVariations);
            if (empty($assets)) continue;

            $pages[$page->path] = [
                'pageId'   => $pageId,
                'pagePath' => (string) $page->path,
                'template' => $page->template->name,
                'assets'   => $assets,
            ];
        }

        ksort($pages);

        return [
            'siteName'         => $this->wire->config->httpHost ?: basename($rootPath),
            'generatedAt'      => gmdate('c'),
            'includeVariations'=> $includeVariations,
            'pageCount'        => count($pages),
            'pages'            => $pages,
        ];
    }

    /**
     * Walk site/assets/files/{pageId}/ recursively and return one entry per
     * file (relative path, size, md5, mtime).
     *
     * @param int  $pageId
     * @param bool $includeVariations
     * @return array<int,array{relativePath:string, size:int, md5:string, modified:string}>
     */
    private function buildPageAssetEntries(int $pageId, bool $includeVariations): array {
        $rootPath = $this->wire->config->paths->root;
        $pageDir  = $rootPath . 'site/assets/files/' . $pageId . '/';
        if (!is_dir($pageDir)) return [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pageDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $entries = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) continue;
            $name = $fileInfo->getFilename();
            if ($name === '' || $name[0] === '.') continue;
            if (!$includeVariations && preg_match(self::PAGE_ASSET_VARIATION_PATTERN, $name)) continue;

            $rel = ltrim(str_replace($pageDir, '', $fileInfo->getPathname()), '/');
            $entries[] = [
                'relativePath' => $rel,
                'size'         => (int) $fileInfo->getSize(),
                'md5'          => md5_file($fileInfo->getPathname()),
                'modified'     => gmdate('c', $fileInfo->getMTime()),
            ];
        }

        usort($entries, fn($a, $b) => strcmp($a['relativePath'], $b['relativePath']));
        return $entries;
    }

    /**
     * Return one page asset's contents as base64. Used by the MCP server's
     * remote→local pull path so a missing file can be fetched without
     * needing SFTP credentials separate from PW_REMOTE_KEY.
     *
     * Refuses paths that escape the page directory (defence in depth — the
     * caller already supplies a relative path, but realpath() is the
     * authoritative check).
     *
     * @param string $pageRef
     * @param string $filename Relative path under site/assets/files/{pageId}/
     * @return array { pageId, pagePath, filename, size, md5, data (base64) } or { error }
     */
    private function pageAssetsDownload(string $pageRef, string $filename): array {
        $resolved = $this->resolvePageRef($pageRef);
        if (isset($resolved['_error'])) {
            return ['error' => $resolved['_error']];
        }

        $rootPath = $this->wire->config->paths->root;
        $pageDir  = realpath($rootPath . 'site/assets/files/' . $resolved['pageId']);
        if ($pageDir === false) {
            return ['error' => "No assets directory exists for page {$resolved['pageId']}"];
        }

        $candidate = $pageDir . DIRECTORY_SEPARATOR . ltrim($filename, '/');
        $real      = realpath($candidate);
        if ($real === false || strpos($real, $pageDir . DIRECTORY_SEPARATOR) !== 0) {
            return ['error' => "Asset not found or outside page directory: {$filename}"];
        }
        if (!is_file($real)) {
            return ['error' => "Asset is not a regular file: {$filename}"];
        }

        $contents = file_get_contents($real);
        if ($contents === false) {
            return ['error' => "Failed to read asset: {$filename}"];
        }

        return [
            'pageId'   => $resolved['pageId'],
            'pagePath' => $resolved['pagePath'],
            'filename' => $filename,
            'size'     => strlen($contents),
            'md5'      => md5($contents),
            'modified' => gmdate('c', filemtime($real)),
            'data'     => base64_encode($contents),
        ];
    }

    /**
     * Show help information
     * 
     * Returns available commands and flags.
     * 
     * @return array Help information
     */
    private function help(): array {
        return [
            'name' => 'PromptWire CLI',
            'version' => '1.11.1',
            'description' => 'ProcessWire ↔ Cursor MCP Bridge CLI',
            'commands' => [
                'health' => 'Check connection and get site info',
                'list-templates' => 'List all templates',
                'get-template [name]' => 'Get template details',
                'list-fields' => 'List all fields (--include=usage for usage info)',
                'get-field [name]' => 'Get field details',
                'get-page [id|path]' => 'Get page by ID or path',
                'query-pages [selector]' => 'Query pages by selector',
                'search [query]' => 'Search page content across text fields',
                'search-files [query]' => 'Search files by name, extension, or description',
                'export-schema' => 'Export full schema (--format=yaml for YAML output)',
                'page:pull [id|path]' => 'Pull page into local sync directory',
                'page:export-yaml [id|path]' => 'Export page as inline YAML payload (no filesystem writes; used by pw_page_pull source=remote)',
                'page:push [path]' => 'Push local changes to ProcessWire (--dry-run=0 to apply)',
                'pages:pull [selector]' => 'Pull multiple pages by selector, parent, or template',
                'pages:push [directory]' => 'Push all local changes in directory (--dry-run=0 to apply)',
                'sync:status [directory]' => 'Check sync status of all pulled pages',
                'sync:reconcile [directory]' => 'Fix path drift and detect orphaned pages',
                'page:new [template] [parent] [name]' => 'Create new page scaffold locally',
                'page:publish [path]' => 'Publish new page to ProcessWire (--dry-run=0 to create)',
                'pages:publish [directory]' => 'Bulk publish new pages (--dry-run=0 to create)',
                'matrix:info [page] [field]' => 'Get matrix/repeater field structure and types',
                'matrix:add [page] [field] [type]' => 'Add a new matrix item (--dry-run=0 to create)',
                'schema:apply [file.json]' => 'Apply a schema JSON file to ProcessWire (--dry-run=0 to apply)',
                'db-schema [table?]' => 'Inspect database schema (all tables, or one table in detail)',
                'db-query [sql]' => 'Execute a read-only SELECT query (--limit=N)',
                'db-explain [sql]' => 'Run EXPLAIN on a SELECT query for performance analysis',
                'db-counts' => 'Get row counts for core tables and top field tables',
                'logs [name?]' => 'Read log entries (--level=error --text=pattern --limit=N)',
                'last-error' => 'Get the most recent error from log files',
                'clear-cache [target?]' => 'Clear caches (all, modules, templates, compiled, wire-cache)',
                'maintenance:on [message?]' => 'Enable maintenance mode (blocks front-end visitors)',
                'maintenance:off' => 'Disable maintenance mode (site goes live)',
                'maintenance:status' => 'Check if maintenance mode is active',
                'backup:create [description?]' => 'Create DB + file backup (--exclude-tables=sessions,caches --no-files)',
                'backup:list' => 'List available PromptWire backups',
                'backup:restore <filename|timestamp>' => 'Restore a database backup',
                'backup:delete <filename|timestamp>' => 'Delete a backup (DB + files)',
                'files:push' => 'Push files to site (--files=JSON --confirm for live)',
                'site:inventory' => 'Page inventory with content hashes (--exclude-templates=user,role --include-system)',
                'files:inventory' => 'File inventory with MD5 hashes (--directories=site/templates,site/modules --extensions=php,js,css,json,latte,twig,module --no-follow-symlinks)',
                'modules:list' => 'List installed modules with version + file path (--classes=Foo,Bar to inspect specific classes)',
                'users:list' => 'List users with roles and member_* fields (--include=all to widen to every non-system field)',
                'resolve' => 'Bulk-resolve names to ids (--type=field|template|page|role|permission|user|module --names=foo,bar OR --input=\'{"type":"field","names":["foo"]}\')',
                'template:inspect' => 'Inspect a template with rich field info ({name,type,label} per field) for fieldgroup-diff workflows',
                'page-assets:inventory [id|path]' => 'List on-disk assets in site/assets/files/{pageId}/ for a single page (--include-variations to keep PW image variations; --all-pages --exclude-templates=user,role for site-wide inventory)',
                'page-assets:download [id|path] --filename=NAME' => 'Return one page asset as base64. Used by remote→local sync so the MCP server can fetch missing files over the same authenticated channel as the rest of the API.',
                'help' => 'Show this help',
            ],
            'flags' => [
                '--format=json|yaml' => 'Output format (default: json)',
                '--pretty' => 'Pretty-print JSON',
                '--include=usage' => 'Include field usage info (list-fields)',
                '--include=files' => 'Include full file/image metadata (get-page)',
                '--include=labels' => 'Include field labels and descriptions (get-page)',
                '--truncate=N' => 'Truncate text fields to N characters (get-page)',
                '--summary' => 'Return field structure only, no content (get-page)',
                '--limit=N' => 'Limit search/pull results',
                '--dry-run=0' => 'Apply changes instead of preview (push/publish commands)',
                '--force' => 'Force push even if remote has changed',
                '--no-parent' => 'Exclude parent page when pulling by path (pages:pull)',
                '--content-format=yaml|json' => 'Sync file format (default: yaml)',
                '--title="Title"' => 'Page title (page:new)',
                '--published' => 'Create page as published instead of unpublished',
                '--content=\'{"field":"value"}\'' => 'JSON content for matrix item (matrix:add)',
                '--classes=Foo,Bar' => 'Comma-separated module class list (modules:list)',
                '--include=all' => 'Widen user projection to every non-system field (users:list)',
                '--type=field|template|page|role|permission|user|module' => 'Object type to resolve (resolve)',
                '--names=foo,bar' => 'Comma-separated names to resolve (resolve)',
                '--input=\'{"type":"...","names":[...]}\'' => 'JSON alternative to --type/--names for very long lists (resolve)',
            ],
        ];
    }
}
