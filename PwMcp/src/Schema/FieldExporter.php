<?php
namespace PwMcp\Schema;

/**
 * Exports field definitions with stable, consistent naming
 */
class FieldExporter {
    
    private $wire;
    
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Export all fields as schema
     */
    public function export(): array {
        $fields = [];
        
        foreach ($this->wire->fields as $field) {
            // Skip system fields
            if ($field->flags & \ProcessWire\Field::flagSystem) {
                continue;
            }
            
            $fields[$field->name] = $this->exportField($field);
        }
        
        // Sort by field name for stable diffs
        ksort($fields);
        
        return $fields;
    }
    
    /**
     * Export a single field definition
     */
    public function exportField($field): array {
        // Get inputfield class - use stable naming
        $inputfieldClass = $field->get('inputfieldClass');
        if (!$inputfieldClass) {
            $inputfield = $field->getInputfield(new \ProcessWire\NullPage());
            $inputfieldClass = $inputfield ? $inputfield->className() : null;
        }
        
        $data = [
            'type' => $field->type->className(),  // e.g., "FieldtypeText"
            'inputfield' => $inputfieldClass,      // e.g., "InputfieldText"
            'label' => $field->label ?: null,
        ];
        
        // Add optional properties if set
        if ($field->description) {
            $data['description'] = $field->description;
        }
        
        if ($field->required) {
            $data['required'] = true;
        }
        
        // Add type-specific settings
        $settings = $this->getTypeSettings($field);
        if (!empty($settings)) {
            $data['settings'] = $settings;
        }
        
        return $data;
    }
    
    /**
     * Get type-specific settings for a field
     */
    private function getTypeSettings($field): array {
        $settings = [];
        $type = $field->type->className();
        
        // Text fields
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
        
        // Textarea specific
        if ($type === 'FieldtypeTextarea') {
            if ($field->rows) {
                $settings['rows'] = (int) $field->rows;
            }
            if ($field->contentType) {
                $settings['contentType'] = $field->contentType;
            }
        }
        
        // Number fields
        if (in_array($type, ['FieldtypeInteger', 'FieldtypeFloat', 'FieldtypeDecimal'])) {
            if ($field->min !== null && $field->min !== '') {
                $settings['min'] = $field->min;
            }
            if ($field->max !== null && $field->max !== '') {
                $settings['max'] = $field->max;
            }
        }
        
        // Page reference
        if (in_array($type, ['FieldtypePage', 'FieldtypePageIDs'])) {
            if ($field->parent_id) {
                $settings['parent'] = $this->wire->pages->get($field->parent_id)->path;
            }
            if ($field->template_id) {
                $template = $this->wire->templates->get($field->template_id);
                if ($template) {
                    $settings['template'] = $template->name;
                }
            }
            if ($field->findPagesSelector) {
                $settings['selector'] = $field->findPagesSelector;
            }
            if ($field->derefAsPage !== null) {
                $settings['derefAsPage'] = (bool) $field->derefAsPage;
            }
        }
        
        // Options field
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
        
        // Image/File fields
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
        
        // Repeater fields
        if (in_array($type, ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix'])) {
            if ($field->repeaterFields) {
                $settings['repeaterFields'] = $field->repeaterFields;
            }
        }
        
        return $settings;
    }
}
