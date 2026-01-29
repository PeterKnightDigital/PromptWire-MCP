<?php
namespace PwMcp\Schema;

/**
 * Exports template definitions with stable, consistent naming
 */
class TemplateExporter {
    
    private $wire;
    
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Export all templates as schema
     */
    public function export(): array {
        $templates = [];
        
        foreach ($this->wire->templates as $template) {
            // Skip system templates
            if ($template->flags & \ProcessWire\Template::flagSystem) {
                continue;
            }
            
            $templates[$template->name] = $this->exportTemplate($template);
        }
        
        // Sort by template name for stable diffs
        ksort($templates);
        
        return $templates;
    }
    
    /**
     * Export a single template definition
     */
    public function exportTemplate($template): array {
        // Get field names in order
        $fields = [];
        foreach ($template->fields as $field) {
            $fields[] = $field->name;
        }
        
        $data = [
            'label' => $template->label ?: null,
            'fields' => $fields,
        ];
        
        // Family settings
        $family = [];
        
        if ($template->noChildren) {
            $family['allowChildren'] = false;
        }
        
        if ($template->noParents) {
            $family['allowParents'] = false;
        }
        
        if (!empty($template->childTemplates)) {
            $family['childTemplates'] = $this->getTemplateNames($template->childTemplates);
        }
        
        if (!empty($template->parentTemplates)) {
            $family['parentTemplates'] = $this->getTemplateNames($template->parentTemplates);
        }
        
        if ($template->allowPageNum) {
            $family['allowPageNum'] = true;
        }
        
        if ($template->urlSegments) {
            $family['urlSegments'] = true;
        }
        
        if (!empty($family)) {
            $data['family'] = $family;
        }
        
        // Access settings
        if ($template->useRoles) {
            $data['access'] = [
                'useRoles' => true,
                'roles' => $this->getTemplateRoles($template),
            ];
        }
        
        // Cache settings
        if ($template->cache_time) {
            $data['cache'] = [
                'time' => (int) $template->cache_time,
            ];
        }
        
        // Template file (if different from name)
        if ($template->altFilename) {
            $data['filename'] = $template->altFilename;
        }
        
        return $data;
    }
    
    /**
     * Convert template IDs to names
     */
    private function getTemplateNames(array $ids): array {
        $names = [];
        foreach ($ids as $id) {
            $template = $this->wire->templates->get($id);
            if ($template) {
                $names[] = $template->name;
            }
        }
        sort($names);
        return $names;
    }
    
    /**
     * Get roles that have access to template
     */
    private function getTemplateRoles($template): array {
        $roles = [];
        
        // Get view roles
        $viewRoles = [];
        foreach ($this->wire->roles as $role) {
            if ($template->hasRole($role, 'view')) {
                $viewRoles[] = $role->name;
            }
        }
        if (!empty($viewRoles)) {
            sort($viewRoles);
            $roles['view'] = $viewRoles;
        }
        
        // Get edit roles
        $editRoles = [];
        foreach ($this->wire->roles as $role) {
            if ($template->hasRole($role, 'edit')) {
                $editRoles[] = $role->name;
            }
        }
        if (!empty($editRoles)) {
            sort($editRoles);
            $roles['edit'] = $editRoles;
        }
        
        // Get create roles
        $createRoles = [];
        foreach ($this->wire->roles as $role) {
            if ($template->hasRole($role, 'create')) {
                $createRoles[] = $role->name;
            }
        }
        if (!empty($createRoles)) {
            sort($createRoles);
            $roles['create'] = $createRoles;
        }
        
        return $roles;
    }
}
