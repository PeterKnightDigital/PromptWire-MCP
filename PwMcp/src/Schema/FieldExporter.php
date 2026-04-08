<?php
/**
 * PW-MCP Field Schema Exporter
 * 
 * Exports ProcessWire field definitions in a stable, consistent format
 * suitable for schema documentation and cross-site comparison.
 * 
 * @package     PwMcp
 * @subpackage  Schema
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

namespace PwMcp\Schema;

/**
 * Exports field definitions with stable, consistent naming
 * 
 * This class extracts field configuration from ProcessWire and formats
 * it in a way that:
 * - Uses stable class names (e.g., "FieldtypeText" not "text")
 * - Includes type-specific settings relevant to each field type
 * - Sorts output alphabetically for clean diffs
 * - Excludes system fields
 * 
 * The output format is designed to be:
 * - Human-readable when converted to YAML
 * - Portable across ProcessWire installations
 * - Suitable for version control
 */
class FieldExporter {
    
    /**
     * ProcessWire instance
     * 
     * @var \ProcessWire\ProcessWire
     */
    private $wire;
    
    /**
     * Create a new FieldExporter
     * 
     * @param \ProcessWire\ProcessWire $wire ProcessWire instance
     */
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Export all non-system fields as a schema array
     * 
     * Returns an associative array keyed by field name, with each
     * value containing the field's type, inputfield, label, and
     * type-specific settings.
     * 
     * Output is sorted alphabetically by field name for stable diffs.
     * 
     * @return array Associative array of field definitions keyed by name
     */
    public function export(): array {
        $fields = [];
        
        foreach ($this->wire->fields as $field) {
            // Skip ProcessWire system fields (title is system but useful)
            if ($field->flags & \ProcessWire\Field::flagSystem) {
                continue;
            }
            
            $fields[$field->name] = $this->exportField($field);
        }
        
        // Sort alphabetically for stable, predictable output
        ksort($fields);
        
        return $fields;
    }
    
    /**
     * Export a single field's definition
     * 
     * Extracts the field's configuration in a format that uses stable
     * class names and includes all relevant settings.
     * 
     * @param \ProcessWire\Field $field Field to export
     * @return array Field definition array
     */
    public function exportField($field): array {
        // Get inputfield class using stable naming
        // Prefer explicitly set inputfieldClass, fall back to detecting from field
        $inputfieldClass = $field->get('inputfieldClass');
        if (!$inputfieldClass) {
            $inputfield = $field->getInputfield(new \ProcessWire\NullPage());
            $inputfieldClass = $inputfield ? $inputfield->className() : null;
        }
        
        // Build base field data
        $data = [
            'type' => $field->type->className(),       // e.g., "FieldtypeText"
            'inputfield' => $inputfieldClass,          // e.g., "InputfieldText"
            'label' => $field->label ?: null,
        ];
        
        // Add optional properties only if they have values
        if ($field->description) {
            $data['description'] = $field->description;
        }
        
        if ($field->required) {
            $data['required'] = true;
        }
        
        // Add type-specific settings (maxlength, options, etc.)
        $settings = $this->getTypeSettings($field);
        if (!empty($settings)) {
            $data['settings'] = $settings;
        }
        
        return $data;
    }
    
    /**
     * Get type-specific settings for a field
     * 
     * Different field types have different relevant settings. This method
     * extracts the settings that are meaningful for each field type.
     * 
     * Supported field types:
     * - Text fields: maxlength, minlength, pattern, placeholder
     * - Textarea: rows, contentType
     * - Number fields: min, max
     * - Page reference: parent, template, selector, derefAsPage
     * - Options: available options
     * - Image/File: maxFiles, extensions, maxFilesize
     * - Repeater: repeaterFields
     * 
     * @param \ProcessWire\Field $field Field to extract settings from
     * @return array Type-specific settings (empty if none apply)
     */
    private function getTypeSettings($field): array {
        $settings = [];
        $type = $field->type->className();
        
        // ====================================================================
        // TEXT FIELDS
        // ====================================================================
        if (in_array($type, ['FieldtypeText', 'FieldtypeTextarea', 'FieldtypePageTitle', 'FieldtypeEmail', 'FieldtypeURL'])) {
            if ($field->maxlength) {
                $settings['maxlength'] = (int) $field->maxlength;
            }
            if ($field->minlength) {
                $settings['minlength'] = (int) $field->minlength;
            }
            if ($field->pattern) {
                $settings['pattern'] = $field->pattern;
            }
            if ($field->placeholder) {
                $settings['placeholder'] = $field->placeholder;
            }
        }
        
        // ====================================================================
        // TEXTAREA SPECIFIC
        // ====================================================================
        if ($type === 'FieldtypeTextarea') {
            if ($field->rows) {
                $settings['rows'] = (int) $field->rows;
            }
            if ($field->contentType) {
                $settings['contentType'] = $field->contentType;
            }
        }
        
        // ====================================================================
        // NUMBER FIELDS
        // ====================================================================
        if (in_array($type, ['FieldtypeInteger', 'FieldtypeFloat', 'FieldtypeDecimal'])) {
            if ($field->min !== null && $field->min !== '') {
                $settings['min'] = $field->min;
            }
            if ($field->max !== null && $field->max !== '') {
                $settings['max'] = $field->max;
            }
        }
        
        // ====================================================================
        // PAGE REFERENCE FIELDS
        // ====================================================================
        if (in_array($type, ['FieldtypePage', 'FieldtypePageIDs'])) {
            // Parent page constraint (as path, not ID for portability)
            if ($field->parent_id) {
                $settings['parent'] = $this->wire->pages->get($field->parent_id)->path;
            }
            // Template constraint (as name, not ID for portability)
            if ($field->template_id) {
                $template = $this->wire->templates->get($field->template_id);
                if ($template) {
                    $settings['template'] = $template->name;
                }
            }
            // Custom selector for finding pages
            if ($field->findPagesSelector) {
                $settings['selector'] = $field->findPagesSelector;
            }
            // Whether to return single Page vs PageArray
            if ($field->derefAsPage !== null) {
                $settings['derefAsPage'] = (bool) $field->derefAsPage;
            }
        }
        
        // ====================================================================
        // OPTIONS FIELD
        // ====================================================================
        if ($type === 'FieldtypeOptions') {
            $options = [];
            if ($field->type && method_exists($field->type, 'getOptions')) {
                foreach ($field->type->getOptions($field) as $option) {
                    $options[] = [
                        'value' => $option->value,
                        'title' => $option->title,
                    ];
                }
            }
            if (!empty($options)) {
                $settings['options'] = $options;
            }
        }
        
        // ====================================================================
        // IMAGE AND FILE FIELDS
        // ====================================================================
        if (in_array($type, ['FieldtypeImage', 'FieldtypeFile', 'FieldtypeCroppableImage3'])) {
            if ($field->maxFiles) {
                $settings['maxFiles'] = (int) $field->maxFiles;
            }
            if ($field->extensions) {
                $settings['extensions'] = $field->extensions;
            }
            if ($field->maxFilesize) {
                $settings['maxFilesize'] = (int) $field->maxFilesize;
            }
        }
        
        // ====================================================================
        // REPEATER FIELDS
        // ====================================================================
        if (in_array($type, ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix'])) {
            if ($field->repeaterFields) {
                $settings['repeaterFields'] = $field->repeaterFields;
            }
        }
        
        return $settings;
    }
}
