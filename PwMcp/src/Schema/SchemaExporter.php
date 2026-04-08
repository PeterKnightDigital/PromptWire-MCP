<?php
/**
 * PW-MCP Schema Exporter
 * 
 * Exports the complete ProcessWire site schema (fields and templates)
 * in a structured format suitable for documentation, version control,
 * and cross-site comparison.
 * 
 * @package     PwMcp
 * @subpackage  Schema
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

namespace PwMcp\Schema;

/**
 * Exports complete site schema (fields + templates)
 * 
 * This is the main entry point for schema export. It combines the
 * output of FieldExporter and TemplateExporter with metadata about
 * the export.
 * 
 * The output includes:
 * - Meta: Export timestamp, PW version, site name
 * - Fields: All non-system field definitions
 * - Templates: All non-system template definitions
 * 
 * The schema can be output as JSON (default) or YAML (via --format=yaml).
 * 
 * Example usage:
 *   php pw-mcp.php export-schema --pretty
 *   php pw-mcp.php export-schema --format=yaml > schema.yaml
 */
class SchemaExporter {
    
    /**
     * ProcessWire instance
     * 
     * @var \ProcessWire\ProcessWire
     */
    private $wire;
    
    /**
     * Create a new SchemaExporter
     * 
     * @param \ProcessWire\ProcessWire $wire ProcessWire instance
     */
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Export complete site schema
     * 
     * Combines field and template exports with metadata to create
     * a complete picture of the site's structure.
     * 
     * The output is designed to be:
     * - Self-documenting (includes site name and PW version)
     * - Portable (uses names instead of IDs where possible)
     * - Diffable (sorted alphabetically for clean version control)
     * - Useful for AI understanding of site structure
     * 
     * @return array Complete schema with meta, fields, and templates
     */
    public function export(): array {
        $fieldExporter = new FieldExporter($this->wire);
        $templateExporter = new TemplateExporter($this->wire);
        
        return [
            // Metadata about this export
            'meta' => [
                'exportedAt' => date('c'),  // ISO 8601 format
                'pwVersion' => $this->wire->config->version,
                'siteName' => $this->wire->config->httpHost ?: basename($this->wire->config->paths->root),
            ],
            // All field definitions
            'fields' => $fieldExporter->export(),
            // All template definitions
            'templates' => $templateExporter->export(),
        ];
    }
}
