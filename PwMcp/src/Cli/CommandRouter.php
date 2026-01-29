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
                // Check if --include=files was specified
                $includeFiles = in_array('files', $flags['include'] ?? []);
                return $this->getPage($idOrPath, $includeFiles);
                
            case 'query-pages':
                $selector = $positional[0] ?? null;
                if (!$selector) {
                    return ['error' => 'Selector required'];
                }
                return $this->queryPages($selector);
                
            case 'export-schema':
                return $this->exportSchema();
                
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
     * fields only return counts; use --include=files for full metadata.
     * 
     * @param int|string $idOrPath Page ID (numeric) or path (e.g., "/about/")
     * @param bool       $includeFiles Include file/image metadata
     * @return array Page data or error
     */
    private function getPage($idOrPath, bool $includeFiles = false): array {
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
            $value = $page->get($field->name);
            $fields[$field->name] = $this->formatFieldValue($field, $value, $includeFiles);
        }
        
        return [
            'id' => $page->id,
            'name' => $page->name,
            'path' => $page->path,
            'url' => $page->url,
            'template' => $page->template->name,
            'status' => $page->status,
            'created' => $page->created,
            'modified' => $page->modified,
            'fields' => $fields,
        ];
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
     * @return mixed Formatted value suitable for JSON encoding
     */
    private function formatFieldValue($field, $value, bool $includeFiles = false) {
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
            // By default, just return count to avoid large payloads
            if (!$includeFiles) {
                return ['_count' => $value->count()];
            }
            
            // Return full file metadata when requested
            $files = [];
            foreach ($value as $file) {
                $fileData = [
                    'filename' => $file->name,
                    'url' => $file->url,
                    'size' => $file->filesize,
                    'description' => $file->description,
                ];
                
                // Add image-specific properties
                if ($file instanceof \ProcessWire\Pageimage) {
                    $fileData['width'] = $file->width;
                    $fileData['height'] = $file->height;
                }
                
                $files[] = $fileData;
            }
            return $files;
        }
        
        // Handle repeater/matrix fields
        if ($value instanceof \ProcessWire\RepeaterPageArray) {
            $items = [];
            foreach ($value as $item) {
                $itemFields = [];
                foreach ($item->template->fields as $f) {
                    // Skip internal repeater fields
                    if ($f->name !== 'repeater_matrix_type') {
                        $itemFields[$f->name] = $this->formatFieldValue($f, $item->get($f->name), $includeFiles);
                    }
                }
                $items[] = $itemFields;
            }
            return $items;
        }
        
        // Handle generic WireArray
        if ($value instanceof \ProcessWire\WireArray) {
            return $value->getArray();
        }
        
        // Default: return as-is (strings, numbers, booleans)
        return $value;
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
            'version' => '0.1.0',
            'description' => 'ProcessWire ↔ Cursor MCP Bridge CLI',
            'commands' => [
                'health' => 'Check connection and get site info',
                'list-templates' => 'List all templates',
                'get-template [name]' => 'Get template details',
                'list-fields' => 'List all fields (--include=usage for usage info)',
                'get-field [name]' => 'Get field details',
                'get-page [id|path]' => 'Get page by ID or path (--include=files for file metadata)',
                'query-pages [selector]' => 'Query pages by selector',
                'export-schema' => 'Export full schema (--format=yaml for YAML output)',
                'help' => 'Show this help',
            ],
            'flags' => [
                '--format=json|yaml' => 'Output format (default: json)',
                '--pretty' => 'Pretty-print JSON',
                '--include=usage' => 'Include field usage info',
                '--include=files' => 'Include file/image metadata',
            ],
        ];
    }
}
