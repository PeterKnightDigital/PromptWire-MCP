/**
 * PromptWire Schema File Sync
 *
 * Handles reading and writing schema files on the local filesystem.
 * Schema files live in .pw-sync/schema/ relative to PW_PATH (or PW_SYNC_DIR).
 *
 * File layout:
 *   .pw-sync/schema/fields.json     — all field definitions
 *   .pw-sync/schema/templates.json  — all template definitions
 *
 * These files are the local source of truth for schema:push and schema:diff.
 * They are written by schema:pull and read by schema:push / schema:diff.
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { promises as fs } from 'fs';
import path from 'path';
import os from 'os';
import { runPwCommand, type PwCommandResult } from '../cli/runner.js';
import { runRemoteCommand } from '../remote/client.js';

// ============================================================================
// TYPES
// ============================================================================

export interface SchemaFile {
  _exportedAt?: string;
  _pwVersion?: string;
  _siteName?: string;
  [key: string]: unknown;
}

export interface SchemaDiffItem {
  status: 'localOnly' | 'liveOnly' | 'changed' | 'unchanged';
  local?: unknown;
  live?: unknown;
}

export interface SchemaDiff {
  syncDir: string;
  fields: Record<string, SchemaDiffItem>;
  templates: Record<string, SchemaDiffItem>;
  summary: {
    fields: { localOnly: number; liveOnly: number; changed: number; unchanged: number };
    templates: { localOnly: number; liveOnly: number; changed: number; unchanged: number };
  };
}

// ============================================================================
// SYNC DIRECTORY RESOLUTION
// ============================================================================

/**
 * Get the local schema sync directory.
 *
 * Priority:
 *   1. PW_SYNC_DIR env var (explicit override)
 *   2. PW_PATH/.pw-sync/schema (local site)
 *   3. Falls back to cwd/.pw-sync/schema (remote-only setups)
 */
export function getSyncDir(): string {
  const syncDir = process.env.PW_SYNC_DIR;
  if (syncDir) {
    return path.join(syncDir, 'schema');
  }

  const pwPath = process.env.PW_PATH;
  if (pwPath) {
    return path.join(pwPath, '.pw-sync', 'schema');
  }

  // Remote-only setup: use the current working directory
  return path.join(process.cwd(), '.pw-sync', 'schema');
}

// ============================================================================
// SCHEMA PULL — export from PW and write to local files
// ============================================================================

/**
 * Pull schema from a PW site and write to local .pw-sync/schema/ files.
 *
 * Works for both local and remote sites — uses the existing export-schema
 * command which returns all fields and templates as JSON.
 */
export async function schemaPull(): Promise<PwCommandResult> {
  // Use the existing export-schema command (works locally and remotely)
  const result = await runPwCommand('export-schema');

  if (!result.success) {
    return result;
  }

  const schema = result.data as {
    meta?: Record<string, unknown>;
    fields?: Record<string, unknown>;
    templates?: Record<string, unknown>;
  };

  const { meta = {}, fields = {}, templates = {} } = schema;

  const syncDir = getSyncDir();
  await fs.mkdir(syncDir, { recursive: true });

  const metaHeader = {
    _exportedAt: meta.exportedAt ?? new Date().toISOString(),
    _pwVersion:  meta.pwVersion  ?? 'unknown',
    _siteName:   meta.siteName   ?? 'unknown',
  };

  const fieldsFile    = path.join(syncDir, 'fields.json');
  const templatesFile = path.join(syncDir, 'templates.json');

  // Back up existing files before overwriting so a mistaken pull is recoverable
  await backupIfExists(fieldsFile);
  await backupIfExists(templatesFile);

  await fs.writeFile(
    fieldsFile,
    JSON.stringify({ ...metaHeader, ...fields }, null, 2),
    'utf8'
  );

  await fs.writeFile(
    templatesFile,
    JSON.stringify({ ...metaHeader, ...templates }, null, 2),
    'utf8'
  );

  return {
    success: true,
    data: {
      message:   'Schema pulled successfully',
      syncDir,
      files: { fields: fieldsFile, templates: templatesFile },
      counts: {
        fields:    Object.keys(fields).length,
        templates: Object.keys(templates).length,
      },
      meta: metaHeader,
    },
  };
}

// ============================================================================
// SCHEMA PUSH — apply local files to a PW site
// ============================================================================

/**
 * Push local schema files to a PW site (local or remote).
 *
 * Reads .pw-sync/schema/fields.json and templates.json, combines them,
 * writes to a temp file, and calls schema:apply on the target site.
 */
