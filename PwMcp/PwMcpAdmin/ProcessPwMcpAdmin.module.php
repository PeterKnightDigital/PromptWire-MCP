<?php namespace ProcessWire;

/**
 * PW-MCP Admin - Sync Status Dashboard
 * 
 * Provides a visual dashboard in ProcessWire admin for managing
 * content sync operations (pull/push pages to YAML files).
 * 
 * @package     PwMcp
 * @subpackage  Admin
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 * @version     1.0.0
 */

class ProcessPwMcpAdmin extends Process {

    /**
     * Module information
     * 
     * @return array Module info array
     */
    public static function getModuleInfo(): array {
        return [
            'title' => 'PW-MCP Admin',
            'summary' => 'Admin UI for PW-MCP: manage content sync between ProcessWire and YAML files',
            'version' => '1.1.0',
            'author' => 'Peter Knight',
            'icon' => 'refresh',
            'requires' => ['ProcessWire>=3.0.165', 'PwMcp'],
            'permission' => 'pw-mcp-admin',
            'permissions' => [
                'pw-mcp-admin' => 'Use PW-MCP Admin dashboard',
            ],
            'page' => [
                'name' => 'pw-mcp-admin',
                'parent' => 'setup',
                'title' => 'PW-MCP Admin',
            ],
        ];
    }

    /**
     * Path to SyncManager
     * @var string
     */
    protected $syncManagerPath;

    /**
     * Initialize the module
     */
    public function init() {
        parent::init();
        
        // Path to PwMcp module's SyncManager
        $this->syncManagerPath = $this->wire('config')->paths->siteModules . 'PwMcp/src/Sync/SyncManager.php';
    }

    /**
     * Get Lucide icon SVG markup.
     * Delegates to the shared LucideIcons helper so icon paths are
     * maintained in one place across all PwMcp modules.
     * 
     * @param string $name Icon name (e.g. 'file-input')
     * @param int $size Icon size in pixels (default 16)
     * @return string SVG markup
     */
    protected function lucideIcon(string $name, int $size = 16): string {
        require_once dirname(__DIR__) . '/PwMcp/src/UI/LucideIcons.php';
        return \PwMcp\UI\LucideIcons::render($name, $size);
    }

    /**
     * Get SyncManager instance
     * 
     * @return \PwMcp\Sync\SyncManager
     */
    protected function getSyncManager() {
        require_once($this->syncManagerPath);
        return new \PwMcp\Sync\SyncManager($this->wire());
    }

    /**
     * Get workspace root path
     * 
     * @return string
     */
    protected function getWorkspaceRoot(): string {
        return $this->wire('config')->paths->assets . 'pw-mcp/';
    }

    /**
     * Get local sync path for a page
     * 
     * Handles the special case where homepage (path="/") is stored in 'home/' folder
     * 
     * @param Page $page The page
     * @return string Absolute path to local sync directory (with trailing slash)
     */
    protected function getLocalPathForPage($page): string {
        $pathSegments = trim($page->path, '/');
        if (empty($pathSegments)) {
            // Homepage is stored in 'home/' folder
            return $this->getWorkspaceRoot() . 'home/';
        }
        return $this->getWorkspaceRoot() . $pathSegments . '/';
    }

    /**
     * Get human-readable label for a field
     * 
     * Handles both simple field names and matrix/repeater paths like "matrix→body[1]"
     * 
     * @param string $fieldName Field name or path
     * @return string|null Field label or null if not found
     */
    protected function getFieldLabel(string $fieldName): ?string {
        // Handle matrix/repeater paths like "matrix→body[1]"
        // Extract the base field name
        $baseName = $fieldName;
        if (strpos($fieldName, '→') !== false) {
            $parts = explode('→', $fieldName);
            $baseName = $parts[0];
        }
        
        // Remove array notation like [1]
        $baseName = preg_replace('/\[\d+\]/', '', $baseName);
        
        $field = $this->wire('fields')->get($baseName);
        if ($field && $field->label) {
            return $field->label;
        }
        
        return null;
    }
    
    /**
     * Get field description/notes
     * 
     * @param string $fieldName Field name
     * @return string|null Field description or null
     */
    protected function getFieldDescription(string $fieldName): ?string {
        // Extract base field name (handle matrix paths)
        $baseName = $fieldName;
        if (strpos($fieldName, '→') !== false) {
            $parts = explode('→', $fieldName);
            $baseName = $parts[0];
        }
        $baseName = preg_replace('/\[\d+\]/', '', $baseName);
        
        $field = $this->wire('fields')->get($baseName);
        if ($field && $field->description) {
            return $field->description;
        }
        
        return null;
    }
    
