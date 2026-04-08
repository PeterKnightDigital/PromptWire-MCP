<?php
/**
 * PW-MCP Schema Importer
 *
 * Applies a schema definition (fields + templates) to a ProcessWire installation.
 * Used by the schema:apply CLI command to push local schema files to any PW site.
 *
 * Safety rules:
 * - Never deletes fields or templates (only adds/updates)
 * - Field type changes are blocked (too dangerous — must be done manually)
 * - Dry-run is on by default
 * - Returns detailed per-item results so callers can see exactly what changed
 *
 * @package     PwMcp
 * @subpackage  Schema
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

namespace PwMcp\Schema;

/**
 * Applies a schema array to a live ProcessWire installation
 */
class SchemaImporter
{
    private $wire;

    public function __construct($wire)
    {
        $this->wire = $wire;
    }

    /**
     * Apply a full schema (fields + templates) to ProcessWire
     *
     * Fields are applied first so templates can reference them.
     * Dry-run shows exactly what would change without touching the database.
     *
     * @param array $schema  Schema array with 'fields' and/or 'templates' keys
     * @param bool  $dryRun  Preview changes without applying (default: true)
     * @return array Results with per-item status
     */
    public function apply(array $schema, bool $dryRun = true): array
    {
        $results = [
            'dryRun'    => $dryRun,
            'fields'    => [],
            'templates' => [],
            'summary'   => [],
        ];

        // Apply fields first — templates reference fields by name
        if (!empty($schema['fields'])) {
            $results['fields'] = $this->applyFields($schema['fields'], $dryRun);
        }

        // Apply templates second
        if (!empty($schema['templates'])) {
            $results['templates'] = $this->applyTemplates($schema['templates'], $dryRun);
        }

        // Build summary counts
        $results['summary'] = $this->buildSummary($results['fields'], $results['templates']);

        return $results;
    }

    // =========================================================================
    // FIELD IMPORT
    // =========================================================================

    /**
     * Apply all field definitions
     */
    private function applyFields(array $fieldsSchema, bool $dryRun): array
    {
        $results = [];

        foreach ($fieldsSchema as $fieldName => $fieldDef) {
            // Skip meta keys (keys starting with _)
            if (strpos($fieldName, '_') === 0) {
                continue;
            }

            $existing = $this->wire->fields->get($fieldName);

            if (!$existing) {
                $result = $this->createField($fieldName, $fieldDef, $dryRun);
                $result['action'] = 'create';
            } else {
                $result = $this->updateField($existing, $fieldDef, $dryRun);
                $result['action'] = $result['changed'] ? 'update' : 'unchanged';
            }

            $results[$fieldName] = $result;
        }

        return $results;
    }

    /**
     * Create a new field
     */
    private function createField(string $name, array $def, bool $dryRun): array
    {
        $typeName = $def['type'] ?? null;
        if (!$typeName) {
            return ['success' => false, 'error' => 'Missing field type'];
        }

        // Validate the fieldtype exists in this PW installation
        $fieldtype = $this->wire->fieldtypes->get($typeName);
        if (!$fieldtype) {
            return [
                'success' => false,
                'error'   => "Unknown field type: $typeName — is the required module installed?",
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'type'    => $typeName,
                'label'   => $def['label'] ?? null,
            ];
        }

        // Create and configure the field
        $field = new \ProcessWire\Field();
        $field->name  = $name;
        $field->type  = $fieldtype;
        $field->label = $def['label'] ?? '';

        if (!empty($def['description'])) {
            $field->description = $def['description'];
        }
        if (!empty($def['required'])) {
            $field->required = (bool) $def['required'];
        }
        if (!empty($def['inputfield'])) {
            $field->inputfieldClass = $def['inputfield'];
        }

        // Apply type-specific settings
        $settings = $def['settings'] ?? [];
        foreach ($settings as $key => $value) {
            // Options are handled separately after save
            if ($key !== 'options') {
                $field->set($key, $value);
            }
        }

        $this->wire->fields->save($field);

        // Handle select options after field is saved
        if (($def['type'] ?? '') === 'FieldtypeOptions' && !empty($settings['options'])) {
            $this->setFieldOptions($field, $settings['options']);
        }

        return [
            'success' => true,
            'id'      => $field->id,
            'type'    => $typeName,
            'label'   => $field->label,
        ];
    }

