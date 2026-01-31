<?php namespace ProcessWire;

/**
 * PW-MCP Admin - Sync Status Dashboard
 * 
 * Provides a visual dashboard in ProcessWire admin for managing
 * content sync operations (pull/push pages to YAML files).
 * 
 * @package     PwMcp
 * @subpackage  Admin
 * @author      Peter Knight
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
            'title' => 'MCP Sync',
            'summary' => 'Manage content sync between ProcessWire and YAML files',
            'version' => '1.0.0',
            'author' => 'Peter Knight',
            'icon' => 'refresh',
            'requires' => ['ProcessWire>=3.0.165', 'PwMcp'],
            'permission' => 'pw-mcp-sync',
            'permissions' => [
                'pw-mcp-sync' => 'Use MCP Sync dashboard',
            ],
            'page' => [
                'name' => 'mcp-sync',
                'parent' => 'setup',
                'title' => 'MCP Sync',
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
     * Get Lucide icon SVG markup
     * 
     * @param string $name Icon name
     * @param int $size Icon size in pixels (default 16)
     * @return string SVG markup
     */
    protected function lucideIcon(string $name, int $size = 16): string {
        $icons = [
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
            'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
            'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/>',
            'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
            'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'layout-template' => '<rect width="18" height="7" x="3" y="3" rx="1"/><rect width="9" height="7" x="3" y="14" rx="1"/><rect width="5" height="7" x="16" y="14" rx="1"/>',
            'activity' => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        ];
        
        $path = $icons[$name] ?? '';
        if (!$path) return '';
        
        return '<svg class="pwmcp-lucide" xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
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
        
        $out .= '<form method="get" action="./" class="pwmcp-filters">';
        $out .= '<div class="pwmcp-filter-row">';
        
        // Main filters group (connected)
        $out .= '<div class="pwmcp-filter-group">';
        
        // Search field
        $out .= '<div class="pwmcp-filter-field pwmcp-filter-search">';
        $out .= '<label for="pwmcp-search">' . $this->lucideIcon('search', 14) . ' ' . $this->_('Search') . '</label>';
        $autofocus = $searchQuery ? ' autofocus' : '';
        $out .= '<input type="text" id="pwmcp-search" name="q" value="' . htmlspecialchars($searchQuery) . '" placeholder="' . $this->_('Search pages...') . '"' . $autofocus . '>';
        $out .= '</div>';
        
        // Template filter
        $out .= '<div class="pwmcp-filter-field">';
        $out .= '<label for="pwmcp-template">' . $this->lucideIcon('layout-template', 14) . ' ' . $this->_('Template') . '</label>';
        $out .= '<select id="pwmcp-template" name="template" onchange="this.form.submit()">';
        $out .= '<option value="">' . $this->_('All') . '</option>';
        foreach ($this->wire('templates') as $template) {
            if ($template->flags & Template::flagSystem) continue;
            $sel = ($templateFilter === $template->name) ? ' selected' : '';
            $out .= '<option value="' . $template->name . '"' . $sel . '>' . $template->name . '</option>';
        }
        $out .= '</select>';
        $out .= '</div>';
        
        // Status filter
        $out .= '<div class="pwmcp-filter-field">';
        $out .= '<label for="pwmcp-status">' . $this->lucideIcon('activity', 14) . ' ' . $this->_('Change Status') . '</label>';
        $out .= '<select id="pwmcp-status" name="status" onchange="this.form.submit()">';
        $statuses = [
            '' => $this->_('All'),
            'clean' => $this->_('In Sync'),
            'localDirty' => $this->_('Local Changes'),
            'remoteChanged' => $this->_('Remote Changed'),
            'conflict' => $this->_('Conflict'),
            'notPulled' => $this->_('Never Exported'),
        ];
        foreach ($statuses as $val => $label) {
            $sel = ($statusFilter === $val) ? ' selected' : '';
            $out .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        $out .= '</select>';
        $out .= '</div>';
        
        $out .= '</div>'; // End filter group
        
        // Bulk actions (separate on right)
        $out .= '<div class="pwmcp-filter-field pwmcp-filter-actions">';
        $out .= '<label>' . $this->_('With selected') . '</label>';
        $out .= '<div class="pwmcp-action-row">';
        $out .= '<select name="bulk_action">';
        $out .= '<option value="">' . $this->_('Action...') . '</option>';
        $out .= '<option value="pull">' . $this->_('Export') . '</option>';
        $out .= '<option value="push">' . $this->_('Import') . '</option>';
        $out .= '</select>';
        $out .= '<button type="submit" class="ui-button ui-priority-secondary">' . $this->_('Go') . '</button>';
        $out .= '</div>';
        $out .= '</div>';
        
        $out .= '</div></form>';
        
        // =====================================================================
        // PAGE TABLE - Using native MarkupAdminDataTable
        // =====================================================================
        
        $filteredPages = [];
        $isSearchOrFiltered = $searchQuery || $templateFilter || $statusFilter;
        
        if ($isSearchOrFiltered) {
            // Search/filter mode: show flat list of matching pages
            $selector = "include=hidden, sort=path";
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
            
            // Add Home's children at depth 1
            $homeChildren = $pages->find("parent=1, include=hidden, sort=sort");
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
        
        // Count display with Expand All toggle
        $count = count($filteredPages);
        $out .= '<div class="pwmcp-count-row">';
        $out .= '<span class="pwmcp-count">';
        $out .= sprintf($this->_('%d pages'), $count);
        if ($searchQuery) {
            $out .= ' ' . sprintf($this->_('matching "%s"'), '<strong>' . htmlspecialchars($searchQuery) . '</strong>');
        }
        $out .= '</span>';
        $out .= '<label class="pwmcp-switch">';
        $out .= '<input type="checkbox" id="pwmcp-expand-all">';
        $out .= '<span class="pwmcp-slider"></span>';
        $out .= '</label>';
        $out .= '<span class="pwmcp-switch-label">' . $this->_('Expand All') . '</span>';
        $out .= '</div>';
        
        // Start form for bulk actions (wraps the table)
        $out .= '<form method="post" action="./" id="pwmcp-tree-form">';
        $out .= '<input type="hidden" name="bulk_action" value="" class="pwmcp-bulk-action-field">';
        
        // Build table manually for expand/collapse support
        $out .= '<table class="AdminDataTable AdminDataList uk-table pwmcp-tree-table">';
        $out .= '<thead><tr>';
        $out .= '<th style="width:30px;"><input type="checkbox" class="pwmcp-select-all"></th>';
        $out .= '<th>' . $this->_('Title') . '</th>';
        $out .= '<th>' . $this->_('Template') . '</th>';
        $out .= '<th>' . $this->_('Change Status') . '</th>';
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
        
        // Clear filters link when search/filters are active
        if ($isSearchOrFiltered) {
            $out .= '<p style="margin-top: 1em;"><a href="./">&larr; ' . $this->_('Clear filters') . '</a></p>';
        }
        
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
        $workspaceRoot = $this->getWorkspaceRoot();
        $localPath = $workspaceRoot . ltrim($page->path, '/');
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
     * @return string HTML for the table row
     */
    protected function buildTreeRow(Page $page, string $status, int $depth, array $syncLookup, bool $isExpanded = false): string {
        $indent = $depth * 20; // 20px per level
        
        $html = '<tr data-page-id="' . $page->id . '" data-depth="' . $depth . '" data-parent-id="' . $page->parent->id . '">';
        
        // Checkbox
        $html .= '<td><input type="checkbox" name="selected_pages[]" value="' . $page->id . '" class="pwmcp-page-checkbox"></td>';
        
        // Title with chevron for expandable parents
        $html .= '<td class="pwmcp-title-cell" style="padding-left:' . ($indent + 8) . 'px;">';
        if ($page->numChildren > 0) {
            $expandedAttr = $isExpanded ? 'true' : 'false';
            $html .= '<span class="pwmcp-toggle" data-page-id="' . $page->id . '" data-expanded="' . $expandedAttr . '" title="' . $this->_('Expand') . '">';
            $html .= '<svg class="pwmcp-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
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
        $html .= '<td>' . $page->template->name . '</td>';
        
        // Status badge
        $html .= '<td>' . $this->getStatusBadge($status) . '</td>';
        
        // Modified
        $html .= '<td>' . ($page->modified ? wireRelativeTimeStr($page->modified) : '-') . '</td>';
        
        // Actions
        $html .= '<td>' . $this->getRowActions($page, $status) . '</td>';
        
        $html .= '</tr>';
        
        return $html;
    }
    
    /**
     * AJAX endpoint: Get children of a page as HTML rows
     */
    public function ___executeChildren(): string {
        $input = $this->wire('input');
        $pages = $this->wire('pages');
        
        $parentId = (int) $input->get('id');
        $depth = (int) $input->get('depth') + 1;
        
        if (!$parentId) {
            return '';
        }
        
        // Get children first (fast)
        $children = $pages->find("parent=$parentId, include=hidden, sort=sort");
        
        // Get sync status for accurate badge display
        $syncManager = $this->getSyncManager();
        $statusData = $syncManager->getSyncStatus();
        $syncLookup = $this->buildSyncLookup($statusData);
        
        $html = '';
        foreach ($children as $child) {
            if ($child->template->flags & Template::flagSystem) continue;
            
            // Get accurate status using the same logic as main page
            $status = $this->getPageStatus($child, $syncLookup);
            
            $html .= $this->buildTreeRow($child, $status, $depth, $syncLookup);
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
.pwmcp-header-buttons { display: flex; gap: 8px; margin-bottom: 1.5em; }
.pwmcp-header-buttons .uk-button i { margin-right: 4px; }

/* Filter bar styling - matches ProcessWire native admin */
.pwmcp-filters { margin-bottom: 1.5em; }
.pwmcp-filter-row { 
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
}
.pwmcp-filter-group {
    display: flex;
    border: 1px solid #d7d7d7;
    border-radius: 4px 0 0 4px;
    background: #fff;
}
.pwmcp-filter-group .pwmcp-filter-field { 
    display: flex; 
    flex-direction: column;
    padding: 12px 16px;
    background: #fff;
    border-left: 1px solid #d7d7d7;
}
.pwmcp-filter-group .pwmcp-filter-field:first-child { 
    border-left: none;
    border-radius: 4px 0 0 4px;
}
.pwmcp-filter-group .pwmcp-filter-field:last-child { 
    border-radius: 0;
}
.pwmcp-filter-field > label:first-child { 
    display: flex; 
    align-items: center; 
    gap: 6px; 
    font-size: 13px; 
    font-weight: normal; 
    color: #6c7a89; 
    margin-bottom: 8px;
}
.pwmcp-filter-field > label:first-child .pwmcp-lucide { color: #6c7a89; }
.pwmcp-filter-field input[type="text"],
.pwmcp-filter-field select { 
    padding: 8px 12px; 
    border: none;
    border-radius: 2px;
    font-size: 14px;
    min-width: 140px;
    background: #f1f1f1;
    color: #354052;
    transition: background-color 0.15s;
    height: 36px;
    box-sizing: border-box;
}
.pwmcp-filter-field select { 
    padding-right: 48px !important; 
    background-position: right 12px center !important;
}
.pwmcp-filter-field input[type="text"]:hover,
.pwmcp-filter-field select:hover { 
    background: #e8e8e8;
}
.pwmcp-filter-field input[type="text"]:focus,
.pwmcp-filter-field select:focus { 
    background: #fff;
    outline: 2px solid #1e87f0;
    outline-offset: -2px;
}
.pwmcp-filter-search { min-width: 200px; max-width: 260px; }
.pwmcp-filter-search input.pwmcp-searching { 
    background-color: #e8f4fc;
}
.pwmcp-count-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 12px 0;
    font-size: 13px;
    color: #666;
}
.pwmcp-count { font-weight: normal; }
.pwmcp-switch-label { font-size: 13px; color: #666; }
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
.pwmcp-filter-actions { 
    display: flex;
    flex-direction: column;
    border: 1px solid #d7d7d7;
    border-left: none;
    border-radius: 0 4px 4px 0;
    padding: 12px 16px;
    background: #fff;
}
.pwmcp-action-row { display: flex; gap: 8px; align-items: center; }
.pwmcp-action-row select { flex: 1; min-width: 120px; }
.pwmcp-action-row .ui-button { 
    height: 36px;
    padding: 0 16px; 
    font-size: 14px;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Tree table styling */
.pwmcp-tree-table { border-collapse: collapse; }
.pwmcp-tree-table thead th { border-bottom: 1px solid #f1f1f1; padding: 8px; }
.pwmcp-tree-table thead th:nth-child(3),
.pwmcp-tree-table thead th:nth-child(4),
.pwmcp-tree-table thead th:nth-child(5) { font-size: 13px; }
.pwmcp-tree-table tbody tr { border-bottom: 1px solid #f1f1f1; }
.pwmcp-tree-table tbody tr:hover { border-color: #eee; }
.pwmcp-tree-table tbody td { padding: 4px 8px; vertical-align: middle; }
.pwmcp-tree-table tbody td:nth-child(3),
.pwmcp-tree-table tbody td:nth-child(4),
.pwmcp-tree-table tbody td:nth-child(5) { font-size: 13px; }
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
.pwmcp-action { display: inline-flex !important; align-items: center !important; justify-content: center !important; padding: 4px !important; color: #d9534f !important; cursor: pointer !important; text-decoration: none !important; }
.pwmcp-action:hover { color: #c9302c !important; cursor: pointer !important; }
.pwmcp-action[title] { cursor: pointer !important; }
.pwmcp-action * { cursor: pointer !important; }
/* Custom tooltip system for action icons - no native tooltips */
.pwmcp-action { position: relative !important; }
.pwmcp-action-tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(-4px);
    background: rgba(0, 0, 0, 0.9);
    color: #fff;
    padding: 6px 10px;
    border-radius: 3px;
    font-size: 12px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.15s ease, visibility 0.15s ease;
    transition-delay: 0.1s;
    z-index: 10000;
    margin-bottom: 4px;
}
.pwmcp-action:hover .pwmcp-action-tooltip {
    opacity: 1;
    visibility: visible;
}
.pwmcp-action-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-top-color: rgba(0, 0, 0, 0.9);
}
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
/* Status badge overrides - each status has consistent intensity with lighter fill than border */
.pwmcp-tree-table .uk-label { font-size: 12px !important; font-weight: normal !important; padding: 3px 7px !important; border-radius: 5px !important; border: 1px solid !important; display: inline-block !important; line-height: 1.4 !important; text-transform: none !important; }
.pwmcp-tree-table .uk-label-success { color: rgba(35, 120, 60, 1) !important; border-color: rgba(60, 150, 85, 1) !important; background-color: rgba(40, 167, 69, 0.15) !important; }
.pwmcp-tree-table .uk-label-warning { color: rgba(150, 110, 0, 1) !important; border-color: rgba(200, 155, 30, 1) !important; background-color: rgba(255, 193, 7, 0.15) !important; }
.pwmcp-tree-table .uk-label-primary { color: rgba(25, 95, 160, 1) !important; border-color: rgba(65, 145, 210, 1) !important; background-color: rgba(30, 135, 240, 0.15) !important; }
.pwmcp-tree-table .uk-label-danger { color: rgba(165, 40, 50, 1) !important; border-color: rgba(200, 75, 85, 1) !important; background-color: rgba(220, 53, 69, 0.15) !important; }
.pwmcp-tree-table .uk-label-muted { color: rgba(120, 120, 120, 1) !important; border-color: rgba(180, 180, 180, 1) !important; background-color: rgba(200, 200, 200, 0.1) !important; }
.pwmcp-toggle-spacer { display: inline-block; width: 14px; }
.pwmcp-title-cell { white-space: nowrap; line-height: 1.6em; max-width: 350px; overflow: hidden; text-overflow: ellipsis; }
.pwmcp-title-cell a { display: inline; }
.pwmcp-title-cell small { font-size: 13px; color: #999; }
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
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var childrenUrl = '{$childrenUrl}';
    
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
    
    // Select all checkbox
    var selectAll = document.querySelector('.pwmcp-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.pwmcp-page-checkbox').forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
        });
    }
    
    // Handle bulk actions - intercept Go button and submit tree form instead
    var filterForm = document.querySelector('.pwmcp-filters');
    var bulkActionSelect = filterForm ? filterForm.querySelector('select[name="bulk_action"]') : null;
    var goButton = filterForm ? filterForm.querySelector('button[type="submit"]') : null;
    var treeForm = document.getElementById('pwmcp-tree-form');
    var hiddenBulkAction = treeForm ? treeForm.querySelector('.pwmcp-bulk-action-field') : null;
    
    if (goButton && bulkActionSelect && treeForm && hiddenBulkAction) {
        goButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var action = bulkActionSelect.value;
            if (!action) {
                alert('Please select an action');
                return;
            }
            
            // Check if any pages are selected
            var checkedBoxes = document.querySelectorAll('.pwmcp-page-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('Please select at least one page');
                return;
            }
            
            // Copy action to hidden field and submit tree form
            hiddenBulkAction.value = action;
            hiddenBulkAction.setAttribute('name', 'bulk_action');
            treeForm.submit();
        });
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
                        // Initialize tooltips and cursors for newly loaded content
                        document.querySelectorAll('.pwmcp-action').forEach(function(action) {
                            action.style.cursor = 'pointer';
                            if (typeof ProcessWire !== 'undefined' && ProcessWire.setupTooltips) {
                                ProcessWire.setupTooltips(action);
                            }
                        });
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
                        // Initialize tooltips and cursors for newly loaded content
                        document.querySelectorAll('.pwmcp-action').forEach(function(action) {
                            action.style.cursor = 'pointer';
                            if (typeof ProcessWire !== 'undefined' && ProcessWire.setupTooltips) {
                                ProcessWire.setupTooltips(action);
                            }
                        });
                    }
                    pendingLoads--;
                    // After this batch loads, check if there are more to expand
                    if (pendingLoads === 0) {
                        setTimeout(expandAllNodes, 50);
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
    }
    
    // Function to initialize action buttons with custom tooltips (no native tooltips)
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
            
            // Setup custom tooltip from data attribute
            var tooltipText = action.getAttribute('data-pwmcp-tooltip');
            if (tooltipText && !action.hasAttribute('data-tooltip-initialized')) {
                // Mark as initialized to prevent duplicates
                action.setAttribute('data-tooltip-initialized', 'true');
                
                // Remove any title attribute to prevent native tooltips
                action.removeAttribute('title');
                
                // Check if tooltip element already exists
                var existingTooltip = action.querySelector('.pwmcp-action-tooltip');
                if (!existingTooltip) {
                    // Create custom tooltip element
                    var tooltip = document.createElement('span');
                    tooltip.className = 'pwmcp-action-tooltip';
                    tooltip.textContent = tooltipText;
                    action.appendChild(tooltip);
                }
            }
        });
    }
    
    // Initialize on page load
    initializeActionButtons();
    
    // Watch for dynamically added action buttons
    var observer = new MutationObserver(function(mutations) {
        var needsInit = false;
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        if (node.classList && node.classList.contains('pwmcp-action')) {
                            needsInit = true;
                        } else if (node.querySelector && node.querySelector('.pwmcp-action')) {
                            needsInit = true;
                        }
                    }
                });
            }
        });
        if (needsInit) {
            initializeActionButtons();
        }
    });
    
    // Observe the table body for changes
    var tableBody = document.getElementById('pwmcp-tree-body');
    if (tableBody) {
        observer.observe(tableBody, { childList: true, subtree: true });
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
            $this->message(sprintf($this->_('Exported %d pages'), $pulled));
        } elseif ($action === 'push') {
            $pushed = 0;
            foreach ($pageIds as $pageId) {
                $page = $this->wire('pages')->get($pageId);
                if (!$page->id) continue;
                $localPath = $this->getWorkspaceRoot() . ltrim($page->path, '/');
                $result = $syncManager->pushPage($localPath, false);
                if (isset($result['success']) && $result['success']) {
                    $pushed++;
                }
            }
            $this->message(sprintf($this->_('Imported %d pages'), $pushed));
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
            'clean' => ['In Sync', 'uk-label-success'],
            'localDirty' => ['Local Changes', 'uk-label-warning'],
            'remoteChanged' => ['Remote Changes', 'uk-label-primary'],
            'conflict' => ['Conflict', 'uk-label-danger'],
            'orphan' => ['Orphan', ''],
            'notPulled' => ['Never Exported', 'uk-label-muted'],
        ];
        
        $label = $labels[$status] ?? ['Unknown', ''];
        $class = $label[1] ? "class='uk-label {$label[1]}'" : "class='uk-label'";
        
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
        $isNotExported = ($status === 'notPulled');
        
        // Export button - always active
        $actions[] = "<a href='{$baseUrl}pull/?id={$page->id}' class='pwmcp-action' data-pwmcp-tooltip='Export to File (YAML)'>" .
            $this->lucideIcon('download') . "</a>";
        
        // Import button - disabled if not exported yet
        $importClass = $isNotExported ? 'pwmcp-action pwmcp-action-disabled' : 'pwmcp-action';
        $importHref = $isNotExported ? '#' : "{$baseUrl}push/?id={$page->id}";
        $importTooltip = $isNotExported ? 'Export page first to enable import' : 'Import from File (YAML)';
        $actions[] = "<a href='{$importHref}' class='{$importClass}' data-pwmcp-tooltip='{$importTooltip}'>" .
            $this->lucideIcon('upload') . "</a>";
        
        // View YAML button - disabled if not exported yet
        $viewClass = $isNotExported ? 'pwmcp-action pwmcp-action-disabled' : 'pw-modal pwmcp-action';
        $viewHref = $isNotExported ? '#' : "{$baseUrl}view-yaml/?id={$page->id}";
        $viewTooltip = $isNotExported ? 'Export page first to view YAML' : 'View YAML';
        $actions[] = "<a href='{$viewHref}' class='{$viewClass}' data-pwmcp-tooltip='{$viewTooltip}'>" .
            $this->lucideIcon('file-text') . "</a>";
        
        return implode(' ', $actions);
    }

    /**
     * Export a single page to YAML file
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
                    $this->_('Exported page "%s" to %s'),
                    $page->title,
                    $result['localPath'] ?? ''
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Failed to pull page'));
            }
        } catch (\Exception $e) {
            $this->error($this->_('Error: ') . $e->getMessage());
        }
        
        $session->redirect($baseUrl);
        return '';
    }

    /**
     * Import a single page from YAML file with dry-run preview
     */
    public function ___executePush(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        // Check both GET (initial load) and POST (form submission) for page ID
        $pageId = (int) $input->get('id') ?: (int) $input->post('id');
        $confirmed = $input->post('submit_confirm') ? true : false;
        
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
        $workspacePath = $this->getWorkspaceRoot() . ltrim($page->path, '/');
        
        // Check if confirmed
        if ($confirmed) {
            // Actually push
            $result = $syncManager->pushPage($workspacePath, false); // dryRun = false
            
            if (isset($result['success']) && $result['success']) {
                $this->message(sprintf(
                    $this->_('Imported changes to page "%s"'),
                    $page->title
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Failed to push page'));
            }
            
            $this->wire('session')->redirect($this->wire('page')->url);
            return '';
        }
        
        // Dry-run preview
        $result = $syncManager->pushPage($workspacePath, true); // dryRun = true
        
        $this->headline(sprintf($this->_('Import Preview: %s'), $page->title));
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        $form->attr('action', $this->wire('page')->url . 'push/?id=' . $pageId);
        
        // Show preview
        $preview = $modules->get('InputfieldMarkup');
        $preview->label = $this->_('Changes to Apply');
        
        if (isset($result['dryRun']) && $result['dryRun']) {
            if (!empty($result['changes'])) {
                $preview->value = '<ul>';
                foreach ($result['changes'] as $field => $change) {
                    $preview->value .= "<li><strong>{$field}</strong>: " . 
                        htmlspecialchars(substr(json_encode($change), 0, 100)) . "...</li>";
                }
                $preview->value .= '</ul>';
            } else {
                $preview->value = '<p>' . $this->_('No changes detected.') . '</p>';
            }
        } elseif (isset($result['error'])) {
            $preview->value = '<p class="uk-alert uk-alert-danger">' . 
                htmlspecialchars($result['error']) . '</p>';
        }
        
        $form->add($preview);
        
        // Hidden page ID
        $f = $modules->get('InputfieldHidden');
        $f->attr('name', 'id');
        $f->attr('value', $pageId);
        $form->add($f);
        
        // Confirm button
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'submit_confirm');
        $f->value = $this->_('Confirm Import');
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
     * View YAML content (read-only modal)
     */
    public function ___executeViewYaml(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $pageId = (int) $input->get('id');
        
        if (!$pageId) {
            return '<p>' . $this->_('No page ID specified') . '</p>';
        }
        
        $page = $this->wire('pages')->get($pageId);
        if (!$page || !$page->id) {
            return '<p>' . $this->_('Page not found') . '</p>';
        }
        
        $workspacePath = $this->getWorkspaceRoot() . ltrim($page->path, '/');
        $yamlPath = $workspacePath . 'page.yaml';
        
        if (!file_exists($yamlPath)) {
            return '<p>' . $this->_('YAML file not found. Export the page first.') . '</p>';
        }
        
        $yamlContent = file_get_contents($yamlPath);
        
        $out = '<div class="pwmcp-yaml-viewer">';
        $out .= '<p class="notes">' . $this->_('Path:') . ' <code>' . 
            htmlspecialchars($yamlPath) . '</code></p>';
        $out .= '<pre style="background:#f5f5f5; padding:1em; overflow:auto; max-height:500px;">' . 
            htmlspecialchars($yamlContent) . '</pre>';
        $out .= '</div>';
        
        return $out;
    }

    /**
     * Bulk Export All (with confirmation)
     */
    public function ___executePullAll(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $session = $this->wire('session');
        
        $this->headline($this->_('Bulk Export'));
        
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
        $info->label = $this->_('Bulk Export Preview');
        $info->value = '<p>' . sprintf(
            $this->_('This will export all pages under: %s'),
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
                    $this->_('Exported %d pages successfully'),
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
        $f->value = $this->_('Confirm Export All');
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
     * Bulk Import All (with dry-run preview)
     */
    public function ___executePushAll(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $session = $this->wire('session');
        
        $this->headline($this->_('Bulk Import'));
        
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
                    $this->_('Imported %d pages successfully'),
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
        $info->label = $this->_('Bulk Import Preview');
        
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
        $f->value = $this->_('Confirm Import All');
        $f->icon = 'upload';
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
            $this->_('Reconcile fixes structural sync issues (moved/deleted pages). To apply content changes, use Import instead.') . 
            '</p>';
        
        $info->value .= '<h4>' . $this->_('Summary') . '</h4>';
        $info->value .= '<table class="AdminDataTable" style="width:auto;">';
        $info->value .= '<tr><td><strong>' . sprintf('%d', $result['summary']['clean'] ?? 0) . '</strong></td>';
        $info->value .= '<td>' . $this->_('In Sync') . '</td>';
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
