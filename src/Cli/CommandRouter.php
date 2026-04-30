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

            // Build a content hash from all custom field values
            $fieldData = [];
            foreach ($page->template->fieldgroup as $field) {
                if ($field->name === 'title') {
                    $fieldData['title'] = (string) $page->title;
                    continue;
                }
                $value = $page->get($field->name);
                if ($value instanceof \ProcessWire\PageArray) {
                    $fieldData[$field->name] = $value->explode('path');
                } elseif ($value instanceof \ProcessWire\Pagefiles || $value instanceof \ProcessWire\Pageimages) {
                    $fileArr = [];
                    foreach ($value as $f) {
                        $fileArr[] = $f->basename . ':' . $f->filesize();
                    }
                    $fieldData[$field->name] = $fileArr;
                } elseif (is_object($value)) {
                    $fieldData[$field->name] = (string) $value;
                } else {
                    $fieldData[$field->name] = $value;
                }
            }

            $contentHash = md5(json_encode($fieldData, JSON_UNESCAPED_UNICODE));

            $pages[] = [
                'id'          => $page->id,
                'path'        => $page->path,
                'template'    => $templateName,
                'status'      => $page->status,
                'modified'    => date('c', $page->modified),
                'created'     => date('c', $page->created),
                'contentHash' => $contentHash,
            ];
        }

        return [
            'siteName'    => $this->wire->config->httpHost ?: basename($this->wire->config->paths->root),
            'generatedAt' => date('c'),
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

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absDir, $iteratorFlags),
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
            'version' => '1.6.0',
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
            ],
        ];
    }
}
