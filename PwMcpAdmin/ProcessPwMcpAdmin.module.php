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
     * Main execute - show tree-based sync dashboard
     * 
     * @return string Rendered output
     */
    public function ___execute(): string {
        $modules = $this->wire('modules');
        $input = $this->wire('input');
        $session = $this->wire('session');
        
        // Set page headline
        $this->headline($this->_('MCP Sync Dashboard'));
        $this->browserTitle($this->_('MCP Sync'));
        
        // Handle bulk actions from checkboxes
        if ($input->post('bulk_action') && $input->post('selected_pages')) {
            return $this->handleBulkAction(
                $input->post('bulk_action'),
                $input->post('selected_pages')
            );
        }
        
        // Check for clear filters action
        if ($input->post('clear_filters') || $input->get('clear')) {
            $session->remove('pwmcp_template');
            $session->remove('pwmcp_status');
            $session->remove('pwmcp_search');
            $session->redirect('./');
            return '';
        }
        
        // Get filter values from input or session
        $templateFilter = $input->post('template') ?: $session->get('pwmcp_template') ?: '';
        $statusFilter = $input->post('status') ?: $session->get('pwmcp_status') ?: '';
        $searchQuery = $input->post('q') ?: $session->get('pwmcp_search') ?: '';
        
        // Save filters to session
        if ($input->requestMethod('POST')) {
            $session->set('pwmcp_template', $templateFilter);
            $session->set('pwmcp_status', $statusFilter);
            $session->set('pwmcp_search', $searchQuery);
        }
        
        // Get sync status data
        $syncManager = $this->getSyncManager();
        $statusData = $syncManager->getSyncStatus();
        $syncedById = $this->buildSyncLookup($statusData);
        
        // Build the form
        $form = $modules->get('InputfieldForm');
        $form->attr('id', 'pwmcp-sync-form');
        $form->attr('method', 'post');
        $form->attr('action', './');
        
        // =====================================================================
        // FILTER ROW (collapsed by default)
        // =====================================================================
        
        $filterFieldset = $modules->get('InputfieldFieldset');
        $filterFieldset->label = $this->_('Filters');
        $filterFieldset->collapsed = ($templateFilter || $statusFilter || $searchQuery) 
            ? Inputfield::collapsedNo : Inputfield::collapsedYes;
        $filterFieldset->icon = 'filter';
        
        // Template filter
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'template');
        $f->label = $this->_('Template');
        $f->addOption('', $this->_('All Templates'));
        foreach ($this->wire('templates') as $template) {
            if ($template->flags & Template::flagSystem) continue;
            $f->addOption($template->name, $template->name);
        }
        $f->attr('value', $templateFilter);
        $f->columnWidth = 33;
        $filterFieldset->add($f);
        
        // Status filter
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'status');
        $f->label = $this->_('Sync Status');
        $f->addOption('', $this->_('All Statuses'));
        $f->addOption('clean', $this->_('Clean'));
        $f->addOption('localDirty', $this->_('Local Changes'));
        $f->addOption('remoteChanged', $this->_('Remote Changes'));
        $f->addOption('conflict', $this->_('Conflict'));
        $f->addOption('notPulled', $this->_('Not Pulled'));
        $f->attr('value', $statusFilter);
        $f->columnWidth = 33;
        $filterFieldset->add($f);
        
        // Search
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'q');
        $f->label = $this->_('Search');
        $f->attr('placeholder', $this->_('Search titles...'));
        $f->attr('value', $searchQuery);
        $f->columnWidth = 34;
        $filterFieldset->add($f);
        
        $form->add($filterFieldset);
        
        // Filter button (inline)
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'submit_filter');
        $f->value = $this->_('Apply Filter');
        $f->icon = 'search';
        $f->addClass('uk-margin-small-right');
        $form->add($f);
        
        // Clear filters button
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'clear_filters');
        $f->value = $this->_('Clear');
        $f->icon = 'times';
        $f->setSecondary(true);
        $form->add($f);
        
        // =====================================================================
        // BULK ACTION BAR
        // =====================================================================
        
        $bulkBar = '<div class="pwmcp-bulk-bar" style="margin: 1em 0; padding: 0.5em; background: #f5f5f5; border-radius: 4px;">';
        $bulkBar .= '<strong>' . $this->_('With selected:') . '</strong> ';
        $bulkBar .= '<select name="bulk_action" style="margin: 0 0.5em;">';
        $bulkBar .= '<option value="">' . $this->_('Choose action...') . '</option>';
        $bulkBar .= '<option value="pull">' . $this->_('Pull (Export to YAML)') . '</option>';
        $bulkBar .= '<option value="push">' . $this->_('Push (Import from YAML)') . '</option>';
        $bulkBar .= '</select>';
        $bulkBar .= '<button type="submit" class="ui-button ui-state-default">' . $this->_('Go') . '</button>';
        $bulkBar .= ' <span class="pwmcp-selected-count" style="margin-left: 1em; color: #666;">' . 
            $this->_('0 pages selected') . '</span>';
        $bulkBar .= '</div>';
        
        $bulkWrapper = $modules->get('InputfieldMarkup');
        $bulkWrapper->value = $bulkBar;
        $form->add($bulkWrapper);
        
        // =====================================================================
        // PAGE TREE TABLE
        // =====================================================================
        
        $treeHtml = $this->buildPageTree($templateFilter, $statusFilter, $searchQuery, $syncedById);
        
        $treeWrapper = $modules->get('InputfieldMarkup');
        $treeWrapper->value = $treeHtml;
        $treeWrapper->label = $this->_('Site Pages');
        $treeWrapper->icon = 'sitemap';
        $form->add($treeWrapper);
        
        // =====================================================================
        // HEADER BUTTONS
        // =====================================================================
        
        // Refresh button
        $btn = $modules->get('InputfieldButton');
        $btn->attr('name', 'refresh');
        $btn->value = $this->_('Refresh');
        $btn->icon = 'refresh';
        $btn->href = './';
        $btn->showInHeader(true);
        $form->add($btn);
        
        // Reconcile/Tools
        $btn = $modules->get('InputfieldButton');
        $btn->attr('name', 'tools');
        $btn->value = $this->_('Reconcile');
        $btn->icon = 'wrench';
        $btn->href = './reconcile/';
        $btn->setSecondary(true);
        $btn->showInHeader(true);
        $form->add($btn);
        
        // Add JavaScript for tree interactions
        $form->appendMarkup = $this->getTreeScript();
        
        return $form->render();
    }
    
    /**
     * Build sync status lookup by page ID
     * 
     * @param array $statusData
     * @return array
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
     * Build the page tree HTML
     * 
     * @param string $templateFilter
     * @param string $statusFilter
     * @param string $searchQuery
     * @param array $syncedById
     * @return string
     */
    protected function buildPageTree(
        string $templateFilter, 
        string $statusFilter, 
        string $searchQuery,
        array $syncedById
    ): string {
        $html = '<table class="AdminDataTable AdminDataList AdminDataTableSortable pwmcp-tree-table" style="width:100%;">';
        $html .= '<thead><tr>';
        $html .= '<th style="width:30px;"><input type="checkbox" class="pwmcp-select-all" title="' . $this->_('Select all') . '"></th>';
        $html .= '<th>' . $this->_('Page') . '</th>';
        $html .= '<th style="width:120px;">' . $this->_('Template') . '</th>';
        $html .= '<th style="width:100px;">' . $this->_('Status') . '</th>';
        $html .= '<th style="width:100px;">' . $this->_('Modified') . '</th>';
        $html .= '<th style="width:100px;">' . $this->_('Pulled') . '</th>';
        $html .= '<th style="width:120px;">' . $this->_('Actions') . '</th>';
        $html .= '</tr></thead><tbody>';
        
        // Get top-level pages (children of home)
        $homePage = $this->wire('pages')->get('/');
        $topPages = $homePage->children('include=hidden, sort=sort');
        
        $pageCount = 0;
        foreach ($topPages as $page) {
            if ($page->template->flags & Template::flagSystem) continue;
            $html .= $this->buildTreeRow($page, 0, $templateFilter, $statusFilter, $searchQuery, $syncedById, $pageCount);
        }
        
        $html .= '</tbody></table>';
        
        if ($pageCount === 0) {
            $html = '<p class="notes">' . $this->_('No pages found matching your filters.') . '</p>';
        }
        
        return $html;
    }
    
    /**
     * Build a single tree row and its children recursively
     * 
     * @param Page $page
     * @param int $depth
     * @param string $templateFilter
     * @param string $statusFilter
     * @param string $searchQuery
     * @param array $syncedById
     * @param int &$pageCount
     * @return string
     */
    protected function buildTreeRow(
        Page $page,
        int $depth,
        string $templateFilter,
        string $statusFilter,
        string $searchQuery,
        array $syncedById,
        int &$pageCount
    ): string {
        // Skip system templates
        if ($page->template->flags & Template::flagSystem) {
            return '';
        }
        
        // Get sync status for this page
        $syncInfo = $syncedById[$page->id] ?? null;
        $status = $syncInfo ? ($syncInfo['status'] ?? 'notPulled') : 'notPulled';
        $pulledAt = $syncInfo['pulledAt'] ?? null;
        
        // Apply filters
        if ($templateFilter && $page->template->name !== $templateFilter) {
            // Still check children
            $childHtml = '';
            foreach ($page->children('include=hidden, sort=sort') as $child) {
                $childHtml .= $this->buildTreeRow($child, $depth + 1, $templateFilter, $statusFilter, $searchQuery, $syncedById, $pageCount);
            }
            return $childHtml;
        }
        
        if ($statusFilter && $status !== $statusFilter) {
            $childHtml = '';
            foreach ($page->children('include=hidden, sort=sort') as $child) {
                $childHtml .= $this->buildTreeRow($child, $depth + 1, $templateFilter, $statusFilter, $searchQuery, $syncedById, $pageCount);
            }
            return $childHtml;
        }
        
        if ($searchQuery && stripos($page->title, $searchQuery) === false && stripos($page->name, $searchQuery) === false) {
            $childHtml = '';
            foreach ($page->children('include=hidden, sort=sort') as $child) {
                $childHtml .= $this->buildTreeRow($child, $depth + 1, $templateFilter, $statusFilter, $searchQuery, $syncedById, $pageCount);
            }
            return $childHtml;
        }
        
        $pageCount++;
        $hasChildren = $page->numChildren > 0;
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
        
        // Build row
        $html = '<tr class="pwmcp-tree-row" data-page-id="' . $page->id . '" data-depth="' . $depth . '">';
        
        // Checkbox
        $html .= '<td><input type="checkbox" name="selected_pages[]" value="' . $page->id . '" class="pwmcp-page-checkbox"></td>';
        
        // Page title with expand/collapse
        $expandIcon = $hasChildren ? '<i class="fa fa-caret-right pwmcp-toggle" style="cursor:pointer; margin-right:5px;"></i>' : '<span style="display:inline-block;width:14px;"></span>';
        $pageIcon = $hasChildren ? 'fa-folder' : 'fa-file-o';
        $html .= '<td>' . $indent . $expandIcon . '<i class="fa ' . $pageIcon . '" style="margin-right:5px;"></i>';
        $html .= '<a href="' . $page->editUrl . '" title="' . htmlspecialchars($page->path) . '">' . htmlspecialchars($page->title) . '</a>';
        $html .= '</td>';
        
        // Template
        $html .= '<td><small>' . $page->template->name . '</small></td>';
        
        // Status badge
        $html .= '<td>' . $this->getStatusBadge($status) . '</td>';
        
        // Modified date
        $modifiedDate = $page->modified ? wireRelativeTimeStr($page->modified) : '-';
        $html .= '<td><small>' . $modifiedDate . '</small></td>';
        
        // Pulled date
        $pulledDate = $pulledAt ? wireRelativeTimeStr(strtotime($pulledAt)) : '-';
        $html .= '<td><small>' . $pulledDate . '</small></td>';
        
        // Actions
        $html .= '<td>' . $this->getRowActions($page, $status) . '</td>';
        
        $html .= '</tr>';
        
        // Build children rows (initially hidden for depth > 0)
        if ($hasChildren) {
            foreach ($page->children('include=hidden, sort=sort') as $child) {
                $html .= $this->buildTreeRow($child, $depth + 1, $templateFilter, $statusFilter, $searchQuery, $syncedById, $pageCount);
            }
        }
        
        return $html;
    }
    
    /**
     * Get JavaScript for tree interactions
     * 
     * @return string
     */
    protected function getTreeScript(): string {
        return <<<'HTML'
<style>
.pwmcp-tree-table tr[data-depth="1"],
.pwmcp-tree-table tr[data-depth="2"],
.pwmcp-tree-table tr[data-depth="3"],
.pwmcp-tree-table tr[data-depth="4"],
.pwmcp-tree-table tr[data-depth="5"] {
    display: none;
}
.pwmcp-tree-table tr.expanded + tr[data-depth] {
    /* Children of expanded rows shown via JS */
}
.pwmcp-toggle.expanded {
    transform: rotate(90deg);
}
.pwmcp-tree-table .uk-label {
    font-size: 11px;
    padding: 2px 6px;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle expand/collapse
    document.querySelectorAll('.pwmcp-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            var row = this.closest('tr');
            var depth = parseInt(row.dataset.depth);
            var isExpanded = this.classList.contains('expanded');
            
            this.classList.toggle('expanded');
            
            // Find all following rows until we hit same or lower depth
            var sibling = row.nextElementSibling;
            while (sibling && parseInt(sibling.dataset.depth) > depth) {
                if (parseInt(sibling.dataset.depth) === depth + 1) {
                    sibling.style.display = isExpanded ? 'none' : '';
                    // Collapse children when parent collapses
                    if (isExpanded) {
                        var childToggle = sibling.querySelector('.pwmcp-toggle');
                        if (childToggle) childToggle.classList.remove('expanded');
                    }
                } else if (isExpanded) {
                    sibling.style.display = 'none';
                }
                sibling = sibling.nextElementSibling;
            }
        });
    });
    
    // Select all checkbox
    document.querySelector('.pwmcp-select-all')?.addEventListener('change', function() {
        var checked = this.checked;
        document.querySelectorAll('.pwmcp-page-checkbox').forEach(function(cb) {
            if (cb.closest('tr').style.display !== 'none') {
                cb.checked = checked;
            }
        });
        updateSelectedCount();
    });
    
    // Individual checkboxes
    document.querySelectorAll('.pwmcp-page-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateSelectedCount);
    });
    
    function updateSelectedCount() {
        var count = document.querySelectorAll('.pwmcp-page-checkbox:checked').length;
        var label = count === 1 ? ' page selected' : ' pages selected';
        document.querySelector('.pwmcp-selected-count').textContent = count + label;
    }
});
</script>
HTML;
    }
    
    /**
     * Handle bulk actions from checkbox selection
     * 
     * @param string $action
     * @param array $pageIds
     * @return string
     */
    protected function handleBulkAction(string $action, array $pageIds): string {
        $modules = $this->wire('modules');
        $syncManager = $this->getSyncManager();
        
        $pageIds = array_map('intval', $pageIds);
        $pageIds = array_filter($pageIds);
        
        if (empty($pageIds)) {
            $this->error($this->_('No pages selected'));
            $this->wire('session')->redirect('./');
            return '';
        }
        
        if ($action === 'pull') {
            $pulled = 0;
            $errors = [];
            foreach ($pageIds as $pageId) {
                $result = $syncManager->pullPage($pageId);
                if (isset($result['success']) && $result['success']) {
                    $pulled++;
                } else {
                    $errors[] = $result['error'] ?? "Failed to pull page $pageId";
                }
            }
            $this->message(sprintf($this->_('Pulled %d pages'), $pulled));
            foreach ($errors as $err) {
                $this->error($err);
            }
        } elseif ($action === 'push') {
            $pushed = 0;
            $errors = [];
            foreach ($pageIds as $pageId) {
                $page = $this->wire('pages')->get($pageId);
                if (!$page->id) continue;
                $localPath = $this->getWorkspaceRoot() . ltrim($page->path, '/');
                $result = $syncManager->pushPage($localPath, false);
                if (isset($result['success']) && $result['success']) {
                    $pushed++;
                } else {
                    $errors[] = $result['error'] ?? "Failed to push page $pageId";
                }
            }
            $this->message(sprintf($this->_('Pushed %d pages'), $pushed));
            foreach ($errors as $err) {
                $this->error($err);
            }
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