    /**
     * Update an existing field (label, description, required — never type)
     */
    private function updateField($field, array $def, bool $dryRun): array
    {
        $changes = [];

        // Block type changes — too dangerous to do automatically
        $typeName = $def['type'] ?? null;
        if ($typeName && $field->type->className() !== $typeName) {
            return [
                'success' => false,
                'changed' => false,
                'warning' => "Type change from {$field->type->className()} to {$typeName} must be done manually in PW admin (data loss risk)",
            ];
        }

        // Detect label change
        $newLabel = $def['label'] ?? null;
        if ($newLabel !== null && $field->label !== $newLabel) {
            $changes['label'] = ['from' => $field->label, 'to' => $newLabel];
        }

        // Detect description change
        $newDesc = $def['description'] ?? null;
        if ($newDesc !== null && $field->description !== $newDesc) {
            $changes['description'] = ['from' => $field->description, 'to' => $newDesc];
        }

        // Detect required change
        if (isset($def['required'])) {
            $newRequired = (bool) $def['required'];
            if ((bool) $field->required !== $newRequired) {
                $changes['required'] = ['from' => (bool) $field->required, 'to' => $newRequired];
            }
        }

        if (empty($changes)) {
            return ['success' => true, 'changed' => false];
        }

        if ($dryRun) {
            return ['success' => true, 'changed' => true, 'changes' => $changes];
        }

        // Apply changes
        foreach ($changes as $key => $change) {
            $field->set($key, $change['to']);
        }
        $this->wire->fields->save($field);

        return ['success' => true, 'changed' => true, 'changes' => $changes];
    }

    // =========================================================================
    // TEMPLATE IMPORT
    // =========================================================================

    /**
     * Apply all template definitions
     */
    private function applyTemplates(array $templatesSchema, bool $dryRun): array
    {
        $results = [];

        foreach ($templatesSchema as $templateName => $templateDef) {
            // Skip meta keys
            if (strpos($templateName, '_') === 0) {
                continue;
            }

            $existing = $this->wire->templates->get($templateName);

            if (!$existing) {
                $result = $this->createTemplate($templateName, $templateDef, $dryRun);
                $result['action'] = 'create';
            } else {
                $result = $this->updateTemplate($existing, $templateDef, $dryRun);
                $result['action'] = $result['changed'] ? 'update' : 'unchanged';
            }

            $results[$templateName] = $result;
        }

        return $results;
    }

    /**
     * Create a new template and assign its fields
     */
    private function createTemplate(string $name, array $def, bool $dryRun): array
    {
        $desiredFields  = $def['fields'] ?? [];
        $missingFields  = $this->findMissingFields($desiredFields);

        if ($dryRun) {
            $result = [
                'success' => true,
                'label'   => $def['label'] ?? null,
                'fields'  => $desiredFields,
            ];
            if (!empty($missingFields)) {
                $result['warning'] = 'Some fields do not exist yet — run schema:push for fields first';
                $result['missingFields'] = $missingFields;
            }
            return $result;
        }

        // ProcessWire requires a Fieldgroup before a Template can be saved
        $fg       = new \ProcessWire\Fieldgroup();
        $fg->name = $name;

        // Always include the title field (PW requires it)
        $titleField = $this->wire->fields->get('title');
        if ($titleField && !in_array('title', $desiredFields, true)) {
            $fg->add($titleField);
        }

        $this->wire->fieldgroups->save($fg);

        // Create the template and assign the fieldgroup
        $template             = new \ProcessWire\Template();
        $template->name       = $name;
        $template->label      = $def['label'] ?? '';
        $template->fieldgroup = $fg;

        // Apply family settings before first save
        if (!empty($def['family'])) {
            $this->applyFamilySettings($template, $def['family']);
        }

        $this->wire->templates->save($template);

        // Add fields (skip any that don't exist yet)
        $fieldsAdded = [];
        foreach ($desiredFields as $fieldName) {
            if (in_array($fieldName, $missingFields, true)) {
                continue;
            }
            $field = $this->wire->fields->get($fieldName);
            if ($field) {
                $template->fieldgroup->add($field);
                $fieldsAdded[] = $fieldName;
            }
        }
        $template->fieldgroup->save();

        $result = [
            'success'      => true,
            'id'           => $template->id,
            'label'        => $template->label,
            'fieldsAdded'  => $fieldsAdded,
        ];
        if (!empty($missingFields)) {
            $result['missingFields'] = $missingFields;
        }
        return $result;
    }

