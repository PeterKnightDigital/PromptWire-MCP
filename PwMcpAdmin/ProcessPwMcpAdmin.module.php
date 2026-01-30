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
        $templateFilter = $input->get('template') ?: '';
        $statusFilter = $input->get('status') ?: '';
        $parentId = (int) $input->get('parent') ?: 1;
        
        // Get sync status data
        $syncManager = $this->getSyncManager();
        $statusData = $syncManager->getSyncStatus();
        $syncedById = $this->buildSyncLookup($statusData);
        
        // Build output
        $out = '';
        
        // =====================================================================
        // FILTER BAR - Clean inline style like Lister Pro
        // =====================================================================
        
        $out .= '<div class="pwmcp-filters" style="margin-bottom: 1em; padding: 10px; background: #f8f8f8; border-radius: 3px;">';
        $out .= '<form method="get" action="./" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">';
        
        // Parent selector
        $out .= '<label style="display: flex; align-items: center; gap: 5px;">';
        $out .= '<span>' . $this->_('Parent:') . '</span>';
        $out .= '<select name="parent" onchange="this.form.submit()" style="min-width: 150px;">';
        $out .= '<option value="1">' . $this->_('Home (all)') . '</option>';
        $topPages = $pages->find('parent=1, include=hidden, sort=sort');
        foreach ($topPages as $p) {
            if ($p->template->flags & Template::flagSystem) continue;
            $sel = ($parentId == $p->id) ? ' selected' : '';
            $out .= '<option value="' . $p->id . '"' . $sel . '>' . htmlspecialchars($p->title) . '</option>';
        }
        $out .= '</select></label>';
        
        // Template filter
        $out .= '<label style="display: flex; align-items: center; gap: 5px;">';
        $out .= '<span>' . $this->_('Template:') . '</span>';
        $out .= '<select name="template" onchange="this.form.submit()" style="min-width: 120px;">';
        $out .= '<option value="">' . $this->_('All') . '</option>';
        foreach ($this->wire('templates') as $template) {
            if ($template->flags & Template::flagSystem) continue;
            $sel = ($templateFilter === $template->name) ? ' selected' : '';
            $out .= '<option value="' . $template->name . '"' . $sel . '>' . $template->name . '</option>';
        }
        $out .= '</select></label>';
        
        // Status filter
        $out .= '<label style="display: flex; align-items: center; gap: 5px;">';
        $out .= '<span>' . $this->_('Status:') . '</span>';
        $out .= '<select name="status" onchange="this.form.submit()" style="min-width: 120px;">';
        $statuses = [
            '' => $this->_('All'),
            'clean' => $this->_('Clean'),
            'localDirty' => $this->_('Local Changes'),
            'remoteChanged' => $this->_('Remote Changed'),
            'conflict' => $this->_('Conflict'),
            'notPulled' => $this->_('Not Pulled'),
        ];
        foreach ($statuses as $val => $label) {
            $sel = ($statusFilter === $val) ? ' selected' : '';
            $out .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        $out .= '</select></label>';
        
        // Bulk actions
        $out .= '<span style="margin-left: auto; display: flex; align-items: center; gap: 5px;">';
        $out .= '<span>' . $this->_('With selected:') . '</span>';
        $out .= '<select name="bulk_action" style="min-width: 100px;">';
        $out .= '<option value="">' . $this->_('Action...') . '</option>';
        $out .= '<option value="pull">' . $this->_('Pull') . '</option>';
        $out .= '<option value="push">' . $this->_('Push') . '</option>';
        $out .= '</select>';
        $out .= '<button type="submit" class="ui-button ui-priority-secondary" style="padding: 5px 10px;">' . $this->_('Go') . '</button>';
        $out .= '</span>';
        
        $out .= '</form></div>';
        
        // =====================================================================
        // PAGE TABLE - Using native MarkupAdminDataTable
        // =====================================================================
        
        // Build selector for pages
        $selector = "parent=$parentId, include=hidden, sort=sort";
        if ($templateFilter) {
            $selector .= ", template=$templateFilter";
        }
        
        $pageList = $pages->find($selector);
        
        // Filter by status if needed
        $filteredPages = [];
        foreach ($pageList as $page) {
            if ($page->template->flags & Template::flagSystem) continue;
            
            $syncInfo = $syncedById[$page->id] ?? null;
            $status = $syncInfo ? ($syncInfo['status'] ?? 'notPulled') : 'notPulled';
            
            if ($statusFilter && $status !== $statusFilter) continue;
            
            $filteredPages[] = [
                'page' => $page,
                'status' => $status,
                'syncInfo' => $syncInfo,
            ];
        }
        
        // Count display
        $count = count($filteredPages);
        $out .= '<p class="description" style="margin-bottom: 0.5em;">';
        $out .= sprintf($this->_('%d pages'), $count);
        if ($parentId > 1) {
            $parentPage = $pages->get($parentId);
            $out .= ' ' . sprintf($this->_('in %s'), '<strong>' . htmlspecialchars($parentPage->title) . '</strong>');
        }
        $out .= '</p>';
        
        // Start form for bulk actions (wraps the table)
        $out .= '<form method="post" action="./" id="pwmcp-tree-form">';
        $out .= '<input type="hidden" name="bulk_action" value="" class="pwmcp-bulk-action-field">';
        
        // Build table manually for expand/collapse support
        $out .= '<table class="AdminDataTable AdminDataList uk-table pwmcp-tree-table">';
        $out .= '<thead><tr>';
        $out .= '<th style="width:30px;"><input type="checkbox" class="pwmcp-select-all"></th>';
        $out .= '<th>' . $this->_('Title') . '</th>';
        $out .= '<th>' . $this->_('Template') . '</th>';
        $out .= '<th>' . $this->_('Status') . '</th>';
        $out .= '<th>' . $this->_('Modified') . '</th>';
        $out .= '<th>' . $this->_('Actions') . '</th>';
        $out .= '</tr></thead>';
        $out .= '<tbody id="pwmcp-tree-body">';
        
        foreach ($filteredPages as $item) {
            $out .= $this->buildTreeRow($item['page'], $item['status'], 0, $syncedById);
        }
        
        $out .= '</tbody></table>';
        $out .= '</form>';
        
        // Breadcrumb for navigation
        if ($parentId > 1) {
            $parentPage = $pages->get($parentId);
            $breadcrumb = '<p style="margin-top: 1em;"><a href="./">&larr; ' . $this->_('Back to Home') . '</a>';
            if ($parentPage->parent->id > 1) {
                $breadcrumb .= ' | <a href="./?parent=' . $parentPage->parent->id . '">&larr; ' . 
                    htmlspecialchars($parentPage->parent->title) . '</a>';
            }
            $breadcrumb .= '</p>';
            $out .= $breadcrumb;
        }
        
        // JavaScript
        $out .= $this->getCleanScript();
        
        // =====================================================================
        // HEADER BUTTONS
        // =====================================================================
        
        $form = $modules->get('InputfieldForm');
        
        // Refresh
        $btn = $modules->get('InputfieldButton');
        $btn->value = $this->_('Refresh');
        $btn->icon = 'refresh';
        $btn->href = './';
        $btn->showInHeader(true);
        $form->add($btn);
        
        // Reconcile
        $btn = $modules->get('InputfieldButton');
        $btn->value = $this->_('Reconcile');
        $btn->icon = 'wrench';
        $btn->href = './reconcile/';
        $btn->setSecondary(true);
        $btn->showInHeader(true);
        $form->add($btn);
        
        return $form->render() . $out;
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
     * Build a single tree row for a page
     * 
     * @param Page $page The page to render
     * @param string $status Sync status
     * @param int $depth Nesting depth for indentation
     * @param array $syncLookup Sync status lookup array
     * @return string HTML for the table row
     */
    protected function buildTreeRow(Page $page, string $status, int $depth, array $syncLookup): string {
        $indent = $depth * 20; // 20px per level
        
        $html = '<tr data-page-id="' . $page->id . '" data-depth="' . $depth . '" data-parent-id="' . $page->parent->id . '">';
        
        // Checkbox
        $html .= '<td><input type="checkbox" name="selected_pages[]" value="' . $page->id . '" class="pwmcp-page-checkbox"></td>';
        
        // Title with chevron for expandable parents
        $html .= '<td class="pwmcp-title-cell" style="padding-left:' . ($indent + 8) . 'px;">';
        if ($page->numChildren > 0) {
            $html .= '<span class="pwmcp-toggle" data-page-id="' . $page->id . '" data-expanded="false" title="' . $this->_('Expand') . '">';
            $html .= '<i class="fa fa-angle-right"></i>';
            $html .= '</span> ';
        } else {
            // Spacer for alignment
            $html .= '<span class="pwmcp-toggle-spacer" style="display:inline-block;width:14px;"></span> ';
        }
        $html .= '<a href="' . $page->editUrl . '">' . htmlspecialchars($page->title ?: $page->name) . '</a>';
        if ($page->numChildren > 0) {
            $html .= ' <small style="color:#888;">' . $page->numChildren . '</small>';
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
        
        // Get sync status data for lookups
        $syncManager = $this->getSyncManager();
        $statusData = $syncManager->getSyncStatus();
        $syncedById = $this->buildSyncLookup($statusData);
        
        // Get children
        $children = $pages->find("parent=$parentId, include=hidden, sort=sort");
        
        $html = '';
        foreach ($children as $child) {
            if ($child->template->flags & Template::flagSystem) continue;
            
            $syncInfo = $syncedById[$child->id] ?? null;
            $status = $syncInfo ? ($syncInfo['status'] ?? 'notPulled') : 'notPulled';
            
            $html .= $this->buildTreeRow($child, $status, $depth, $syncedById);
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
.pwmcp-tree-table { border-collapse: collapse; }
.pwmcp-tree-table tbody tr { border-bottom: 1px solid #f1f1f1; }
.pwmcp-tree-table tbody tr:hover { border-color: #eee; }
.pwmcp-tree-table tbody td { padding: 4px 8px; vertical-align: middle; }
.pwmcp-toggle { 
    cursor: pointer; 
    display: inline-block;
    width: 10px;
    text-align: center;
    margin-right: 4px;
    color: #bbb;
    font-size: 14px;
    line-height: 12px;
    position: relative;
    left: 1px;
}
.pwmcp-toggle:hover { color: #999; }
.pwmcp-toggle[data-expanded="true"] { color: #999; }
.pwmcp-toggle-spacer { display: inline-block; width: 14px; }
.pwmcp-title-cell { white-space: nowrap; line-height: 1.6em; }
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
    
    // Select all checkbox
    var selectAll = document.querySelector('.pwmcp-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.pwmcp-page-checkbox').forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
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
        
        var icon = toggle.querySelector('i');
        
        if (isExpanded) {
            // Collapse: remove all child rows
            toggle.setAttribute('data-expanded', 'false');
            icon.className = 'fa fa-angle-right';
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
            icon.className = 'fa fa-angle-down';
            
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
                    }
                })
                .catch(function(err) {
                    spinner.remove();
                    console.error('Failed to load children:', err);
                });
        }
    });
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
            $this->wire('session')->redirect('./');
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
            $this->message(sprintf($this->_('Pulled %d pages'), $pulled));
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
            $this->message(sprintf($this->_('Pushed %d pages'), $pushed));
        }
        
        $this->wire('session')->redirect('./');
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
            'localDirty' => ['Local Changes', 'uk-label-warning'],
            'remoteChanged' => ['Remote Changes', 'uk-label-primary'],
            'conflict' => ['Conflict', 'uk-label-danger'],
            'orphan' => ['Orphan', ''],
            'notPulled' => ['Not Pulled', ''],
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
        
        // Pull (Export) button
        $actions[] = "<a href='{$baseUrl}pull/?id={$page->id}' class='pw-tooltip' title='Pull (Export) to YAML'>" .
            wireIconMarkup('download') . "</a>";
        
        // Push (Import) button - only if local exists
        if ($status !== 'notPulled') {
            $actions[] = "<a href='{$baseUrl}push/?id={$page->id}' class='pw-tooltip' title='Push (Import) from YAML'>" .
                wireIconMarkup('upload') . "</a>";
            
            // View YAML button
            $actions[] = "<a href='{$baseUrl}view-yaml/?id={$page->id}' class='pw-tooltip pw-modal' title='View YAML'>" .
                wireIconMarkup('file-text-o') . "</a>";
        }
        
        return implode(' ', $actions);
    }

    /**
     * Pull (Export) a single page
     */
    public function ___executePull(): string {
        $input = $this->wire('input');
        $pageId = (int) $input->get('id');
        
        if (!$pageId) {
            $this->error($this->_('No page ID specified'));
            $this->wire('session')->redirect('./');
            return '';
        }
        
        $page = $this->wire('pages')->get($pageId);
        if (!$page || !$page->id) {
            $this->error($this->_('Page not found'));
            $this->wire('session')->redirect('./');
            return '';
        }
        
        $syncManager = $this->getSyncManager();
        $result = $syncManager->pullPage($pageId);
        
        if (isset($result['success']) && $result['success']) {
            $this->message(sprintf(
                $this->_('Pulled page "%s" to %s'),
                $page->title,
                $result['localPath']
            ));
        } else {
            $this->error($result['error'] ?? $this->_('Failed to pull page'));
        }
        
        $this->wire('session')->redirect('./');
        return '';
    }

    /**
     * Push (Import) a single page with dry-run preview
     */
    public function ___executePush(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $pageId = (int) $input->get('id');
        $confirmed = (int) $input->post('confirm');
        
        if (!$pageId) {
            $this->error($this->_('No page ID specified'));
            $this->wire('session')->redirect('./');
            return '';
        }
        
        $page = $this->wire('pages')->get($pageId);
        if (!$page || !$page->id) {
            $this->error($this->_('Page not found'));
            $this->wire('session')->redirect('./');
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
                    $this->_('Pushed changes to page "%s"'),
                    $page->title
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Failed to push page'));
            }
            
            $this->wire('session')->redirect('./');
            return '';
        }
        
        // Dry-run preview
        $result = $syncManager->pushPage($workspacePath, true); // dryRun = true
        
        $this->headline(sprintf($this->_('Push Preview: %s'), $page->title));
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        
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
        $f->attr('name', 'confirm');
        $f->attr('value', 1);
        $f->value = $this->_('Confirm Push');
        $f->icon = 'check';
        $form->add($f);
        
        // Cancel button
        $btn = $modules->get('InputfieldButton');
        $btn->value = $this->_('Cancel');
        $btn->href = './';
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
            return '<p>' . $this->_('YAML file not found. Pull the page first.') . '</p>';
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
     * Bulk Pull All (with confirmation)
     */
    public function ___executePullAll(): string {
        $input = $this->wire('input');
        $modules = $this->wire('modules');
        $session = $this->wire('session');
        
        $this->headline($this->_('Bulk Pull (Export)'));
        
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
        $info->label = $this->_('Bulk Pull Preview');
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
                    $this->_('Pulled %d pages successfully'),
                    $result['pulled'] ?? 0
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Bulk pull failed'));
            }
            
            $session->redirect('./');
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
        
        $this->headline($this->_('Bulk Push (Import)'));
        
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
                    $this->_('Pushed %d pages successfully'),
                    $result['pushed'] ?? 0
                ));
            } else {
                $this->error($result['error'] ?? $this->_('Bulk push failed'));
            }
            
            $session->redirect('./');
            return '';
        }
        
        // Dry-run preview - scoped to filter
        $result = $syncManager->pushPages($pushDir, true); // dryRun = true
        
        $info = $modules->get('InputfieldMarkup');
        $info->label = $this->_('Bulk Push Preview');
        
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
        $f->value = $this->_('Confirm Push All');
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
            
            $this->wire('session')->redirect('./');
            return '';
        }
        
        // Dry-run preview
        $result = $syncManager->reconcile(null, true); // dryRun = true
        
        $info = $modules->get('InputfieldMarkup');
        $info->label = $this->_('Reconcile Preview');
        
        $info->value = '<h4>' . $this->_('Summary') . '</h4><ul>';
        $info->value .= '<li>' . sprintf($this->_('Clean: %d'), $result['summary']['clean'] ?? 0) . '</li>';
        $info->value .= '<li>' . sprintf($this->_('Path Drift: %d'), $result['summary']['pathDrift'] ?? 0) . '</li>';
        $info->value .= '<li>' . sprintf($this->_('Orphans: %d'), $result['summary']['orphans'] ?? 0) . '</li>';
        $info->value .= '<li>' . sprintf($this->_('New Pages: %d'), $result['summary']['newPages'] ?? 0) . '</li>';
        $info->value .= '</ul>';
        
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