    /**
     * Heuristic: value looks like rich text (HTML) and likely has an external file in sync.
     *
     * @param mixed $value
     * @return bool
     */
    protected function looksLikeRichText($value): bool {
        if (!is_string($value) || $value === '') {
            return false;
        }
        $tags = ['<p', '<h1', '<h2', '<h3', '<h4', '<h5', '<h6', '<ul', '<ol', '<table', '<div', '<blockquote'];
        foreach ($tags as $tag) {
            if (stripos($value, $tag) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Format a field value for display in the preview
     *
     * @param mixed $value The field value
     * @param string|null $fieldName Optional field machine name to show at bottom right (may contain HTML if $fieldNameIsHtml)
     * @param bool $fieldNameIsHtml When true, $fieldName is output unescaped (for built helper text with bold labels)
     * @return string HTML-formatted content
     */
    protected function formatFieldValueForPreview($value, ?string $fieldName = null, bool $fieldNameIsHtml = false): string {
        $content = '';
        
        if ($value === null || $value === '') {
            $content = '<em style="color: #999;">' . $this->_('(empty)') . '</em>';
        } elseif (is_bool($value)) {
            $content = '<input type="text" class="pwmcp-preview-input" value="' . ($value ? $this->_('Yes') : $this->_('No')) . '" disabled />';
        } elseif (is_numeric($value)) {
            $content = '<input type="text" class="pwmcp-preview-input" value="' . htmlspecialchars((string) $value) . '" disabled />';
        } elseif (is_string($value)) {
            // Count lines to decide between input and textarea
            $lineCount = substr_count($value, "\n") + 1;
            if ($lineCount <= 1 && strlen($value) < 100) {
                // Short single-line value - use a text input
                $content = '<input type="text" class="pwmcp-preview-input" value="' . htmlspecialchars($value) . '" disabled />';
            } else {
                // Multi-line or long content - use a textarea (shows raw HTML source)
                $rows = min(max($lineCount, 8), 30);
                $content = '<textarea class="pwmcp-preview-textarea" rows="' . $rows . '" disabled>' . htmlspecialchars($value) . '</textarea>';
            }
        } elseif (is_array($value)) {
            // Check for page reference
            if (isset($value['_pageRef']) || (isset($value[0]) && isset($value[0]['_pageRef']))) {
                $refs = isset($value['_pageRef']) ? [$value] : $value;
                $titles = [];
                foreach ($refs as $ref) {
                    $titles[] = $ref['_comment'] ?? ('ID: ' . ($ref['id'] ?? '?'));
                }
                $content = '<input type="text" class="pwmcp-preview-input" value="' . htmlspecialchars(implode(', ', $titles)) . '" disabled />';
            }
            // Check for option field
            elseif (isset($value['_label'])) {
                $content = '<input type="text" class="pwmcp-preview-input" value="' . htmlspecialchars($value['_label']) . '" disabled />';
            }
            elseif (isset($value[0]['_label'])) {
                $labels = array_map(fn($v) => $v['_label'], $value);
                $content = '<input type="text" class="pwmcp-preview-input" value="' . htmlspecialchars(implode(', ', $labels)) . '" disabled />';
            }
            // Generic array - show as formatted
            else {
                $formatted = print_r($value, true);
                $rows = min(substr_count($formatted, "\n") + 1, 15);
                $content = '<textarea class="pwmcp-preview-textarea" rows="' . $rows . '" disabled>' . htmlspecialchars($formatted) . '</textarea>';
            }
        } else {
            $content = '<input type="text" class="pwmcp-preview-input" value="' . htmlspecialchars((string) $value) . '" disabled />';
        }
        
        // Add field name at bottom right (like ProcessWire page editor)
        if ($fieldName) {
            $content .= '<div class="pwmcp-field-name">' . ($fieldNameIsHtml ? $fieldName : htmlspecialchars($fieldName)) . '</div>';
        }
        
        return $content;
    }
    
    /**
     * Build the changes preview using ProcessWire Inputfield structure
     * 
     * @param array $changes Array of field changes
     * @param int|null $pageId Page ID for display reference
     * @return InputfieldWrapper
     */
    protected function buildChangesPreview(array $changes, ?int $pageId = null): InputfieldWrapper {
        $modules = $this->wire('modules');
        $wrapper = $modules->get('InputfieldWrapper');
        
        // Group changes by parent field (for matrix items)
        $grouped = [];
        $regularFields = [];
        
        foreach ($changes as $fieldKey => $change) {
            $parentField = $change['parentField'] ?? null;
            if ($parentField) {
                if (!isset($grouped[$parentField])) {
                    $grouped[$parentField] = [];
                }
                $grouped[$parentField][$fieldKey] = $change;
            } else {
                $regularFields[$fieldKey] = $change;
            }
        }
        
        // Add regular fields first
        foreach ($regularFields as $fieldKey => $change) {
            $fieldLabel = $this->getFieldLabel($fieldKey);
            $fieldDesc = $this->getFieldDescription($fieldKey);
            $newValue = $change['newValue'] ?? null;
            
            // Build helper text with bold labels: Field • ID • File (file only for rich text)
            $machineKey = '<strong>Field:</strong> ' . htmlspecialchars($fieldKey);
            if ($pageId) {
                $machineKey .= '   •   <strong>ID:</strong> ' . (int) $pageId;
            }
            if ($this->looksLikeRichText($newValue)) {
                $machineKey .= '   •   <strong>File:</strong> fields/' . htmlspecialchars(strtolower($fieldKey)) . '.html';
            }
            
            $f = $modules->get('InputfieldMarkup');
            $f->wrapAttr('data-pwmcp-field-key', $fieldKey);
            $f->label = $fieldLabel ?: $fieldKey;
            if ($fieldDesc) {
                $f->description = $fieldDesc;
            }
            $f->collapsed = Inputfield::collapsedNo; // Open by default
            $f->value = $this->formatFieldValueForPreview($newValue, $machineKey, true);
            
            $wrapper->add($f);
        }
        
        // Add matrix/repeater groups
        foreach ($grouped as $parentField => $subChanges) {
            $parentLabel = $this->getFieldLabel($parentField);
            $parentDesc = $this->getFieldDescription($parentField);
            
            // Create a fieldset for the matrix field
            $fieldset = $modules->get('InputfieldFieldset');
            $fieldset->label = $parentLabel ?: $parentField;
            if ($parentDesc) {
                $fieldset->description = $parentDesc;
            }
            $fieldset->collapsed = Inputfield::collapsedNo;
            
            // Add each sub-field as a nested inputfield
            foreach ($subChanges as $subKey => $subChange) {
                $subFieldName = $subChange['subField'] ?? preg_replace('/\[\d+\]$/', '', $subKey);
                // Strip parent prefix if subKey contains it (e.g. "matrix→Body[1]" → "Body[1]")
                if (strpos($subFieldName, '→') !== false) {
                    $parts = explode('→', $subFieldName);
                    $subFieldName = end($parts);
                }
                
                $itemIndex = $subChange['itemIndex'] ?? null;
                $matrixType = $subChange['matrixType'] ?? null;
                
                // Build label: use matrix type label for the accordion label
                // e.g. "Body [2]", "Breakout Box [1]", "FAQ [3]"
                if ($matrixType) {
                    // Label comes pre-formatted from SyncManager, use as-is
                    $displayLabel = $matrixType;
                } else {
                    $subLabel = $this->getFieldLabel($subFieldName);
                    $displayLabel = $subLabel ?: $subFieldName;
                }
                if ($itemIndex) {
                    $displayLabel .= ' [' . $itemIndex . ']';
                }
                
                $newValue = $subChange['newValue'] ?? null;
                $itemId = $subChange['itemId'] ?? null;
                $typeSlug = $subChange['typeSlug'] ?? null;
                $subField = $subChange['subField'] ?? preg_replace('/\[\d+\]$/', '', $subKey);
                // Helper text with bold labels: Field • ID • File (file only for rich text when typeSlug known)
                $machineKey = '<strong>Field:</strong> ' . htmlspecialchars($subKey) . '   •   <strong>ID:</strong> ' . (int) $itemId;
                if ($typeSlug && $this->looksLikeRichText($newValue)) {
                    $machineKey .= '   •   <strong>File:</strong> ' . htmlspecialchars('[' . $itemId . ']-' . $typeSlug . '-' . strtolower($subField) . '.html');
                }
                
                $subF = $modules->get('InputfieldMarkup');
                $subF->wrapAttr('data-pwmcp-field-key', $subKey);
                $subF->label = $displayLabel;
                $subF->collapsed = Inputfield::collapsedNo;
                $subF->value = $this->formatFieldValueForPreview($newValue, $machineKey, true);
                
                $fieldset->add($subF);
            }
            
            $wrapper->add($fieldset);
        }
        
        return $wrapper;
    }

    /**
     * Build the helper text (Field • ID • File) for a single change - used by preview and refresh.
     *
     * @param array $change Change entry from SyncManager (keys: newValue, itemId, typeSlug, subField, etc.)
     * @param string $fieldKey Full field key (e.g. "body" or "matrix→Body[2]")
     * @param int|null $pageId Page ID for regular fields
     * @return string HTML helper string for formatFieldValueForPreview
     */
    protected function buildFieldPreviewHelperText(array $change, string $fieldKey, ?int $pageId): string {
        $newValue = $change['newValue'] ?? null;
        $itemId = $change['itemId'] ?? null;
        $typeSlug = $change['typeSlug'] ?? null;
        $subField = $change['subField'] ?? preg_replace('/\[\d+\]$/', '', $fieldKey);
        if ($itemId !== null) {
            $machineKey = '<strong>Field:</strong> ' . htmlspecialchars($fieldKey) . '   •   <strong>ID:</strong> ' . (int) $itemId;
            if ($typeSlug && $this->looksLikeRichText($newValue)) {
                $machineKey .= '   •   <strong>File:</strong> ' . htmlspecialchars('[' . $itemId . ']-' . $typeSlug . '-' . strtolower($subField) . '.html');
            }
        } else {
            $machineKey = '<strong>Field:</strong> ' . htmlspecialchars($fieldKey);
            if ($pageId) {
                $machineKey .= '   •   <strong>ID:</strong> ' . (int) $pageId;
            }
            if ($this->looksLikeRichText($newValue)) {
                $machineKey .= '   •   <strong>File:</strong> fields/' . htmlspecialchars(strtolower($fieldKey)) . '.html';
            }
        }
        return $machineKey;
    }

    /**
     * AJAX: return refreshed HTML for one field's preview (re-read from disk).
     *
     * @param int $pageId
     * @param string $fieldKey
     * @return string JSON with { html: "..." } or { error: "..." }
     */
    protected function refreshPushFieldPreview(int $pageId, string $fieldKey): string {
        $page = $this->wire('pages')->get($pageId);
        if (!$page || !$page->id) {
            return json_encode(['error' => 'Page not found']);
        }
        $syncManager = $this->getSyncManager();
        $workspacePath = $this->getLocalPathForPage($page);
        $result = $syncManager->pushPage($workspacePath, true);
        if (!isset($result['changes'][$fieldKey])) {
            return json_encode(['error' => 'Field not in changes']);
        }
        $change = $result['changes'][$fieldKey];
        $newValue = $change['newValue'] ?? null;
        $helper = $this->buildFieldPreviewHelperText($change, $fieldKey, $pageId);
        $html = $this->formatFieldValueForPreview($newValue, $helper, true);
        return json_encode(['html' => $html]);
    }

    /**
     * Main execute - show sync dashboard with native PW styling
     * 
     * @return string Rendered output
     */
    public function ___execute(): string {
        $modules = $this->wire('modules');
        $input = $this->wire('input');
        $session = $this->wire('session');
        $pages = $this->wire('pages');
        
        // Set page headline
        $this->headline($this->_('MCP Sync'));
        $this->browserTitle($this->_('MCP Sync'));
        
        // Handle bulk actions
        if ($input->post('bulk_action') && $input->post('selected_pages')) {
            return $this->handleBulkAction(
                $input->post('bulk_action'),
                $input->post('selected_pages')
            );
        }
        
        // Get filter values
        $searchQuery = $input->get('q') ?: '';
        $templateFilter = $input->get('template') ?: '';
        $statusFilter = $input->get('status') ?: '';
        
        // Get sync status data
        $syncManager = $this->getSyncManager();
        $statusData = $syncManager->getSyncStatus();
        $syncedById = $this->buildSyncLookup($statusData);
        
        // Build output
        $out = '';
        
        // =====================================================================
        // FILTER BAR
        // =====================================================================
        
        // Calculate counts for filters AND build page hierarchy for JS
        $templateCounts = [];
        $statusCounts = [
            'clean' => 0,
            'localDirty' => 0,
            'remoteChanged' => 0,
            'conflict' => 0,
            'notPulled' => 0,
        ];
        $totalPages = 0;
        $pageHierarchy = []; // pageId => parentId
        $pageStatuses = [];  // pageId => status
        
        foreach ($pages->find("include=all") as $page) {
            if ($page->template->flags & Template::flagSystem) continue;
            // Skip repeater/matrix item pages - these are internal storage, not real content pages
            if (strpos($page->template->name, 'repeater_') === 0) continue;
            $totalPages++;
            
            // Build hierarchy map
            $pageHierarchy[$page->id] = $page->parent->id;
            
            // Count by template
            $templateName = $page->template->name;
            $templateCounts[$templateName] = ($templateCounts[$templateName] ?? 0) + 1;
            
            // Count by status and store for JS
            $status = $this->getPageStatus($page, $syncedById);
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $pageStatuses[$page->id] = $status;
        }
        
        // Build filter form using ProcessWire Inputfield API (native admin theme styling)
        /** @var InputfieldWrapper $filterWrapper */
        $filterWrapper = new InputfieldWrapper();
        
        // Search field
        /** @var InputfieldText $f */
        $f = $this->wire('modules')->get('InputfieldText');
        $f->attr('name', 'q');
        $f->attr('id', 'pwmcp-search');
        $f->label = $this->_('Search');
        $f->attr('placeholder', $this->_('Search pages...'));
        $f->attr('value', $searchQuery);
        $f->columnWidth = 40;
        if ($searchQuery) $f->attr('autofocus', 'autofocus');
        $filterWrapper->add($f);
        
        // Template filter
        /** @var InputfieldSelect $f */
        $f = $this->wire('modules')->get('InputfieldSelect');
        $f->attr('name', 'template');
        $f->label = $this->_('Template');
        $f->addOption('', $this->_('All') . ' (' . $totalPages . ')');
        foreach ($this->wire('templates') as $template) {
            if ($template->flags & Template::flagSystem) continue;
            if (strpos($template->name, 'repeater_') === 0) continue;
            $count = $templateCounts[$template->name] ?? 0;
            $f->addOption($template->name, $template->name . ' (' . $count . ')');
        }
        $f->attr('value', $templateFilter);
        $f->attr('onchange', 'this.form.submit()');
        $f->columnWidth = 30;
        $filterWrapper->add($f);
        
        // Status filter
        /** @var InputfieldSelect $f */
        $f = $this->wire('modules')->get('InputfieldSelect');
        $f->attr('name', 'status');
        $f->label = $this->_('Sync Status');
        $statuses = [
            '' => [$this->_('All'), $totalPages],
            'clean' => [$this->_('Clean'), $statusCounts['clean']],
            'localDirty' => [$this->_('File Newer'), $statusCounts['localDirty']],
            'remoteChanged' => [$this->_('Wire Newer'), $statusCounts['remoteChanged']],
            'conflict' => [$this->_('Conflict'), $statusCounts['conflict']],
            'notPulled' => [$this->_('Untracked'), $statusCounts['notPulled']],
        ];
        foreach ($statuses as $val => $data) {
            list($label, $count) = $data;
            $f->addOption($val, $label . ' (' . $count . ')');
        }
        $f->attr('value', $statusFilter);
        $f->attr('onchange', 'this.form.submit()');
        $f->columnWidth = 30;
        $filterWrapper->add($f);
        
        $out .= '<form method="get" action="./" class="pwmcp-filters">';
        $out .= $filterWrapper->render();
        $out .= '</form>';
        
        // =====================================================================
        // PAGE TABLE - Using native MarkupAdminDataTable
        // =====================================================================
        
        $filteredPages = [];
        $isSearchOrFiltered = $searchQuery || $templateFilter || $statusFilter;
        
        if ($isSearchOrFiltered) {
            // Search/filter mode: show flat list of matching pages
            $selector = "include=all, sort=path";
            if ($searchQuery) {
                $selector .= ", title|name%=" . $this->wire('sanitizer')->selectorValue($searchQuery);
            }
            if ($templateFilter) {
                $selector .= ", template=$templateFilter";
            }
            
            $pageList = $pages->find($selector . ", limit=200");
            
            foreach ($pageList as $page) {
                if ($page->template->flags & Template::flagSystem) continue;
                
                $status = $this->getPageStatus($page, $syncedById);
                $syncInfo = $syncedById[$page->id] ?? null;
                
                if ($statusFilter && $status !== $statusFilter) continue;
                
                $filteredPages[] = [
                    'page' => $page,
                    'status' => $status,
                    'syncInfo' => $syncInfo,
                ];
            }
        } else {
            // Default tree view: Home + its children
            $homePage = $pages->get(1);
            if ($homePage->id && !($homePage->template->flags & Template::flagSystem)) {
                $status = $this->getPageStatus($homePage, $syncedById);
                $syncInfo = $syncedById[$homePage->id] ?? null;
                
                $filteredPages[] = [
                    'page' => $homePage,
                    'status' => $status,
                    'syncInfo' => $syncInfo,
                    'isHome' => true,
                ];
            }
            
            // Add Home's children at depth 1, sorted by the template's configured sort order
            $homeSortfield = $homePage->template->sortfield ?: 'sort';
            $homeChildren = $pages->find("parent=1, include=all, sort=$homeSortfield");
            foreach ($homeChildren as $page) {
                if ($page->template->flags & Template::flagSystem) continue;
                
                $status = $this->getPageStatus($page, $syncedById);
                $syncInfo = $syncedById[$page->id] ?? null;
                
                $filteredPages[] = [
                    'page' => $page,
                    'status' => $status,
                    'syncInfo' => $syncInfo,
                    'depth' => 1,
                ];
            }
        }
        
        // Start form for bulk actions (wraps the table)
        $out .= '<form method="post" action="./" id="pwmcp-tree-form">';
        $out .= '<input type="hidden" name="bulk_action" value="" class="pwmcp-bulk-action-field">';
        
        // Selection toolbar - includes Expand All toggle and total page count
        $out .= '<div class="pwmcp-selection-toolbar" data-total-pages="' . $totalPages . '">';
        $out .= '<div class="pwmcp-selection-summary">';
        $out .= '<label class="pwmcp-switch">';
        $out .= '<input type="checkbox" id="pwmcp-expand-all">';
        $out .= '<span class="pwmcp-slider"></span>';
        $out .= '</label>';
        $out .= '<span class="pwmcp-switch-label">' . $this->_('Expand All') . '</span>';
        $out .= '<span class="pwmcp-selection-count">' . sprintf($this->_('0 of %d pages selected'), $totalPages) . '</span>';
        $out .= '</div>';
        $out .= '<div class="pwmcp-selection-actions">';
        $out .= '<button type="button" class="uk-button uk-button-default uk-button-small pwmcp-bulk-export" uk-tooltip="title: Wire to File" disabled>';
        $out .= $this->lucideIcon('file-input', 16) . ' <span>Wire to File</span>';
        $out .= '</button>';
        $out .= '<button type="button" class="uk-button uk-button-primary uk-button-small pwmcp-bulk-import" uk-tooltip="title: File to Wire" disabled>';
        $out .= $this->lucideIcon('file-output', 16) . ' <span>File to Wire</span>';
        $out .= '</button>';
        $out .= '</div>';
        $out .= '</div>';
        
        // Build table manually for expand/collapse support
        $out .= '<table class="AdminDataTable AdminDataList uk-table uk-table-divider uk-table-small pwmcp-tree-table">';
        $out .= '<thead><tr>';
        $out .= '<th style="width:30px;"><input type="checkbox" class="pwmcp-select-all"></th>';
        $out .= '<th>' . $this->_('Title') . '</th>';
        $out .= '<th>' . $this->_('Template') . '</th>';
        $out .= '<th>' . $this->_('Sync Status') . '</th>';
        $out .= '<th>' . $this->_('Modified') . '</th>';
        $out .= '<th>' . $this->_('Actions') . '</th>';
        $out .= '</tr></thead>';
        $out .= '<tbody id="pwmcp-tree-body">';
        
        foreach ($filteredPages as $item) {
            $depth = $item['depth'] ?? 0;
            $isHome = $item['isHome'] ?? false;
            $out .= $this->buildTreeRow($item['page'], $item['status'], $depth, $syncedById, $isHome);
        }
        
        $out .= '</tbody></table>';
        $out .= '</form>';
        
        // Push confirmation modal
        $out .= '<div id="pwmcp-import-modal" class="pwmcp-modal" style="display:none;">';
        $out .= '<div class="pwmcp-modal-backdrop"></div>';
        $out .= '<div class="pwmcp-modal-dialog">';
        $out .= '<div class="pwmcp-modal-header">';
        $out .= '<h3>Confirm File to Wire</h3>';
        $out .= '<button type="button" class="pwmcp-modal-close">&times;</button>';
        $out .= '</div>';
        $out .= '<div class="pwmcp-modal-body">';
        $out .= '<p class="pwmcp-modal-message"></p>';
        $out .= '<p class="uk-text-warning"><strong>This action cannot be undone.</strong></p>';
        $out .= '</div>';
        $out .= '<div class="pwmcp-modal-footer">';
        $out .= '<button type="button" class="uk-button uk-button-default pwmcp-modal-cancel">Cancel</button>';
        $out .= '<button type="button" class="uk-button uk-button-danger pwmcp-modal-confirm"></button>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</div>';
        
        // Clear filters link when search/filters are active
        if ($isSearchOrFiltered) {
            $out .= '<p style="margin-top: 1em;"><a href="./">&larr; ' . $this->_('Clear filters') . '</a></p>';
        }
        
        // Inject page hierarchy and status data for JavaScript
        $out .= '<script>';
        $out .= 'var pwmcpPageParents = ' . json_encode($pageHierarchy) . ';';
        $out .= 'var pwmcpPageStatuses = ' . json_encode($pageStatuses) . ';';
        $out .= '</script>';
        
        // JavaScript
        $out .= $this->getCleanScript();
        
        // =====================================================================
        // HEADER BUTTONS (inline HTML to avoid ProcessWire duplication issue)
        // =====================================================================
        
        $headerButtons = '<div class="pwmcp-header-buttons">';
        $headerButtons .= '<a href="./" class="uk-button uk-button-primary"><i class="fa fa-refresh"></i> ' . $this->_('Refresh') . '</a>';
        $headerButtons .= '<a href="./reconcile/" class="uk-button uk-button-default"><i class="fa fa-wrench"></i> ' . $this->_('Reconcile') . '</a>';
        $headerButtons .= '</div>';
        
        return $headerButtons . $out;
    }
    
    /**
     * Build sync status lookup by page ID
     */
    protected function buildSyncLookup(array $statusData): array {
        $lookup = [];
        if (isset($statusData['pages']) && is_array($statusData['pages'])) {
            foreach ($statusData['pages'] as $pageStatus) {
                if (isset($pageStatus['pageId'])) {
                    $lookup[$pageStatus['pageId']] = $pageStatus;
                }
            }
        }
        return $lookup;
    }
    
    /**
     * Get actual page status considering both lookup and file existence
     * 
     * @param Page $page The page to check
     * @param array $syncLookup Lookup array from getSyncStatus()
     * @return string Status: clean, localDirty, remoteChanged, conflict, notPulled
     */
    protected function getPageStatus($page, array $syncLookup): string {
        // First check the lookup (which contains all non-clean pages)
        if (isset($syncLookup[$page->id])) {
            return $syncLookup[$page->id]['status'];
        }
        
        // Not in lookup - check if it's been pulled (would be clean) or not pulled at all
        $localPath = $this->getLocalPathForPage($page);
        $metaFile = $localPath . 'page.meta.json';
        
        if (file_exists($metaFile)) {
            // Has been pulled but not in lookup = must be clean
            return 'clean';
        }
        
        // No local files = not pulled
        return 'notPulled';
    }
    
    /**
     * Build a single tree row for a page
     * 
     * @param Page $page The page to render
     * @param string $status Sync status
     * @param int $depth Nesting depth for indentation
     * @param array $syncLookup Sync status lookup array
     * @param bool $isExpanded Whether the node should show as expanded
     * @param bool $ajaxMode If true, badge cell is left empty for client-side rendering
     * @return string HTML for the table row
     */
    protected function buildTreeRow(Page $page, string $status, int $depth, array $syncLookup, bool $isExpanded = false, bool $ajaxMode = false): string {
        $indent = $depth * 20; // 20px per level
        $isModified = in_array($status, ['localDirty', 'conflict']);
        
        // Determine page publish status for visual styling (matching ProcessWire's official tree)
        $pageStateClasses = [];
        if ($page->isUnpublished()) {
            $pageStateClasses[] = 'pwmcp-unpublished';
        }
        if ($page->isHidden()) {
            $pageStateClasses[] = 'pwmcp-hidden';
        }
        $stateClassStr = implode(' ', $pageStateClasses);
        
        $html = '<tr data-page-id="' . $page->id . '" data-depth="' . $depth . '" data-parent-id="' . $page->parent->id . '" data-status="' . $status . '" data-modified="' . ($isModified ? '1' : '0') . '"' . ($stateClassStr ? ' class="' . $stateClassStr . '"' : '') . '>';
        
        // Checkbox
        $html .= '<td><input type="checkbox" name="selected_pages[]" value="' . $page->id . '" class="pwmcp-page-checkbox" data-page-id="' . $page->id . '"></td>';
        
        // Title with chevron for expandable parents
        $html .= '<td class="pwmcp-title-cell" style="padding-left:' . ($indent + 8) . 'px;">';
        if ($page->numChildren > 0) {
            $expandedAttr = $isExpanded ? 'true' : 'false';
            $html .= '<span class="pwmcp-toggle" data-page-id="' . $page->id . '" data-expanded="' . $expandedAttr . '" data-has-children="true" title="' . $this->_('Expand') . '">';
            $html .= '<svg class="pwmcp-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
            $html .= '<span class="pwmcp-toggle-dot" style="display:none;"></span>';
            $html .= '</span> ';
        } else {
            // Spacer for alignment
            $html .= '<span class="pwmcp-toggle-spacer" style="display:inline-block;width:14px;"></span> ';
        }
        $fullTitle = $page->title ?: $page->name;
        $displayTitle = (mb_strlen($fullTitle) > 50) ? mb_substr($fullTitle, 0, 47) . '...' : $fullTitle;
        $html .= '<a href="' . $page->editUrl . '" title="' . htmlspecialchars($fullTitle) . '">' . htmlspecialchars($displayTitle) . '</a>';
        if ($page->numChildren > 0) {
            $html .= ' <small>' . $page->numChildren . '</small>';
        }
        $html .= '</td>';
        
        // Template
        $html .= '<td class="pwmcp-col-secondary">' . $page->template->name . '</td>';
        
        // Status badge - in AJAX mode, render empty cell for client-side badge application
        $html .= '<td class="pwmcp-badge-cell">';
        if (!$ajaxMode) {
            $html .= $this->getStatusBadge($status);
        }
        $html .= '</td>';
        
        // Modified
        $html .= '<td class="pwmcp-col-secondary">' . ($page->modified ? wireRelativeTimeStr($page->modified) : '-') . '</td>';
        
        // Actions - uses $status to determine which buttons are enabled
        // In AJAX mode, $status comes from file_exists() check (clean or notPulled)
        $html .= '<td>' . $this->getRowActions($page, $status) . '</td>';
        
        $html .= '</tr>';
        
        return $html;
    }
    
    /**
     * AJAX endpoint: Get children of a page as HTML rows
     * 
     * Badges are applied client-side from the pre-loaded pwmcpPageStatuses JS variable.
     * This avoids the expensive getSyncStatus() call (filesystem scan + DB queries for all pulled pages).
     */
    public function ___executeChildren(): string {
        $input = $this->wire('input');
        $pages = $this->wire('pages');
        
        $parentId = (int) $input->get('id');
        $depth = (int) $input->get('depth') + 1;
        
        if (!$parentId) {
            return '';
        }
        
        // Get children sorted by the parent template's configured sort order
        $parent = $pages->get($parentId);
        $sortfield = $parent->template->sortfield ?: 'sort';
        $children = $pages->find("parent=$parentId, include=all, sort=$sortfield");
        
        // No getSyncStatus() call here - badges are applied client-side
        // from the pwmcpPageStatuses JS variable (already computed on initial page load).
        // We only do a quick file_exists() per child to determine pulled/not-pulled 
        // for the action buttons (export/import/view).
        $syncLookup = [];
        
        $html = '';
        foreach ($children as $child) {
            if ($child->template->flags & Template::flagSystem) continue;
            
            // Quick check: is this page pulled? (just file_exists, no hash/diff calculation)
            $localPath = $this->getLocalPathForPage($child);
            $isPulled = file_exists($localPath . 'page.meta.json');
            $actionStatus = $isPulled ? 'clean' : 'notPulled';
            
            // Badge HTML is left empty - JS applies it from pwmcpPageStatuses
            $html .= $this->buildTreeRow($child, $actionStatus, $depth, $syncLookup, false, true);
        }
        
        // Return raw HTML (for AJAX)
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
    
    /**
     * Get clean JavaScript for interactions
     */
    protected function getCleanScript(): string {
        $childrenUrl = $this->wire('page')->url . 'children/';
        
        return <<<HTML
<style>
/* Header buttons */
.pwmcp-header-buttons { float: right; margin-top: 6px; display: inline-flex; gap: 8px; align-items: center; }
.pwmcp-header-buttons .uk-button { border-radius: 3px; }
.pwmcp-header-buttons .uk-button i { margin-right: 4px; }

/* Filter bar styling */
.pwmcp-filters { margin-bottom: 1.5em; }

/* Selection toolbar */
.pwmcp-selection-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 0;
    margin-bottom: 4px;
}
.pwmcp-selection-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    color: #666;
}
.pwmcp-selection-count {
    font-weight: normal;
    font-size: 0.875rem;
}
.pwmcp-selection-actions {
    display: flex;
    gap: 8px;
}
.pwmcp-selection-actions .uk-button {
    display: flex;
    align-items: center;
    gap: 6px;
    border-radius: 3px;
}
.pwmcp-selection-actions .uk-button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.pwmcp-selection-actions .uk-button .pwmcp-lucide {
    flex-shrink: 0;
}

/* Checkbox styling */
.pwmcp-page-checkbox:indeterminate {
    opacity: 0.6;
}
tr.pwmcp-selected {
    background-color: #f0f7ff !important;
}

/* Chevron dot for hidden selections */
.pwmcp-toggle {
    position: relative;
    display: inline-flex;
    align-items: center;
}
.pwmcp-toggle-dot {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 5px;
    height: 5px;
    background: #1e87f0;
    border-radius: 50%;
    pointer-events: none;
}

/* Push confirmation modal */
.pwmcp-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 10000;
}
.pwmcp-modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}
.pwmcp-modal-dialog {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    min-width: 480px;
    max-width: 600px;
}
.pwmcp-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e5e5;
}
.pwmcp-modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}
.pwmcp-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    line-height: 1;
    color: #999;
    cursor: pointer;
    padding: 0;
    width: 28px;
    height: 28px;
}
.pwmcp-modal-close:hover {
    color: #666;
}
.pwmcp-modal-body {
    padding: 24px;
}
.pwmcp-modal-body p {
    margin: 0 0 12px 0;
}
.pwmcp-modal-body p:last-child {
    margin-bottom: 0;
}
.pwmcp-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 16px 24px;
    border-top: 1px solid #e5e5e5;
}
.pwmcp-modal-footer .uk-button {
    border-radius: 3px;
}
input.pwmcp-searching { 
    background-color: #e8f4fc;
}
.pwmcp-switch-label { font-size: 0.875rem; color: #666; font-weight: normal; }
/* Toggle switch */
.pwmcp-switch {
    position: relative;
    display: inline-block;
    width: 36px;
    height: 20px;
    margin: 0;
}
.pwmcp-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.pwmcp-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 20px;
}
.pwmcp-slider:before {
    position: absolute;
    content: "";
    height: 14px;
    width: 14px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
.pwmcp-switch input:checked + .pwmcp-slider {
    background-color: #1e87f0;
}
.pwmcp-switch input:focus + .pwmcp-slider {
    box-shadow: 0 0 2px #1e87f0;
}
.pwmcp-switch input:checked + .pwmcp-slider:before {
    transform: translateX(16px);
}

/* Tree table styling - only overrides not handled by AdminDataTable/AdminDataList */
.pwmcp-toggle { 
    cursor: pointer; 
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    margin-right: 4px;
    color: #bbb;
    transition: transform 0.15s ease;
}
.pwmcp-toggle:hover { color: #999; }
.pwmcp-toggle[data-expanded="true"] { color: #999; transform: rotate(90deg); }
.pwmcp-icon { display: block; }
.pwmcp-lucide { display: inline-block; vertical-align: middle; }
/* Action icon styling (table row icons only) */
a.pwmcp-action { display: inline-flex !important; align-items: center !important; justify-content: center !important; padding: 4px !important; color: #d9534f !important; cursor: pointer !important; text-decoration: none !important; }
a.pwmcp-action:hover { color: #c9302c !important; cursor: pointer !important; }
a.pwmcp-action[title] { cursor: pointer !important; }
a.pwmcp-action * { cursor: pointer !important; }
/* Selection toolbar buttons - override action icon colors and add padding */
.pwmcp-selection-actions .pwmcp-action { color: inherit !important; padding: 0 15px !important; }
.pwmcp-selection-actions .pwmcp-action:hover { color: inherit !important; }
.pwmcp-selection-actions .uk-button { padding-left: 15px; padding-right: 15px; }
.pwmcp-selection-actions .uk-button svg { margin-right: 6px; }
/* Force cursor on all child elements to prevent question mark */
.pwmcp-action svg { cursor: pointer !important; pointer-events: none !important; }
.pwmcp-action .pwmcp-lucide { cursor: pointer !important; pointer-events: none !important; }
.pwmcp-action path { cursor: pointer !important; pointer-events: none !important; }
/* Disabled action state */
.pwmcp-action-disabled {
    opacity: 0.3;
    cursor: not-allowed !important;
    pointer-events: none !important;
}
.pwmcp-action-disabled svg,
.pwmcp-action-disabled .pwmcp-lucide,
.pwmcp-action-disabled path {
    cursor: not-allowed !important;
}
.pwmcp-action-disabled .pwmcp-lucide {
    color: #999 !important;
    stroke: #999 !important;
}
.pwmcp-tree-table td, .pwmcp-tree-table th { padding-top: 7px; padding-bottom: 7px; }
.pwmcp-col-secondary { font-size: 0.875rem; color: var(--pw-text-color, #354b60); }
/* Status badge overrides - consistent across tree table and preview pages */
.pwmcp-badge { font-size: 11px !important; font-weight: normal !important; padding: 2px 6px !important; border-radius: 4px !important; border: 1px solid !important; display: inline-block !important; line-height: 1.3 !important; text-transform: none !important; }
.pwmcp-badge.uk-label-success { color: rgba(35, 120, 60, 1) !important; border-color: rgba(60, 150, 85, 1) !important; background-color: rgba(40, 167, 69, 0.15) !important; }
.pwmcp-badge.uk-label-warning { color: rgba(150, 110, 0, 1) !important; border-color: rgba(200, 155, 30, 1) !important; background-color: rgba(255, 193, 7, 0.15) !important; }
.pwmcp-badge.uk-label-primary { color: rgba(25, 95, 160, 1) !important; border-color: rgba(65, 145, 210, 1) !important; background-color: rgba(30, 135, 240, 0.15) !important; }
.pwmcp-badge.uk-label-danger { color: rgba(165, 40, 50, 1) !important; border-color: rgba(200, 75, 85, 1) !important; background-color: rgba(220, 53, 69, 0.15) !important; }
.pwmcp-badge.uk-label-muted { color: rgba(120, 120, 120, 1) !important; border-color: rgba(180, 180, 180, 1) !important; background-color: rgba(200, 200, 200, 0.1) !important; }
.pwmcp-toggle-spacer { display: inline-block; width: 14px; }
.pwmcp-title-cell { white-space: nowrap; line-height: 1.4; max-width: 350px; overflow: hidden; text-overflow: ellipsis; }
.pwmcp-title-cell a { display: inline; }
.pwmcp-title-cell small { font-size: 13px; color: #999; }
/* Page publish status styling - only affects the title, not action icons/badges/columns */
tr.pwmcp-unpublished .pwmcp-title-cell a { text-decoration: line-through; color: #999; }
tr.pwmcp-hidden .pwmcp-title-cell a { opacity: 0.6; }
tr.pwmcp-unpublished.pwmcp-hidden .pwmcp-title-cell a { text-decoration: line-through; color: #999; opacity: 0.6; }
.pwmcp-spinner { 
    display: inline-block; 
    width: 12px; 
    height: 12px; 
    margin-left: 6px;
    border: 2px solid #ddd; 
    border-top-color: #1e87f0; 
    border-radius: 50%; 
    animation: pwmcp-spin 0.6s linear infinite;
    vertical-align: middle;
}
@keyframes pwmcp-spin { to { transform: rotate(360deg); } }
tr[data-depth="1"] { background-color: #fcfcfc; }
tr[data-depth="2"] { background-color: #f9f9f9; }
tr[data-depth="3"] { background-color: #f6f6f6; }
tr[data-depth="4"] { background-color: #f3f3f3; }

/* Preview - disabled input/textarea styles (match PW page editor) */
.pwmcp-preview-input,
.pwmcp-preview-textarea {
    width: 100%;
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: #354b60;
    background: #f5f5f5;
    border: 1px solid #cdcdcd;
    border-radius: 2px;
    padding: 8px 10px;
    cursor: default;
}
.pwmcp-preview-input:disabled,
.pwmcp-preview-textarea:disabled {
    opacity: 0.85;
}
.pwmcp-preview-textarea {
    resize: vertical;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: Consolas, monaco, monospace;
    font-size: 13px;
    tab-size: 2;
}
/* Field machine name below field content */
.pwmcp-field-name {
    font-size: 13px;
    color: #999;
    margin-top: 10px;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var childrenUrl = '{$childrenUrl}';
    
    // Badge renderer - applies status badges to AJAX-loaded rows using pre-loaded lookup
    var badgeHtml = {
        'clean':         '<span class="uk-label pwmcp-badge uk-label-success">Clean</span>',
        'localDirty':    '<span class="uk-label pwmcp-badge uk-label-warning">File Newer</span>',
        'remoteChanged': '<span class="uk-label pwmcp-badge uk-label-primary">Wire Newer</span>',
        'conflict':      '<span class="uk-label pwmcp-badge uk-label-danger">Conflict</span>',
        'notPulled':     '<span class="uk-label pwmcp-badge uk-label-muted">Untracked</span>'
    };
    
    function applyBadgesToNewRows(startAfterRow) {
        if (typeof pwmcpPageStatuses === 'undefined') return;
        var row = startAfterRow.nextElementSibling;
        var baseDepth = parseInt(startAfterRow.getAttribute('data-depth'), 10);
        while (row) {
            var rowDepth = parseInt(row.getAttribute('data-depth'), 10);
            if (rowDepth <= baseDepth) break;
            var pageId = row.getAttribute('data-page-id');
            var status = pwmcpPageStatuses[pageId] || 'notPulled';
            var isPulled = (status !== 'notPulled');
            // Apply badge
            var badgeCell = row.querySelector('.pwmcp-badge-cell');
            if (badgeCell && !badgeCell.innerHTML.trim()) {
                badgeCell.innerHTML = badgeHtml[status] || badgeHtml['notPulled'];
            }
            // Update data-status and data-modified attributes
            row.setAttribute('data-status', status);
            row.setAttribute('data-modified', (status === 'localDirty' || status === 'conflict') ? '1' : '0');
            // Fix action buttons to match actual status (server only knows clean/notPulled)
            var actions = row.querySelectorAll('.pwmcp-action');
            for (var i = 0; i < actions.length; i++) {
                var action = actions[i];
                // Skip the first action (Pull) - always enabled
                if (i === 0) continue;
                if (isPulled) {
                    action.classList.remove('pwmcp-action-disabled');
                    action.style.cursor = 'pointer';
                    // Restore href and tooltip if it was disabled
                    if (action.getAttribute('href') === '#' && pageId) {
                        var baseUrl = childrenUrl.replace('children/', '');
                        if (i === 1) {
                            action.setAttribute('href', baseUrl + 'push/?id=' + pageId);
                            action.setAttribute('uk-tooltip', 'title: File to Wire');
                        } else if (i === 2) {
                            action.setAttribute('href', baseUrl + 'view-yaml/?id=' + pageId);
                            action.setAttribute('uk-tooltip', 'title: View YAML');
                            action.classList.add('pw-modal');
                        }
                    }
                } else {
                    action.classList.add('pwmcp-action-disabled');
                    action.setAttribute('href', '#');
                    action.classList.remove('pw-modal');
                    if (i === 1) {
                        action.setAttribute('uk-tooltip', 'title: No local file yet');
                    } else if (i === 2) {
                        action.setAttribute('uk-tooltip', 'title: No local file yet');
                    }
                }
            }
            row = row.nextElementSibling;
        }
    }
    
    // Real-time search with debounce
    var searchInput = document.getElementById('pwmcp-search');
    var searchTimeout = null;
    if (searchInput) {
        // Move cursor to end if autofocused with existing value
        if (searchInput.value) {
            var len = searchInput.value.length;
            searchInput.setSelectionRange(len, len);
        }
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            var value = this.value;
            var input = this;
            
            // Add searching class for visual feedback
            input.classList.add('pwmcp-searching');
            
            searchTimeout = setTimeout(function() {
                // Only submit if value changed and has 2+ chars (or is empty to clear)
                if (value.length >= 2 || value.length === 0) {
                    searchInput.form.submit();
                } else {
                    input.classList.remove('pwmcp-searching');
                }
            }, 400);
        });
        
        // Submit on Enter key
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimeout);
                this.form.submit();
            }
        });
    }
    
    // =====================================================================
    // TREE SELECTION SYSTEM
    // =====================================================================
    
    var selectionState = new Set(); // Stores selected page IDs
    
    // Update selection summary and button states
    function updateSelectionToolbar() {
        var count = selectionState.size;
        var summaryEl = document.querySelector('.pwmcp-selection-count');
        var exportBtn = document.querySelector('.pwmcp-bulk-export');
        var importBtn = document.querySelector('.pwmcp-bulk-import');
        var toolbar = document.querySelector('.pwmcp-selection-toolbar');
        var totalPages = toolbar ? parseInt(toolbar.getAttribute('data-total-pages')) || 0 : 0;
        
        var modifiedCount = 0;
        
        // Count modified pages in selection
        selectionState.forEach(function(pageId) {
            if (isPageModified(pageId)) {
                modifiedCount++;
            }
        });
        
        // Update summary text with "X of Y pages selected" format
        var text = count + ' of ' + totalPages + ' pages selected';
        if (modifiedCount > 0) {
            text += ', ' + modifiedCount + ' modified';
        }
        summaryEl.textContent = text;
        
        // Update button states
        exportBtn.disabled = (count === 0);
        importBtn.disabled = (count === 0 || modifiedCount === 0);
        
        if (importBtn.disabled && count > 0) {
            importBtn.title = 'No modified pages in selection';
        } else {
            importBtn.title = '';
        }
    }
    
    // Build children map from pre-loaded parent map (pwmcpPageParents)
    var pageChildrenMap = {};
    if (typeof pwmcpPageParents !== 'undefined') {
        for (var id in pwmcpPageParents) {
            var parentId = pwmcpPageParents[id];
            if (!pageChildrenMap[parentId]) pageChildrenMap[parentId] = [];
            pageChildrenMap[parentId].push(parseInt(id));
        }
    }
    
    // Get all descendant page IDs for a given page (uses pre-loaded hierarchy)
    function getAllDescendants(pageId) {
        var descendants = [];
        var queue = [pageId];
        
        while (queue.length > 0) {
            var currentId = queue.shift();
            var children = pageChildrenMap[currentId] || [];
            
            children.forEach(function(childId) {
                descendants.push(childId);
                queue.push(childId);
            });
        }
        
        return descendants;
    }
    
    // Check if a page is modified (has local changes) using pre-loaded status data
    function isPageModified(pageId) {
        if (typeof pwmcpPageStatuses !== 'undefined') {
            var status = pwmcpPageStatuses[pageId];
            return status === 'localDirty' || status === 'conflict';
        }
        // Fallback to DOM check
        var row = document.querySelector('tr[data-page-id="' + pageId + '"]');
        return row && row.getAttribute('data-modified') === '1';
    }
    
    // Check if a parent row is collapsed
    function isParentCollapsed(pageId) {
        var row = document.querySelector('tr[data-page-id="' + pageId + '"]');
        if (!row) return false;
        
        var toggle = row.querySelector('.pwmcp-toggle');
        if (!toggle) return false;
        
        return toggle.getAttribute('data-expanded') !== 'true';
    }
    
    // Update chevron dots for collapsed parents with selected children
    function updateChevronDots() {
        document.querySelectorAll('.pwmcp-toggle').forEach(function(toggle) {
            var pageId = parseInt(toggle.getAttribute('data-page-id'));
            var dot = toggle.querySelector('.pwmcp-toggle-dot');
            var isCollapsed = toggle.getAttribute('data-expanded') !== 'true';
            
            if (!dot) return;
            
            if (!isCollapsed) {
                dot.style.display = 'none';
                return;
            }
            
            // Check if any descendants are selected (uses pre-loaded hierarchy)
            var descendants = getAllDescendants(pageId);
            var hasSelectedDescendants = descendants.some(function(descId) {
                return selectionState.has(descId);
            });
            
            dot.style.display = hasSelectedDescendants ? 'block' : 'none';
        });
    }
    
    // Update parent checkbox states (checked/indeterminate/unchecked)
    function updateParentCheckboxes() {
        document.querySelectorAll('tr[data-page-id]').forEach(function(row) {
            var pageId = parseInt(row.getAttribute('data-page-id'));
            var checkbox = row.querySelector('.pwmcp-page-checkbox');
            
            // Use pre-loaded hierarchy to get all descendants
            var allDescendants = getAllDescendants(pageId);
            if (allDescendants.length === 0) {
                // No children - just set checked state based on selection
                checkbox.checked = selectionState.has(pageId);
                checkbox.indeterminate = false;
                return;
            }
            
            // Count selected descendants
            var selectedDescendants = 0;
            allDescendants.forEach(function(descId) {
                if (selectionState.has(descId)) {
                    selectedDescendants++;
                }
            });
            
            // Set checkbox state
            if (selectedDescendants === 0) {
                // No descendants selected - show this page's own selection state
                checkbox.checked = selectionState.has(pageId);
                checkbox.indeterminate = false;
            } else if (selectedDescendants === allDescendants.length && selectionState.has(pageId)) {
                // All descendants + this page selected
                checkbox.checked = true;
                checkbox.indeterminate = false;
            } else {
                // Partial selection
                checkbox.checked = false;
                checkbox.indeterminate = true;
            }
        });
    }
    
    // Update header select-all checkbox state
    function updateSelectAllCheckbox() {
        var selectAll = document.querySelector('.pwmcp-select-all');
        if (!selectAll) return;
        
        // Use pre-loaded hierarchy for total count (includes hidden pages)
        var totalPages = 0;
        if (typeof pwmcpPageParents !== 'undefined') {
            for (var id in pwmcpPageParents) {
                totalPages++;
            }
        }
        
        var selectedCount = selectionState.size;
        
        if (selectedCount === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (selectedCount >= totalPages) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else {
            selectAll.checked = false;
            selectAll.indeterminate = true;
        }
    }
    
    // Update row background and checkbox states for selected rows
    function updateRowStyles() {
        document.querySelectorAll('tr[data-page-id]').forEach(function(row) {
            var pageId = parseInt(row.getAttribute('data-page-id'));
            var checkbox = row.querySelector('.pwmcp-page-checkbox');
            var isSelected = selectionState.has(pageId);
            
            // Update row background
            if (isSelected) {
                row.classList.add('pwmcp-selected');
            } else {
                row.classList.remove('pwmcp-selected');
            }
            
            // Sync checkbox state (important for newly loaded rows)
            if (checkbox && !checkbox.indeterminate) {
                checkbox.checked = isSelected;
            }
        });
    }
    
    // Main selection update function
    function updateSelection() {
        updateSelectAllCheckbox();
        updateParentCheckboxes();
        updateRowStyles();
        updateChevronDots();
        updateSelectionToolbar();
    }
    
    // Handle individual checkbox clicks
    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('pwmcp-page-checkbox')) return;
        
        var checkbox = e.target;
        var pageId = parseInt(checkbox.getAttribute('data-page-id'));
        var row = checkbox.closest('tr');
        var toggle = row.querySelector('.pwmcp-toggle');
        var isExpanded = toggle && toggle.getAttribute('data-expanded') === 'true';
        
        // Check if this page has children using pre-loaded hierarchy
        var hasChildren = pageChildrenMap[pageId] && pageChildrenMap[pageId].length > 0;
        
        if (checkbox.checked) {
            // Add to selection
            selectionState.add(pageId);
            
            // If collapsed parent (has children but not expanded), select all descendants
            if (hasChildren && !isExpanded) {
                var descendants = getAllDescendants(pageId);
                descendants.forEach(function(descId) {
                    selectionState.add(descId);
                });
            }
        } else {
            // Remove from selection
            selectionState.delete(pageId);
            
            // If collapsed parent, deselect all descendants
            if (hasChildren && !isExpanded) {
                var descendants = getAllDescendants(pageId);
                descendants.forEach(function(descId) {
                    selectionState.delete(descId);
                });
            }
        }
        
        updateSelection();
    });
    
    // Handle select-all checkbox
    var selectAll = document.querySelector('.pwmcp-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            if (this.checked) {
                // Select ALL pages using pre-loaded hierarchy (includes hidden/collapsed)
                if (typeof pwmcpPageParents !== 'undefined') {
                    for (var id in pwmcpPageParents) {
                        selectionState.add(parseInt(id));
                    }
                }
            } else {
                // Clear all selections
                selectionState.clear();
            }
            
            updateSelection();
        });
    }
    
    // Initialize toolbar on page load
    updateSelection();
    
    // =====================================================================
    // EXPORT & IMPORT HANDLERS
    // =====================================================================
    
    var exportBtn = document.querySelector('.pwmcp-bulk-export');
    var importBtn = document.querySelector('.pwmcp-bulk-import');
    var treeForm = document.getElementById('pwmcp-tree-form');
    var hiddenBulkAction = treeForm ? treeForm.querySelector('.pwmcp-bulk-action-field') : null;
    
    // Pull button handler
    if (exportBtn && treeForm && hiddenBulkAction) {
        exportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (selectionState.size === 0) return;
            
            // Check all selected checkboxes in the form
            document.querySelectorAll('.pwmcp-page-checkbox').forEach(function(cb) {
                var pageId = parseInt(cb.getAttribute('data-page-id'));
                cb.checked = selectionState.has(pageId);
            });
            
            // Set action and submit
            hiddenBulkAction.value = 'pull';
            hiddenBulkAction.setAttribute('name', 'bulk_action');
            treeForm.submit();
        });
    }
    
    // Push button handler - shows confirmation modal
    if (importBtn) {
        importBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (selectionState.size === 0) return;
            
            // Count modified pages in selection using pre-loaded status data
            var modifiedCount = 0;
            var cleanCount = 0;
            
            selectionState.forEach(function(pageId) {
                if (isPageModified(pageId)) {
                    modifiedCount++;
                } else {
                    cleanCount++;
                }
            });
            
            if (modifiedCount === 0) return;
            
            // Show modal
            var modal = document.getElementById('pwmcp-import-modal');
            var message = modal.querySelector('.pwmcp-modal-message');
            var confirmBtn = modal.querySelector('.pwmcp-modal-confirm');
            
            var messageText = 'You are about to push ' + modifiedCount + ' page' + (modifiedCount === 1 ? '' : 's') + ' from local YAML files, overwriting their current content in ProcessWire.';
            if (cleanCount > 0) {
                messageText += ' ' + cleanCount + ' page' + (cleanCount === 1 ? '' : 's') + ' with no local changes will be skipped.';
            }
            
            message.textContent = messageText;
            confirmBtn.textContent = 'Apply ' + modifiedCount + ' page' + (modifiedCount === 1 ? '' : 's');
            
            modal.style.display = 'block';
        });
    }
    
    // Modal handlers
    var modal = document.getElementById('pwmcp-import-modal');
    if (modal) {
        var closeBtn = modal.querySelector('.pwmcp-modal-close');
        var cancelBtn = modal.querySelector('.pwmcp-modal-cancel');
        var confirmBtn = modal.querySelector('.pwmcp-modal-confirm');
        var backdrop = modal.querySelector('.pwmcp-modal-backdrop');
        
        // Close modal
        function closeModal() {
            modal.style.display = 'none';
        }
        
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        if (backdrop) backdrop.addEventListener('click', closeModal);
        
        // Confirm import
        if (confirmBtn && treeForm && hiddenBulkAction) {
            confirmBtn.addEventListener('click', function() {
                // Check all selected checkboxes in the form
                document.querySelectorAll('.pwmcp-page-checkbox').forEach(function(cb) {
                    var pageId = parseInt(cb.getAttribute('data-page-id'));
                    cb.checked = selectionState.has(pageId);
                });
                
                // Set action and submit
                hiddenBulkAction.value = 'push';
                hiddenBulkAction.setAttribute('name', 'bulk_action');
                treeForm.submit();
            });
        }
    }
    
    // Toggle expand/collapse for tree nodes
    document.addEventListener('click', function(e) {
        var toggle = e.target.closest('.pwmcp-toggle');
        if (!toggle) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        var pageId = toggle.getAttribute('data-page-id');
        var isExpanded = toggle.getAttribute('data-expanded') === 'true';
        var currentRow = toggle.closest('tr');
        var currentDepth = parseInt(currentRow.getAttribute('data-depth'), 10);
        
        if (isExpanded) {
            // Collapse: remove all child rows
            toggle.setAttribute('data-expanded', 'false');
            var nextRow = currentRow.nextElementSibling;
            while (nextRow) {
                var nextDepth = parseInt(nextRow.getAttribute('data-depth'), 10);
                if (nextDepth <= currentDepth) break;
                var toRemove = nextRow;
                nextRow = nextRow.nextElementSibling;
                toRemove.remove();
            }
            
            // Update selection state after collapse
            updateSelection();
        } else {
            // Expand: load children via AJAX
            toggle.setAttribute('data-expanded', 'true');
            
            // Add loading spinner after page title
            var titleCell = currentRow.querySelector('.pwmcp-title-cell');
            var spinner = document.createElement('span');
            spinner.className = 'pwmcp-spinner';
            titleCell.appendChild(spinner);
            
            fetch(childrenUrl + '?id=' + pageId + '&depth=' + currentDepth)
                .then(function(response) { return response.text(); })
                .then(function(html) {
                    spinner.remove();
                    if (html.trim()) {
                        currentRow.insertAdjacentHTML('afterend', html);
                        // Apply badges from pre-loaded status lookup
                        applyBadgesToNewRows(currentRow);
                        // Initialize disabled state handlers for newly loaded content
                        initializeActionButtons();
                        
                        // Update selection state after expand
                        updateSelection();
                    }
                })
                .catch(function(err) {
                    spinner.remove();
                    console.error('Failed to load children:', err);
                });
        }
    });
    
    // Expand All toggle
    var expandAllCheckbox = document.getElementById('pwmcp-expand-all');
    if (expandAllCheckbox) {
        expandAllCheckbox.addEventListener('change', function() {
            if (this.checked) {
                expandAllNodes();
            } else {
                collapseAllNodes();
            }
        });
    }
    
    // Recursively expand all nodes
    function expandAllNodes() {
        var toggles = document.querySelectorAll('.pwmcp-toggle[data-expanded="false"]');
        if (toggles.length === 0) return;
        
        var pendingLoads = 0;
        
        toggles.forEach(function(toggle) {
            var pageId = toggle.getAttribute('data-page-id');
            var currentRow = toggle.closest('tr');
            var currentDepth = parseInt(currentRow.getAttribute('data-depth'), 10);
            
            toggle.setAttribute('data-expanded', 'true');
            pendingLoads++;
            
            fetch(childrenUrl + '?id=' + pageId + '&depth=' + currentDepth)
                .then(function(response) { return response.text(); })
                .then(function(html) {
                    if (html.trim()) {
                        currentRow.insertAdjacentHTML('afterend', html);
                        // Apply badges from pre-loaded status lookup
                        applyBadgesToNewRows(currentRow);
                        // Initialize disabled state handlers for newly loaded content
                        initializeActionButtons();
                    }
                    pendingLoads--;
                    // After this batch loads, check if there are more to expand
                    if (pendingLoads === 0) {
                        setTimeout(function() {
                            expandAllNodes();
                            updateSelection();
                        }, 50);
                    }
                })
                .catch(function(err) {
                    pendingLoads--;
                    console.error('Failed to load children:', err);
                });
        });
    }
    
    // Collapse all expanded nodes
    function collapseAllNodes() {
        // Remove all rows with depth > 0
        document.querySelectorAll('tr[data-depth]').forEach(function(row) {
            var depth = parseInt(row.getAttribute('data-depth'), 10);
            if (depth > 0) {
                row.remove();
            }
        });
        // Reset all toggles to collapsed state
        document.querySelectorAll('.pwmcp-toggle[data-expanded="true"]').forEach(function(toggle) {
            toggle.setAttribute('data-expanded', 'false');
        });
        
        // Update selection state after collapse
        updateSelection();
    }
    
    
    // Initialize action buttons - disabled state handling
    function initializeActionButtons() {
        document.querySelectorAll('.pwmcp-action').forEach(function(action) {
            var isDisabled = action.classList.contains('pwmcp-action-disabled');
            
            // Force appropriate cursor based on state
            if (!isDisabled) {
                action.style.cursor = 'pointer';
                var children = action.querySelectorAll('*');
                children.forEach(function(child) {
                    child.style.cursor = 'pointer';
                });
            }
            
            // Prevent clicks on disabled actions
            if (isDisabled && !action.hasAttribute('data-disabled-handler')) {
                action.setAttribute('data-disabled-handler', 'true');
                action.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });
            }
        });
    }
    
    // Initialize on page load
    initializeActionButtons();

    // Move header buttons to top-right next to H1
    var headerBtns = document.querySelector('.pwmcp-header-buttons');
    var head = document.getElementById('pw-content-head');
    var h1 = head ? head.querySelector('h1') : null;
    if (headerBtns && h1) {
        h1.style.display = 'inline-block';
        head.appendChild(headerBtns);
    }
});
</script>
HTML;
    }
    
    /**
     * Handle bulk actions from checkbox selection
     */
    protected function handleBulkAction(string $action, $pageIds): string {
        $syncManager = $this->getSyncManager();
        
        if (!is_array($pageIds)) {
            $pageIds = [$pageIds];
        }
        $pageIds = array_map('intval', $pageIds);
        $pageIds = array_filter($pageIds);
        
        if (empty($pageIds)) {
            $this->error($this->_('No pages selected'));
            $this->wire('session')->redirect($this->wire('page')->url);
            return '';
        }
        
        if ($action === 'pull') {
            $pulled = 0;
            foreach ($pageIds as $pageId) {
                $result = $syncManager->pullPage($pageId);
                if (isset($result['success']) && $result['success']) {
                    $pulled++;
                }
            }
            $this->message(sprintf($this->_('Exported %d pages to file'), $pulled));
        } elseif ($action === 'push') {
            $pushed = 0;
            foreach ($pageIds as $pageId) {
                $page = $this->wire('pages')->get($pageId);
                if (!$page->id) continue;
                $localPath = $this->getLocalPathForPage($page);
                $result = $syncManager->pushPage($localPath, false);
                if (isset($result['success']) && $result['success']) {
                    $pushed++;
                }
            }
            $this->message(sprintf($this->_('Applied %d pages to Wire'), $pushed));
        }
        
        $this->wire('session')->redirect($this->wire('page')->url);
        return '';
    }

    /**
     * Get status badge HTML
     * 
     * @param string $status Status name
     * @return string Badge HTML
     */
    protected function getStatusBadge(string $status): string {
        $labels = [
            'clean' => ['Clean', 'uk-label-success'],
            'localDirty' => ['File Newer', 'uk-label-warning'],
            'remoteChanged' => ['Wire Newer', 'uk-label-primary'],
            'conflict' => ['Conflict', 'uk-label-danger'],
            'orphan' => ['Orphan', ''],
            'notPulled' => ['Untracked', 'uk-label-muted'],
        ];
        
        $label = $labels[$status] ?? ['Unknown', ''];
        $class = $label[1] ? "class='uk-label pwmcp-badge {$label[1]}'" : "class='uk-label pwmcp-badge'";
        
        return "<span {$class}>{$label[0]}</span>";
    }

    /**
     * Get row action buttons
     * 
     * @param Page $page The page
     * @param string $status Page status
     * @return string Actions HTML
     */
    protected function getRowActions(Page $page, string $status): string {
        $actions = [];
        $baseUrl = $this->wire('page')->url;
        $isUntracked = ($status === 'notPulled');
        
        // Wire to File button - always active
        $actions[] = "<a href='{$baseUrl}pull/?id={$page->id}' class='pwmcp-action' uk-tooltip='title: Wire to File'>" .
            $this->lucideIcon('file-input') . "</a>";
        
        // File to Wire button - disabled if no local file yet
        $pushClass = $isUntracked ? 'pwmcp-action pwmcp-action-disabled' : 'pwmcp-action';
        $pushHref = $isUntracked ? '#' : "{$baseUrl}push/?id={$page->id}";
        $pushTooltip = $isUntracked ? 'No local file yet' : 'File to Wire';
        $actions[] = "<a href='{$pushHref}' class='{$pushClass}' uk-tooltip='title: {$pushTooltip}'>" .
            $this->lucideIcon('file-output') . "</a>";
        
        // View YAML button - disabled if no local file yet
        $viewClass = $isUntracked ? 'pwmcp-action pwmcp-action-disabled' : 'pw-modal pwmcp-action';
        $viewHref = $isUntracked ? '#' : "{$baseUrl}view-yaml/?id={$page->id}";
        $viewTooltip = $isUntracked ? 'No local file yet' : 'View YAML';
        $actions[] = "<a href='{$viewHref}' class='{$viewClass}' uk-tooltip='title: {$viewTooltip}'>" .
            $this->lucideIcon('file-braces') . "</a>";
        
        return implode(' ', $actions);
    }

    /**
     * Pull a single page from PW to YAML file
     */
    public function ___executePull(): string {
        $input = $this->wire('input');
        $session = $this->wire('session');
        $pageId = (int) $input->get('id');
        
        // Get the base URL for this Process page
        $baseUrl = $this->wire('page')->url;
        
        if (!$pageId) {
            $this->error($this->_('No page ID specified'));
            $session->redirect($baseUrl);
            return '';
        }
        
        $page = $this->wire('pages')->get($pageId);
        if (!$page || !$page->id) {
            $this->error($this->_('Page not found'));
            $session->redirect($baseUrl);
            return '';
        }
        
        try {
            $syncManager = $this->getSyncManager();
            $result = $syncManager->pullPage($pageId);
            
            if (isset($result['success']) && $result['success']) {
                $this->message(sprintf(
                    $this->_('Exported "%s" to %s'),
                    $page->title,
                    $result['localPath'] ?? ''
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Failed to export page'));
            }
        } catch (\Exception $e) {
            $this->error($this->_('Error: ') . $e->getMessage());
        }
        
        $session->redirect($baseUrl);
        return '';
    }

    /**
     * Push a single page from YAML file to PW with dry-run preview
     */
    public function ___executePush(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        // Check both GET (initial load) and POST (form submission) for page ID
        $pageId = (int) $input->get('id') ?: (int) $input->post('id');
        $confirmed = $input->post('submit_confirm') ? true : false;
        $forceConfirmed = $input->post('submit_force') ? true : false;
        
        if (!$pageId) {
            $this->error($this->_('No page ID specified'));
            $this->wire('session')->redirect($this->wire('page')->url);
            return '';
        }
        
        $page = $this->wire('pages')->get($pageId);
        if (!$page || !$page->id) {
            $this->error($this->_('Page not found'));
            $this->wire('session')->redirect($this->wire('page')->url);
            return '';
        }
        
        $syncManager = $this->getSyncManager();
        $workspacePath = $this->getLocalPathForPage($page);
        
        // AJAX: refresh one field's preview (re-read from disk)
        if ($input->post('refresh_field') && $input->post('field_key')) {
            header('Content-Type: application/json');
            echo $this->refreshPushFieldPreview($pageId, $input->post('field_key'));
            return '';
        }
        
        // Check if force confirmed (overwrite conflict)
        if ($forceConfirmed) {
            // Force push - ignore conflict
            $result = $syncManager->pushPage($workspacePath, false, true); // dryRun = false, force = true
            
            if (isset($result['success']) && $result['success']) {
                $this->message(sprintf(
                    $this->_('Force applied changes to "%s"'),
                    $page->title
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Failed to apply changes'));
            }
            
            $this->wire('session')->redirect($this->wire('page')->url);
            return '';
        }
        
        // Check if confirmed
        if ($confirmed) {
            $excludedRaw = $input->post('pwmcp_excluded');
            $excludedKeys = is_string($excludedRaw) && $excludedRaw !== ''
                ? array_map('trim', explode(',', $excludedRaw))
                : [];
            // Actually push (excluding any fields the user unchecked)
            $result = $syncManager->pushPage($workspacePath, false, false, $excludedKeys);
            
            if (isset($result['success']) && $result['success']) {
                $this->message(sprintf(
                    $this->_('Applied changes to "%s"'),
                    $page->title
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Failed to apply changes'));
            }
            
            $this->wire('session')->redirect($this->wire('page')->url);
            return '';
        }
        
        // Dry-run preview
        $result = $syncManager->pushPage($workspacePath, true); // dryRun = true
        
        $this->headline(sprintf($this->_('Preview: %s'), $page->title));
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        $form->attr('action', $this->wire('page')->url . 'push/?id=' . $pageId);
        
        // Include preview styles
        $cssField = $modules->get('InputfieldMarkup');
        $cssField->addClass('InputfieldHeaderHidden');
        $cssField->value = '<style>
.pwmcp-preview-input,
.pwmcp-preview-textarea {
    width: 100%;
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: #354b60;
    background: #f5f5f5;
    border: 1px solid #cdcdcd;
    border-radius: 2px;
    padding: 8px 10px;
    cursor: default;
}
.pwmcp-preview-input:disabled,
.pwmcp-preview-textarea:disabled {
    opacity: 0.85;
}
.pwmcp-preview-textarea {
    resize: vertical;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: Consolas, monaco, monospace;
    font-size: 13px;
    tab-size: 2;
}
.pwmcp-field-name {
    font-size: 13px;
    color: #999;
    margin-top: 10px;
}
.pwmcp-field-actions { float: right; display: inline-flex; align-items: center; gap: 0; margin-right: 8px; height: 24px; }
.pwmcp-field-actions button { position: relative; background: none; border: none; padding: 0 6px; cursor: pointer; color: #999; line-height: 1; display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; height: 24px; }
.pwmcp-field-actions button svg { display: block; }
.pwmcp-field-actions button:hover { color: #354b60; }
.pwmcp-field-actions button.pwmcp-toggle-off svg { color: #c33; }
.pwmcp-field-actions::after { content: ""; width: 1px; height: 16px; background: #ddd; margin-left: 8px; margin-right: 4px; display: inline-block; vertical-align: middle; align-self: center; }
.pwmcp-excluded > .InputfieldHeader { text-decoration: line-through; text-decoration-color: #e83561; color: #e83561 !important; opacity: 0.75; font-weight: normal !important; }
.pwmcp-excluded > .InputfieldHeader .pwmcp-field-actions { text-decoration: none; }
.pwmcp-header-actions { float: right; margin-top: 6px; display: inline-flex; gap: 8px; align-items: center; }
.InputfieldHeaderHidden { display: none !important; }
</style>';
        $form->add($cssField);
        
        // Clean — no changes to apply
        if (isset($result['success']) && $result['success'] && empty($result['changes']) && !isset($result['dryRun'])) {
            $preview = $modules->get('InputfieldMarkup');
            $preview->label = $this->_('Status');
            $preview->value = '<p>' . $this->_('Clean — no changes to apply.') . '</p>';
            $form->add($preview);
            
            // Back button only — nothing to apply
            $btn = $modules->get('InputfieldButton');
            $btn->value = $this->_('Back');
            $btn->href = $this->wire('page')->url;
            $btn->icon = 'arrow-left';
            $form->add($btn);
            
            return $form->render();
        }
        
        // Show preview using ProcessWire's native Inputfield structure
        if (isset($result['dryRun']) && $result['dryRun']) {
            if (!empty($result['changes'])) {
                // Build preview wrapper with native PW field structure
                $changesWrapper = $this->buildChangesPreview($result['changes'], $pageId);
                
                // Add wrapper to form (each field appears as its own collapsible)
                foreach ($changesWrapper as $inputfield) {
                    $form->add($inputfield);
                }
                // Hidden input for excluded field keys (comma-separated)
                $hiddenExcluded = $modules->get('InputfieldHidden');
                $hiddenExcluded->attr('name', 'pwmcp_excluded');
                $hiddenExcluded->attr('id', 'pwmcp_excluded');
                $hiddenExcluded->attr('value', '');
                $form->add($hiddenExcluded);
                // Script: add Refresh + Don't import icons to each field header and handle clicks
                $pushActionsJs = $modules->get('InputfieldMarkup');
                $pushActionsJs->addClass('InputfieldHeaderHidden');
                $refreshLabel = $this->_('Refresh contents');
                $excludeLabel = $this->_('Exclude from import');
                $includeLabel = $this->_('Include in import');
                $pushActionsJs->value = '<script>
document.addEventListener("DOMContentLoaded", function() {
var form = document.querySelector("form[action*=\"push/\"]");
if (!form) return;
var excludedInput = document.getElementById("pwmcp_excluded");
if (!excludedInput) return;
var pushUrl = form.getAttribute("action");
var idInput = form.querySelector("input[name=\'id\']");
var pageId = idInput ? idInput.value : "";
var fields = form.querySelectorAll("[data-pwmcp-field-key]");
function getExcluded() { var v = excludedInput.value; return v ? v.split(",") : []; }
function setExcluded(arr) { excludedInput.value = arr.join(","); }
fields.forEach(function(wrap) {
    var key = wrap.getAttribute("data-pwmcp-field-key");
    if (!key) return;
    var header = wrap.querySelector(".InputfieldHeader");
    if (!header) return;
    var actions = document.createElement("span");
    actions.className = "pwmcp-field-actions";
    var refreshBtn = document.createElement("button");
    refreshBtn.type = "button";
    refreshBtn.innerHTML = \'<svg class="pwmcp-lucide" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>\';
    refreshBtn.setAttribute("uk-tooltip", "title: ' . addslashes($refreshLabel) . '");
    var toggleOnSvg = \'<svg class="pwmcp-lucide" xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="6"/><circle cx="16" cy="12" r="2"/></svg>\';
    var toggleOffSvg = \'<svg class="pwmcp-lucide" xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="6"/><circle cx="8" cy="12" r="2"/></svg>\';
    var excludeBtn = document.createElement("button");
    excludeBtn.type = "button";
    excludeBtn.innerHTML = toggleOnSvg;
    excludeBtn.setAttribute("uk-tooltip", "title: ' . addslashes($excludeLabel) . '");
    excludeBtn.setAttribute("data-pwmcp-exclude-key", key);
    refreshBtn.addEventListener("click", function(e) {
        e.preventDefault(); e.stopPropagation();
        var fd = new FormData();
        fd.append("id", pageId);
        fd.append("refresh_field", "1");
        fd.append("field_key", key);
        refreshBtn.disabled = true;
        fetch(pushUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var content = wrap.querySelector(".InputfieldContent");
                if (content && data && data.html) content.innerHTML = data.html;
            })
            .catch(function() {})
            .finally(function() { refreshBtn.disabled = false; });
    });
    excludeBtn.addEventListener("click", function(e) {
        e.preventDefault(); e.stopPropagation();
        var arr = getExcluded();
        var idx = arr.indexOf(key);
        if (idx >= 0) {
            arr.splice(idx, 1);
            wrap.classList.remove("pwmcp-excluded");
            excludeBtn.classList.remove("pwmcp-toggle-off");
            excludeBtn.innerHTML = toggleOnSvg;
            excludeBtn.setAttribute("uk-tooltip", "title: ' . addslashes($excludeLabel) . '");
        } else {
            arr.push(key);
            wrap.classList.add("pwmcp-excluded");
            excludeBtn.classList.add("pwmcp-toggle-off");
            excludeBtn.innerHTML = toggleOffSvg;
            excludeBtn.setAttribute("uk-tooltip", "title: ' . addslashes($includeLabel) . '");
        }
        setExcluded(arr);
    });
    actions.appendChild(refreshBtn);
    actions.appendChild(excludeBtn);
    var toggle = header.querySelector(".toggle-icon") || header.querySelector("i:last-child");
    if (toggle && toggle.nextSibling) { header.insertBefore(actions, toggle.nextSibling); }
    else { header.appendChild(actions); }
});
// Clone form buttons to header next to H1
var head = document.getElementById("pw-content-head");
var h1 = head ? head.querySelector("h1") : null;
if (h1 && form) {
    var originalConfirm = form.querySelector("button[name=submit_confirm], input[type=submit][name=submit_confirm]");
    var originalCancel = form.querySelector("a.ui-button[href], button.ui-priority-secondary");
    if (originalConfirm || originalCancel) {
        var headerBtns = document.createElement("div");
        headerBtns.className = "pwmcp-header-actions";
        if (originalConfirm) {
            var confirmClone = originalConfirm.cloneNode(true);
            confirmClone.removeAttribute("name");
            confirmClone.addEventListener("click", function(e) {
                e.preventDefault();
                originalConfirm.click();
            });
            headerBtns.appendChild(confirmClone);
        }
        if (originalCancel) {
            var cancelClone = originalCancel.cloneNode(true);
            headerBtns.appendChild(cancelClone);
        }
        h1.style.display = "inline-block";
        head.appendChild(headerBtns);
    }
}
});
</script>';
                $form->add($pushActionsJs);
            }
        } elseif (isset($result['conflict']) && $result['conflict']) {
            // Conflict warning
            $warning = $modules->get('InputfieldMarkup');
            $warning->value = '<div class="NoticeWarning" style="margin-bottom: 20px;">';
            $warning->value .= $this->_('This page has been modified in ProcessWire since you last pulled it.');
            $warning->value .= '</div>';
            $form->add($warning);
            
            // Show remote changes (what changed in ProcessWire)
            if (!empty($result['remoteChanges'])) {
                $remoteFieldset = $modules->get('InputfieldFieldset');
                $remoteFieldset->label = $this->_('Changed in ProcessWire (will be overwritten)');
                $remoteFieldset->collapsed = Inputfield::collapsedNo;
                
                $remoteWrapper = $this->buildChangesPreview($result['remoteChanges'], $pageId);
                foreach ($remoteWrapper as $inputfield) {
                    $remoteFieldset->add($inputfield);
                }
                $form->add($remoteFieldset);
            }
            
            // Show local changes (what user wants to push)
            if (!empty($result['localChanges'])) {
                $localFieldset = $modules->get('InputfieldFieldset');
                $localFieldset->label = $this->_('Your Local Changes');
                $localFieldset->collapsed = Inputfield::collapsedNo;
                
                $localWrapper = $this->buildChangesPreview($result['localChanges'], $pageId);
                foreach ($localWrapper as $inputfield) {
                    $localFieldset->add($inputfield);
                }
                $form->add($localFieldset);
            }
            
            // Add explanation text field
            $explanation = $modules->get('InputfieldMarkup');
            $explanation->value = '<p style="color: #666;">' . 
                $this->_('Re-export to get the latest version, or force apply to overwrite with your local changes.') . 
                '</p>';
            $form->add($explanation);
            
            // Hidden page ID
            $f = $modules->get('InputfieldHidden');
            $f->attr('name', 'id');
            $f->attr('value', $pageId);
            $form->add($f);
            
            // Re-export button (link)
            $btn = $modules->get('InputfieldButton');
            $btn->value = $this->_('Re-export from Wire');
            $btn->href = $this->wire('page')->url . 'pull/?id=' . $pageId;
            $btn->icon = 'refresh';
            $form->add($btn);
            
            // Force Push button
            $f = $modules->get('InputfieldSubmit');
            $f->attr('name', 'submit_force');
            $f->value = $this->_('Force Apply (Overwrite)');
            $f->icon = 'bolt';
            $f->setSecondary(true);
            $form->add($f);
            
            // Cancel button
            $btn = $modules->get('InputfieldButton');
            $btn->value = $this->_('Cancel');
            $btn->href = $this->wire('page')->url;
            $btn->setSecondary(true);
            $form->add($btn);
            
            return $form->render();
            
        } elseif (isset($result['error'])) {
            $errorField = $modules->get('InputfieldMarkup');
            $errorField->value = '<p class="uk-alert uk-alert-danger">' . 
                htmlspecialchars($result['error']) . '</p>';
            $form->add($errorField);
        }
        
        // Hidden page ID
        $f = $modules->get('InputfieldHidden');
        $f->attr('name', 'id');
        $f->attr('value', $pageId);
        $form->add($f);
        
        // Confirm button
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'submit_confirm');
        $f->value = $this->_('Apply Changes');
        $f->icon = 'check';
        $form->add($f);
        
        // Cancel button
        $btn = $modules->get('InputfieldButton');
        $btn->value = $this->_('Cancel');
        $btn->href = $this->wire('page')->url;
        $btn->setSecondary(true);
        $form->add($btn);
        
        return $form->render();
    }

    /**
     * View YAML content (read-only)
     */
    public function ___executeViewYaml(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $pageId = (int) $input->get('id');
        
        $this->headline($this->_('View YAML'));
        
        if (!$pageId) {
            return '<p>' . $this->_('No page ID specified') . '</p>';
        }
        
        $page = $this->wire('pages')->get($pageId);
        if (!$page || !$page->id) {
            return '<p>' . $this->_('Page not found') . '</p>';
        }
        
        $workspacePath = $this->getLocalPathForPage($page);
        $yamlPath = $workspacePath . 'page.yaml';
        
        if (!file_exists($yamlPath)) {
            return '<p>' . $this->_('YAML file not found. Pull the page first.') . '</p>';
        }
        
        $yamlContent = file_get_contents($yamlPath);
        
        $out = '<div class="pwmcp-yaml-viewer">';
        $out .= '<p><strong>' . htmlspecialchars($page->title) . '</strong> <span class="detail">' . $page->path . '</span></p>';
        $out .= '<pre style="background:#f5f5f5; padding:1em; overflow:auto; max-height:500px;">' . 
            htmlspecialchars($yamlContent) . '</pre>';
        
        // Back button - use the module's page URL for proper navigation
        $dashboardUrl = $this->wire('page')->url;
        $out .= '<p style="margin-top:1em;">';
        $out .= '<a href="' . $dashboardUrl . '" class="ui-button ui-state-default">';
        $out .= '<span class="ui-button-text">' . $this->lucideIcon('arrow-left', 14) . ' ' . $this->_('Dashboard') . '</span>';
        $out .= '</a>';
        $out .= '</p>';
        
        $out .= '</div>';
        
        return $out;
    }

    /**
     * Bulk Pull All (with confirmation)
     */
    public function ___executePullAll(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $session = $this->wire('session');
        
        $this->headline($this->_('Bulk Wire to File'));
        
        // Get filter from session
        $rootPath = $session->get('pwmcp_root_path') ?: '';
        $templateFilter = $session->get('pwmcp_template') ?: '';
        
        if (!$rootPath && !$templateFilter) {
            return '<p class="uk-alert uk-alert-warning">' . 
                $this->_('Please set a filter first (root path or template) before bulk operations.') . 
                '</p><p><a href="./">Back to Dashboard</a></p>';
        }
        
        // Build selector from page ID
        $selector = '';
        $displayPath = '';
        if ($rootPath) {
            $rootPageId = (int) $rootPath;
            $rootPage = $this->wire('pages')->get($rootPageId);
            if ($rootPage && $rootPage->id) {
                // Pass the page path - resolvePagesFromSelector handles paths specially
                // and will include the parent + all children
                $selector = $rootPage->path;
                $displayPath = $rootPage->path;
            }
        } elseif ($templateFilter) {
            $selector = "template=$templateFilter";
            $displayPath = "template=$templateFilter";
        }
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        
        $info = $modules->get('InputfieldMarkup');
        $info->label = $this->_('Bulk Preview');
        $info->value = '<p>' . sprintf(
            $this->_('This will pull all pages under: %s'),
            '<strong>' . htmlspecialchars($displayPath ?: $selector) . '</strong>'
        ) . '</p>';
        $info->value .= '<p class="notes">' . 
            $this->_('Existing YAML files will be overwritten with current ProcessWire content.') . 
            '</p>';
        $form->add($info);
        
        if ($input->post('confirm_pull')) {
            // Execute bulk pull
            $syncManager = $this->getSyncManager();
            $result = $syncManager->pullPages($selector);
            
            if (isset($result['success']) && $result['success']) {
                $this->message(sprintf(
                    $this->_('Exported %d pages to file'),
                    $result['pulled'] ?? 0
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Bulk pull failed'));
            }
            
            $session->redirect($this->wire('page')->url);
            return '';
        }
        
        // Confirm button
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'confirm_pull');
        $f->value = $this->_('Confirm Pull All');
        $f->icon = 'download';
        $form->add($f);
        
        // Cancel
        $btn = $modules->get('InputfieldButton');
        $btn->value = $this->_('Cancel');
        $btn->href = './';
        $btn->setSecondary(true);
        $form->add($btn);
        
        return $form->render();
    }

    /**
     * Bulk Push All (with dry-run preview)
     */
    public function ___executePushAll(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $session = $this->wire('session');
        
        $this->headline($this->_('Bulk File to Wire'));
        
        // Get filter from session to scope the push
        $rootPath = $session->get('pwmcp_root_path') ?: '';
        
        // Determine the directory to push from
        $pushDir = $this->getWorkspaceRoot();
        $displayPath = '/';
        
        if ($rootPath) {
            $rootPageId = (int) $rootPath;
            $rootPage = $this->wire('pages')->get($rootPageId);
            if ($rootPage && $rootPage->id) {
                // Scope to just this path in the workspace
                $pushDir = $this->getWorkspaceRoot() . ltrim($rootPage->path, '/');
                $displayPath = $rootPage->path;
            }
        }
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        
        $syncManager = $this->getSyncManager();
        
        if ($input->post('confirm_push')) {
            // Execute bulk push - scoped to filter
            $result = $syncManager->pushPages($pushDir, false); // dryRun = false
            
            if (isset($result['success']) && $result['success']) {
                $this->message(sprintf(
                    $this->_('Applied %d pages to Wire'),
                    $result['pushed'] ?? 0
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Bulk push failed'));
            }
            
            $session->redirect($this->wire('page')->url);
            return '';
        }
        
        // Dry-run preview - scoped to filter
        $result = $syncManager->pushPages($pushDir, true); // dryRun = true
        
        $info = $modules->get('InputfieldMarkup');
        $info->label = $this->_('Bulk Preview');
        
        // Show scope
        $info->value = '<p class="notes">' . sprintf(
            $this->_('Scope: %s'),
            '<strong>' . htmlspecialchars($displayPath) . '</strong>'
        ) . '</p>';
        
        if (isset($result['pages']) && !empty($result['pages'])) {
            $info->value = '<p>' . sprintf(
                $this->_('%d pages have local changes to push:'),
                count($result['pages'])
            ) . '</p><ul>';
            foreach ($result['pages'] as $pageInfo) {
                $info->value .= '<li>' . htmlspecialchars($pageInfo['path'] ?? $pageInfo['localPath'] ?? 'Unknown') . '</li>';
            }
            $info->value .= '</ul>';
        } else {
            $info->value = '<p>' . $this->_('No local changes to push.') . '</p>';
        }
        $form->add($info);
        
        // Confirm button
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'confirm_push');
        $f->value = $this->_('Apply All Changes');
        $f->icon = 'check';
        $form->add($f);
        
        // Cancel
        $btn = $modules->get('InputfieldButton');
        $btn->value = $this->_('Cancel');
        $btn->href = './';
        $btn->setSecondary(true);
        $form->add($btn);
        
        return $form->render();
    }

    /**
     * Reconcile - fix path drift and orphans
     */
    public function ___executeReconcile(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        
        $this->headline($this->_('Reconcile Sync'));
        
        $syncManager = $this->getSyncManager();
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        
        if ($input->post('confirm_reconcile')) {
            // Execute reconcile
            $result = $syncManager->reconcile(null, false); // dryRun = false
            
            if (isset($result['success']) && $result['success']) {
                $this->message($this->_('Reconciliation complete'));
                if (!empty($result['actions'])) {
                    foreach ($result['actions'] as $action) {
                        $this->message($action);
                    }
                }
            } else {
                $this->error($result['error'] ?? $this->_('Reconciliation failed'));
            }
            
            $this->wire('session')->redirect($this->wire('page')->url);
            return '';
        }
        
        // Dry-run preview
        $result = $syncManager->reconcile(null, true); // dryRun = true
        
        $info = $modules->get('InputfieldMarkup');
        $info->label = $this->_('Reconcile Preview');
        
        // Explanation
        $info->value = '<p class="notes" style="margin-bottom:1em;">' . 
            $this->_('Reconcile fixes structural sync issues (moved/deleted pages). To apply content changes, use Push instead.') . 
            '</p>';
        
        $info->value .= '<h4>' . $this->_('Summary') . '</h4>';
        $info->value .= '<table class="AdminDataTable" style="width:auto;">';
        $info->value .= '<tr><td><strong>' . sprintf('%d', $result['summary']['clean'] ?? 0) . '</strong></td>';
        $info->value .= '<td>' . $this->_('Clean') . '</td>';
        $info->value .= '<td class="notes">' . $this->_('Local folders correctly match their ProcessWire pages') . '</td></tr>';
        $info->value .= '<tr><td><strong>' . sprintf('%d', $result['summary']['pathDrift'] ?? 0) . '</strong></td>';
        $info->value .= '<td>' . $this->_('Path Drift') . '</td>';
        $info->value .= '<td class="notes">' . $this->_('Page was moved/renamed in PW - local folder will be relocated') . '</td></tr>';
        $info->value .= '<tr><td><strong>' . sprintf('%d', $result['summary']['orphans'] ?? 0) . '</strong></td>';
        $info->value .= '<td>' . $this->_('Orphans') . '</td>';
        $info->value .= '<td class="notes">' . $this->_('Local folder exists but page was deleted in PW') . '</td></tr>';
        $info->value .= '<tr><td><strong>' . sprintf('%d', $result['summary']['newPages'] ?? 0) . '</strong></td>';
        $info->value .= '<td>' . $this->_('New Pages') . '</td>';
        $info->value .= '<td class="notes">' . $this->_('Pages in PW that haven\'t been pulled yet') . '</td></tr>';
        $info->value .= '</table>';
        
        if (!empty($result['pathDrift'])) {
            $info->value .= '<h4>' . $this->_('Path Drift (will be fixed)') . '</h4><ul>';
            foreach ($result['pathDrift'] as $drift) {
                $info->value .= '<li>' . htmlspecialchars($drift['oldPath'] . ' → ' . $drift['newPath']) . '</li>';
            }
            $info->value .= '</ul>';
        }
        
        if (!empty($result['orphans'])) {
            $info->value .= '<h4>' . $this->_('Orphans (will be marked)') . '</h4><ul>';
            foreach ($result['orphans'] as $orphan) {
                $info->value .= '<li>' . htmlspecialchars($orphan['localPath']) . '</li>';
            }
            $info->value .= '</ul>';
        }
        
        $form->add($info);
        
        // Confirm button
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'confirm_reconcile');
        $f->value = $this->_('Apply Reconciliation');
        $f->icon = 'check';
        $form->add($f);
        
        // Cancel
        $btn = $modules->get('InputfieldButton');
        $btn->value = $this->_('Cancel');
        $btn->href = './';
        $btn->setSecondary(true);
        $form->add($btn);
        
        return $form->render();
    }

    /**
     * Install the module
     */
    public function ___install() {
        parent::___install();
        
        // Create the workspace directory
        $workspaceRoot = $this->getWorkspaceRoot();
        if (!is_dir($workspaceRoot)) {
            wireMkdir($workspaceRoot);
        }
    }
}