export async function schemaPush(dryRun: boolean): Promise<PwCommandResult> {
  const syncDir = getSyncDir();

  const fieldsFile    = path.join(syncDir, 'fields.json');
  const templatesFile = path.join(syncDir, 'templates.json');

  const fieldsExists    = await fileExists(fieldsFile);
  const templatesExists = await fileExists(templatesFile);

  if (!fieldsExists && !templatesExists) {
    return {
      success: false,
      error: `No schema files found in ${syncDir} — run schema:pull first`,
    };
  }

  // Build combined schema object from local files
  const schema: Record<string, unknown> = {};

  if (fieldsExists) {
    const raw = await fs.readFile(fieldsFile, 'utf8');
    const data = JSON.parse(raw) as Record<string, unknown>;
    // Strip meta keys (prefixed with _)
    schema.fields = stripMeta(data);
  }

  if (templatesExists) {
    const raw = await fs.readFile(templatesFile, 'utf8');
    const data = JSON.parse(raw) as Record<string, unknown>;
    schema.templates = stripMeta(data);
  }

  if (!process.env.PW_PATH && process.env.PW_REMOTE_URL) {
    // Remote: pass schema inline in the HTTP body (no temp file needed)
    const cmdArgs: string[] = [];
    if (!dryRun) cmdArgs.push('--dry-run=0');
    return runRemoteCommand('schema:apply', cmdArgs, schema as Record<string, unknown>);
  }

  // Local: write schema to a temp file so PHP can read it
  // (avoids command-line length limits for large schemas)
  const tmpFile = path.join(os.tmpdir(), `promptwire-schema-${Date.now()}.json`);
  await fs.writeFile(tmpFile, JSON.stringify(schema), 'utf8');

  try {
    const cmdArgs = [tmpFile];
    if (!dryRun) {
      cmdArgs.push('--dry-run=0');
    }
    const result = await runPwCommand('schema:apply', cmdArgs);
    return result;
  } finally {
    // Always clean up the temp file
    await fs.unlink(tmpFile).catch(() => {});
  }
}

// ============================================================================
// SCHEMA DIFF — compare local files vs live PW
// ============================================================================

/**
 * Diff local schema files against the live PW site schema.
 *
 * Shows what's in your local files but not on the live site (would be added),
 * what's on the live site but not in your files (live-only),
 * and what differs between the two (would be updated).
 */
export async function schemaDiff(): Promise<PwCommandResult> {
  const syncDir = getSyncDir();

  const fieldsFile    = path.join(syncDir, 'fields.json');
  const templatesFile = path.join(syncDir, 'templates.json');

  const fieldsExists    = await fileExists(fieldsFile);
  const templatesExists = await fileExists(templatesFile);

  if (!fieldsExists && !templatesExists) {
    return {
      success: false,
      error: `No schema files found in ${syncDir} — run schema:pull first`,
    };
  }

  // Get live schema
  const liveResult = await runPwCommand('export-schema');
  if (!liveResult.success) {
    return liveResult;
  }

  const liveSchema = liveResult.data as {
    fields?: Record<string, unknown>;
    templates?: Record<string, unknown>;
  };

  const liveFields    = liveSchema.fields    ?? {};
  const liveTemplates = liveSchema.templates ?? {};

  // Load local files
  let localFields:    Record<string, unknown> = {};
  let localTemplates: Record<string, unknown> = {};

  if (fieldsExists) {
    const raw = await fs.readFile(fieldsFile, 'utf8');
    localFields = stripMeta(JSON.parse(raw) as Record<string, unknown>);
  }

  if (templatesExists) {
    const raw = await fs.readFile(templatesFile, 'utf8');
    localTemplates = stripMeta(JSON.parse(raw) as Record<string, unknown>);
  }

  // Perform diffs
  const fieldsDiff    = diffObjects(localFields, liveFields);
  const templatesDiff = diffObjects(localTemplates, liveTemplates);

  const result: SchemaDiff = {
    syncDir,
    fields:    fieldsDiff,
    templates: templatesDiff,
    summary: {
      fields:    countByStatus(fieldsDiff),
      templates: countByStatus(templatesDiff),
    },
  };

  return { success: true, data: result };
}

// ============================================================================
// HELPERS
// ============================================================================

/** Compare two schema objects and return per-key diff status */
function diffObjects(
  local: Record<string, unknown>,
  live: Record<string, unknown>
): Record<string, SchemaDiffItem> {
  const result: Record<string, SchemaDiffItem> = {};
  const allKeys = Array.from(new Set([...Object.keys(local), ...Object.keys(live)])).sort();

  for (const key of allKeys) {
    if (key in local && !(key in live)) {
      result[key] = { status: 'localOnly', local: local[key] };
    } else if (!(key in local) && key in live) {
      result[key] = { status: 'liveOnly', live: live[key] };
    } else {
      const localStr = JSON.stringify(local[key]);
      const liveStr  = JSON.stringify(live[key]);
      if (localStr !== liveStr) {
        result[key] = { status: 'changed', local: local[key], live: live[key] };
      } else {
        result[key] = { status: 'unchanged' };
      }
    }
  }

  return result;
}

/** Count diff items by status */
function countByStatus(
  diff: Record<string, SchemaDiffItem>
): { localOnly: number; liveOnly: number; changed: number; unchanged: number } {
  const counts = { localOnly: 0, liveOnly: 0, changed: 0, unchanged: 0 };
  for (const item of Object.values(diff)) {
    counts[item.status]++;
  }
  return counts;
}

/** Strip meta keys (starting with _) from a schema object */
function stripMeta(data: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(data).filter(([k]) => !k.startsWith('_'))
  );
}

/** Check if a file exists */
async function fileExists(filePath: string): Promise<boolean> {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

/** Copy an existing file to a .bak alongside it (single rotating backup) */
async function backupIfExists(filePath: string): Promise<void> {
  try {
    await fs.access(filePath);
    await fs.copyFile(filePath, filePath + '.bak');
  } catch {
    // File doesn't exist yet — nothing to back up
  }
}
