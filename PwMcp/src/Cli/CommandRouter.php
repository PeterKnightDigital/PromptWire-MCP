<?php
/**
 * PW-MCP Command Router
 * 
 * Routes CLI commands to their appropriate handlers and returns
 * structured data suitable for JSON output. This is the main
 * orchestrator for all PW-MCP CLI operations.
 * 
 * @package     PwMcp
 * @subpackage  Cli
 * @author      Peter Knight
 * @license     MIT
 */

namespace PwMcp\Cli;

/**
 * Routes and executes CLI commands for PW-MCP
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
        
        // Check if the PwMcp module is installed in ProcessWire
        $moduleLoaded = $modules->isInstalled('PwMcp');
        
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire, $root);
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
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
    private function syncStatus(?string $directory = null): array {
        // Load sync manager
        require_once(__DIR__ . '/../Sync/SyncManager.php');
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
        return $syncManager->createPageScaffold($template, $parentPath, $pageName, $title);
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
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
        
        $syncManager = new \PwMcp\Sync\SyncManager($this->wire);
        return $syncManager->publishPages($directory, $dryRun, $unpublished);
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
        
        $exporter = new \PwMcp\Schema\SchemaExporter($this->wire);
        return $exporter->export();
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
            'name' => 'PW-MCP CLI',
            'version' => '1.0.0',
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
                'page:push [path]' => 'Push local changes to ProcessWire (--dry-run=0 to apply)',
                'pages:pull [selector]' => 'Pull multiple pages by selector, parent, or template',
                'pages:push [directory]' => 'Push all local changes in directory (--dry-run=0 to apply)',
                'sync:status [directory]' => 'Check sync status of all pulled pages',
                'sync:reconcile [directory]' => 'Fix path drift and detect orphaned pages',
                'page:new [template] [parent] [name]' => 'Create new page scaffold locally',
                'page:publish [path]' => 'Publish new page to ProcessWire (--dry-run=0 to create)',
                'pages:publish [directory]' => 'Bulk publish new pages (--dry-run=0 to create)',
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
            ],
        ];
    }
}
