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
     * Main execute - show status table with filters
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
        
        // Get filter values from input or session
        $rootPath = $input->get('root_path') ?: $session->get('pwmcp_root_path') ?: '';
        $templateFilter = $input->get('template') ?: $session->get('pwmcp_template') ?: '';
        $statusFilter = $input->get('status') ?: $session->get('pwmcp_status') ?: '';
        $searchQuery = $input->get('q') ?: $session->get('pwmcp_search') ?: '';
        
        // Save filters to session
        $session->set('pwmcp_root_path', $rootPath);
        $session->set('pwmcp_template', $templateFilter);
        $session->set('pwmcp_status', $statusFilter);
        $session->set('pwmcp_search', $searchQuery);
        
        // Build the form
        $form = $modules->get('InputfieldForm');
        $form->attr('id', 'pwmcp-sync-form');
        $form->attr('method', 'get');
        
        // =====================================================================
        // FILTER CONTROLS
        // =====================================================================
        
        $filterFieldset = $modules->get('InputfieldFieldset');
        $filterFieldset->label = $this->_('Filter Pages');
        $filterFieldset->collapsed = Inputfield::collapsedNo;
        $filterFieldset->icon = 'filter';
        
        // Root path filter
        $f = $modules->get('InputfieldPageListSelect');
        $f->attr('name', 'root_path');
        $f->label = $this->_('Root Path');
        $f->description = $this->_('Select a parent page to filter (e.g., /services/)');
        $f->attr('value', $rootPath ? $this->wire('pages')->get($rootPath)->id : 0);
        $f->columnWidth = 25;
        $f->collapsed = Inputfield::collapsedNever;
        $filterFieldset->add($f);
        
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
        $f->columnWidth = 25;
        $filterFieldset->add($f);
        
        // Status filter
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'status');
        $f->label = $this->_('Status');
        $f->addOption('', $this->_('All Statuses'));
        $f->addOption('clean', $this->_('Clean'));
        $f->addOption('localDirty', $this->_('Local Changes'));
        $f->addOption('remoteChanged', $this->_('Remote Changes'));
        $f->addOption('conflict', $this->_('Conflict'));
        $f->addOption('orphan', $this->_('Orphan'));
        $f->addOption('notPulled', $this->_('Not Pulled'));
        $f->attr('value', $statusFilter);
        $f->columnWidth = 25;
        $filterFieldset->add($f);
        
        // Search
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'q');
        $f->label = $this->_('Search');
        $f->attr('placeholder', $this->_('Search titles or paths...'));
        $f->attr('value', $searchQuery);
        $f->columnWidth = 25;
        $filterFieldset->add($f);
        
        $form->add($filterFieldset);
        
        // Filter button
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'submit_filter');
        $f->value = $this->_('Apply Filter');
        $f->icon = 'search';
        $f->addClass('uk-margin-bottom');
        $form->add($f);
        
        // =====================================================================
        // STATUS TABLE
        // =====================================================================
        
        // Get sync status
        $syncManager = $this->getSyncManager();
        $statusData = $syncManager->getSyncStatus();
        
        // Build the status table
        $table = $modules->get('MarkupAdminDataTable');
        $table->setEncodeEntities(false);
        $table->setSortable(true);
        $table->setResizable(true);
        $table->addClass('pwmcp-status-table');
        
        // Header row
        $table->headerRow([
            $this->_('Page'),
            $this->_('Path'),
            $this->_('Template'),
            $this->_('Status'),
            $this->_('Modified (PW)'),
            $this->_('Last Pulled'),
            $this->_('Actions'),
        ]);
        
        // Get pages to display
        $pages = $this->getPagesForDisplay($rootPath, $templateFilter, $statusFilter, $searchQuery, $statusData);
        
        if (empty($pages)) {
            // No pages message
            $form->appendMarkup = '<p class="notes">' . 
                $this->_('No pages found. Select a root path or template filter to view pages.') . 
                '</p>';
        } else {
            foreach ($pages as $pageData) {
                $table->row($this->buildTableRow($pageData));
            }
        }
        
        // Add table to form
        $tableWrapper = $modules->get('InputfieldMarkup');
        $tableWrapper->value = $table->render();
        $tableWrapper->label = $this->_('Synced Pages') . ' (' . count($pages) . ')';
        $tableWrapper->icon = 'files-o';
        $form->add($tableWrapper);
        
        // =====================================================================
        // HEADER BUTTONS
        // =====================================================================
        
        // Refresh button
        $btn = $modules->get('InputfieldButton');
        $btn->attr('name', 'refresh');
        $btn->value = $this->_('Refresh Status');
        $btn->icon = 'refresh';
        $btn->href = './';
        $btn->showInHeader(true);
        $form->add($btn);
        
        // Pull All button
        $btn = $modules->get('InputfieldButton');
        $btn->attr('name', 'pull_all');
        $btn->value = $this->_('Pull (Export)');
        $btn->icon = 'download';
        $btn->href = './pull-all/';
        $btn->setSecondary(true);
        $btn->showInHeader(true);
        $form->add($btn);
        
        // Push All button  
        $btn = $modules->get('InputfieldButton');
        $btn->attr('name', 'push_all');
        $btn->value = $this->_('Push (Import)');
        $btn->icon = 'upload';
        $btn->href = './push-all/';
        $btn->setSecondary(true);
        $btn->showInHeader(true);
        $form->add($btn);
        
        // Tools dropdown
        $btn = $modules->get('InputfieldButton');
        $btn->attr('name', 'tools');
        $btn->value = $this->_('Tools');
        $btn->icon = 'wrench';
        $btn->href = './reconcile/';
        $btn->setSecondary(true);
        $btn->showInHeader(true);
        $form->add($btn);
        
        return $form->render();
    }

    /**
     * Get pages for display based on filters
     * 
     * @param string $rootPath Root page path filter
     * @param string $templateFilter Template filter
     * @param string $statusFilter Status filter
     * @param string $searchQuery Search query
     * @param array $statusData Sync status data
     * @return array Pages with status info
     */
    protected function getPagesForDisplay(
        string $rootPath, 
        string $templateFilter, 
        string $statusFilter, 
        string $searchQuery,
        array $statusData
    ): array {
        $results = [];
        
        // Build selector
        $selector = 'include=all';
        
        if ($rootPath) {
            $rootPage = $this->wire('pages')->get($rootPath);
            if ($rootPage && $rootPage->id) {
                $selector .= ", has_parent={$rootPage->id}|id={$rootPage->id}";
            }
        }
        
        if ($templateFilter) {
            $selector .= ", template=$templateFilter";
        }
        
        if ($searchQuery) {
            $selector .= ", title|name%=$searchQuery";
        }
        
        // Limit results for performance
        $selector .= ', limit=100';
        
        // Don't query if no filters set (avoid loading entire site)
        if (!$rootPath && !$templateFilter && !$searchQuery) {
            return [];
        }
        
        $pages = $this->wire('pages')->find($selector);
        
        // Build lookup of synced pages by ID
        $syncedById = [];
        if (isset($statusData['pages']) && is_array($statusData['pages'])) {
            foreach ($statusData['pages'] as $pageStatus) {
                if (isset($pageStatus['pageId'])) {
                    $syncedById[$pageStatus['pageId']] = $pageStatus;
                }
            }
        }
        
        foreach ($pages as $page) {
            // Skip system pages
            if ($page->template->flags & Template::flagSystem) continue;
            
            // Get sync status for this page
            $status = $syncedById[$page->id] ?? null;
            $statusName = $status ? ($status['status'] ?? 'notPulled') : 'notPulled';
            
            // Apply status filter
            if ($statusFilter && $statusName !== $statusFilter) {
                continue;
            }
            
            $results[] = [
                'page' => $page,
                'status' => $statusName,
                'pulledAt' => $status['pulledAt'] ?? null,
                'localChanges' => $status['localChanges'] ?? 0,
            ];
        }
        
        return $results;
    }

    /**
     * Build a table row for a page
     * 
     * @param array $pageData Page data with status
     * @return array Table row data
     */
    protected function buildTableRow(array $pageData): array {
        $page = $pageData['page'];
        $status = $pageData['status'];
        $pulledAt = $pageData['pulledAt'];
        
        // Status badge
        $statusBadge = $this->getStatusBadge($status);
        
        // Actions
        $actions = $this->getRowActions($page, $status);
        
        // Format dates
        $modifiedDate = $page->modified ? wireRelativeTimeStr($page->modified) : '-';
        $pulledDate = $pulledAt ? wireRelativeTimeStr(strtotime($pulledAt)) : '-';
        
        return [
            // Page title links to PW edit page
            "<a href='{$page->editUrl}'>{$page->title}</a>",
            $page->path,
            $page->template->name,
            $statusBadge,
            $modifiedDate,
            $pulledDate,
            $actions,
        ];
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
        
        // Build selector
        $selector = '';
        if ($rootPath) {
            $selector = $rootPath;
        } elseif ($templateFilter) {
            $selector = "template=$templateFilter";
        }
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        
        $info = $modules->get('InputfieldMarkup');
        $info->label = $this->_('Bulk Pull Preview');
        $info->value = '<p>' . sprintf(
            $this->_('This will pull all pages matching: %s'),
            '<strong>' . htmlspecialchars($selector) . '</strong>'
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
        
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        
        $syncManager = $this->getSyncManager();
        
        if ($input->post('confirm_push')) {
            // Execute bulk push
            $result = $syncManager->pushPages($this->getWorkspaceRoot(), false); // dryRun = false
            
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
        
        // Dry-run preview
        $result = $syncManager->pushPages($this->getWorkspaceRoot(), true); // dryRun = true
        
        $info = $modules->get('InputfieldMarkup');
        $info->label = $this->_('Bulk Push Preview');
        
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