    /**
     * Update an existing template — add new fields, update label
     * Never removes fields from a template (safe by default)
     */
    private function updateTemplate($template, array $def, bool $dryRun): array
    {
        $changes = [];

        // Check label change
        $newLabel = $def['label'] ?? null;
        if ($newLabel !== null && $template->label !== $newLabel) {
            $changes['label'] = ['from' => $template->label, 'to' => $newLabel];
        }

        // Detect fields that need adding (never remove — that requires manual action)
        $desiredFields = $def['fields'] ?? [];
        $currentFields = [];
        foreach ($template->fields as $f) {
            $currentFields[] = $f->name;
        }

        $toAdd         = array_values(array_diff($desiredFields, $currentFields));
        $missingFields = $this->findMissingFields($toAdd);

        if (!empty($toAdd)) {
            $changes['fieldsToAdd'] = $toAdd;
        }

        if (empty($changes)) {
            return ['success' => true, 'changed' => false];
        }

        if ($dryRun) {
            $result = ['success' => true, 'changed' => true, 'changes' => $changes];
            if (!empty($missingFields)) {
                $result['missingFields'] = $missingFields;
            }
            return $result;
        }

        // Apply label change
        if (isset($changes['label'])) {
            $template->label = $changes['label']['to'];
        }

        // Add new fields
        $fieldsAdded = [];
        foreach ($toAdd as $fieldName) {
            if (in_array($fieldName, $missingFields, true)) {
                continue;
            }
            $field = $this->wire->fields->get($fieldName);
            if ($field) {
                $template->fields->add($field);
                $fieldsAdded[] = $fieldName;
            }
        }
        if (!empty($fieldsAdded)) {
            $template->fields->save();
            $changes['fieldsAdded'] = $fieldsAdded;
        }

        $this->wire->templates->save($template);

        $result = ['success' => true, 'changed' => true, 'changes' => $changes];
        if (!empty($missingFields)) {
            $result['missingFields'] = $missingFields;
        }
        return $result;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Apply family/relationship settings to a template
     */
    private function applyFamilySettings($template, array $family): void
    {
        if (isset($family['allowChildren'])) {
            $template->noChildren = $family['allowChildren'] ? 0 : 1;
        }
        if (isset($family['allowParents'])) {
            $template->noParents = $family['allowParents'] ? 0 : 1;
        }
        if (isset($family['allowPageNum'])) {
            $template->allowPageNum = (int) $family['allowPageNum'];
        }
        if (isset($family['urlSegments'])) {
            $template->urlSegments = (int) $family['urlSegments'];
        }
    }

    /**
     * Return which field names don't exist in this PW installation
     */
    private function findMissingFields(array $fieldNames): array
    {
        $missing = [];
        foreach ($fieldNames as $name) {
            if (!$this->wire->fields->get($name)) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /**
     * Set options for a FieldtypeOptions field (adds missing options only)
     */
    private function setFieldOptions($field, array $options): void
    {
        $manager = $this->wire->modules->get('FieldtypeOptions');
        if (!$manager || !method_exists($manager, 'getOptions')) {
            return;
        }

        $existingOptions = $manager->getOptions($field);
        $existingValues  = [];
        foreach ($existingOptions as $opt) {
            $existingValues[] = (string) $opt->value;
        }

        foreach ($options as $opt) {
            $value = (string) ($opt['value'] ?? '');
            if ($value === '' || in_array($value, $existingValues, true)) {
                continue;
            }
            if (method_exists($manager, 'getBlankOption')) {
                $newOpt        = $manager->getBlankOption($field);
                $newOpt->value = $value;
                $newOpt->title = $opt['title'] ?? $value;
                if (method_exists($manager, 'saveOption')) {
                    $manager->saveOption($field, $newOpt);
                }
            }
        }
    }

    /**
     * Build summary counts from results
     */
    private function buildSummary(array $fields, array $templates): array
    {
        $tally = function (array $items): array {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];
            foreach ($items as $item) {
                if (!empty($item['error'])) {
                    $counts['errors']++;
                } elseif (($item['action'] ?? '') === 'create') {
                    $counts['created']++;
                } elseif (($item['action'] ?? '') === 'update') {
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }
            }
            return $counts;
        };

        return [
            'fields'    => $tally($fields),
            'templates' => $tally($templates),
        ];
    }
}
