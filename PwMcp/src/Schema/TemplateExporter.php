<?php
/**
 * PW-MCP Template Schema Exporter
 * 
 * Exports ProcessWire template definitions in a stable, consistent format
 * suitable for schema documentation and cross-site comparison.
 * 
 * @package     PwMcp
 * @subpackage  Schema
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

namespace PwMcp\Schema;

/**
 * Exports template definitions with stable, consistent naming
 * 
 * This class extracts template configuration from ProcessWire and formats
 * it in a way that:
 * - Lists fields in their template order
 * - Includes family settings (parent/child relationships)
 * - Includes access control settings
 * - Uses template names instead of IDs for portability
 * - Sorts output alphabetically for clean diffs
 * - Excludes system templates
 */
class TemplateExporter {
    
    /**
     * ProcessWire instance
     * 
     * @var \ProcessWire\ProcessWire
     */
    private $wire;
    
    /**
     * Create a new TemplateExporter
     * 
     * @param \ProcessWire\ProcessWire $wire ProcessWire instance
     */
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Export all non-system templates as a schema array
     * 
     * Returns an associative array keyed by template name, with each
     * value containing the template's fields, family settings, and
     * access configuration.
     * 
     * Output is sorted alphabetically by template name for stable diffs.
     * 
     * @return array Associative array of template definitions keyed by name
     */
    public function export(): array {
        $templates = [];
        
        foreach ($this->wire->templates as $template) {
            // Skip ProcessWire system templates (admin, user, role, etc.)
            if ($template->flags & \ProcessWire\Template::flagSystem) {
                continue;
            }
            
            $templates[$template->name] = $this->exportTemplate($template);
        }
        
        // Sort alphabetically for stable, predictable output
        ksort($templates);
        
        return $templates;
    }
    
    /**
     * Export a single template's definition
     * 
     * Extracts the template's configuration including:
     * - Field list (in template order)
     * - Family settings (parent/child rules)
     * - Access control (roles)
     * - Cache settings
     * - Alternative filename (if set)
     * 
     * @param \ProcessWire\Template $template Template to export
     * @return array Template definition array
     */
    public function exportTemplate($template): array {
        // Get field names in the order they appear in the template
        $fields = [];
        foreach ($template->fields as $field) {
            $fields[] = $field->name;
        }
        
        // Build base template data
        $data = [
            'label' => $template->label ?: null,
            'fields' => $fields,
        ];
        
        // ====================================================================
        // FAMILY SETTINGS
        // ====================================================================
        // These control parent/child page relationships
        $family = [];
        
        if ($template->noChildren) {
            $family['allowChildren'] = false;
        }
        
        if ($template->noParents) {
            $family['allowParents'] = false;
        }
        
        // Allowed child templates (as names, not IDs)
        if (!empty($template->childTemplates)) {
            $family['childTemplates'] = $this->getTemplateNames($template->childTemplates);
        }
        
        // Allowed parent templates (as names, not IDs)
        if (!empty($template->parentTemplates)) {
            $family['parentTemplates'] = $this->getTemplateNames($template->parentTemplates);
        }
        
        // Pagination support
        if ($template->allowPageNum) {
            $family['allowPageNum'] = true;
        }
        
        // URL segment support
        if ($template->urlSegments) {
            $family['urlSegments'] = true;
        }
        
        if (!empty($family)) {
            $data['family'] = $family;
        }
        
        // ====================================================================
        // ACCESS CONTROL
        // ====================================================================
        if ($template->useRoles) {
            $data['access'] = [
                'useRoles' => true,
                'roles' => $this->getTemplateRoles($template),
            ];
        }
        
        // ====================================================================
        // CACHE SETTINGS
        // ====================================================================
        if ($template->cache_time) {
            $data['cache'] = [
                'time' => (int) $template->cache_time,
            ];
        }
        
        // ====================================================================
        // ALTERNATIVE TEMPLATE FILE
        // ====================================================================
        // If template uses a different PHP file than its name
        if ($template->altFilename) {
            $data['filename'] = $template->altFilename;
        }
        
        return $data;
    }
    
    /**
     * Convert template IDs to template names
     * 
     * ProcessWire stores template relationships as IDs, but for
     * portability we convert these to names in the schema output.
     * 
     * @param array $ids Array of template IDs
     * @return array Array of template names (sorted alphabetically)
     */
    private function getTemplateNames(array $ids): array {
        $names = [];
        foreach ($ids as $id) {
            $template = $this->wire->templates->get($id);
            if ($template) {
                $names[] = $template->name;
            }
        }
        // Sort for consistent output
        sort($names);
        return $names;
    }
    
    /**
     * Get roles that have access to a template
     * 
     * Returns roles organized by permission type (view, edit, create).
     * Only includes permission types that have roles assigned.
     * 
     * @param \ProcessWire\Template $template Template to check
     * @return array Roles organized by permission type
     */
    private function getTemplateRoles($template): array {
        $roles = [];
        
        // Get roles with view permission
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
        
        // Get roles with edit permission
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
        
        // Get roles with create (add children) permission
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
