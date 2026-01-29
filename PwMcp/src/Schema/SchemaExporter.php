<?php
namespace PwMcp\Schema;

/**
 * Exports complete site schema (fields + templates)
 */
class SchemaExporter {
    
    private $wire;
    
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Export complete schema
     */
    public function export(): array {
        $fieldExporter = new FieldExporter($this->wire);
        $templateExporter = new TemplateExporter($this->wire);
        
        return [
            'meta' => [
                'exportedAt' => date('c'),
                'pwVersion' => $this->wire->config->version,
                'siteName' => $this->wire->config->httpHost ?: basename($this->wire->config->paths->root),
            ],
            'fields' => $fieldExporter->export(),
            'templates' => $templateExporter->export(),
        ];
    }
}
