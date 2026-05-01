/**
 * PromptWire conflict classification (v1.11.0+).
 *
 * First user: `pw_template_fields_push` (additive fieldgroup edits). Designed
 * to extend across subsequent releases for `pw_field_push`, `pw_template_push`,
 * and other schema-object pushes, so every operator-facing tool surfaces the
 * same three-tier diagnosis:
 *
 *   - safe    — proceed without prompting
 *   - warning — the change is valid but has a non-obvious consequence
 *               (e.g. orphaning page-value data)
 *   - danger  — refuse the write unless `--force` is passed
 *
 * The PHP handlers currently classify inline and return the same shape over
 * the CLI boundary (see `CommandRouter::templateFieldsPush`). Pulling the
 * rules into this module will land with v1.11.0 once a second consumer
 * (pw_field_push) proves the shape.
 */

export interface ConflictEntry {
  op:    'add' | 'remove' | 'reorder' | 'type-change' | 'flag-change';
  field: string;
  /** Human-readable explanation of why the operation is classified this way. */
  why?:  string;
  /** Operation-specific payload (e.g. target field type for add, new flags for flag-change). */
  detail?: Record<string, unknown>;
}

export interface ConflictResult {
  safe:    ConflictEntry[];
  warning: ConflictEntry[];
  danger:  ConflictEntry[];
}

/**
 * Stub classifier. Real rules land once `pw_template_fields_push` moves from
 * WIP (PHP-side classification) to v1.11.0 (TS-side classification shared
 * across tools).
 */
export function emptyConflictResult(): ConflictResult {
  return { safe: [], warning: [], danger: [] };
}

export function isDangerous(result: ConflictResult): boolean {
  return result.danger.length > 0;
}
