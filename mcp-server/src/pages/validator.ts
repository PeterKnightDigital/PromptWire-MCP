/**
 * PW-MCP Page Reference Validator
 *
 * Scans all locally synced pages (site/assets/pw-mcp/) for _pageRef fields and
 * verifies that the referenced page paths exist on a target environment before a
 * push. Prevents pushes that would silently leave relationship fields blank.
 *
 * Status values per reference:
 *   ok          — path resolves and page is published
 *   unpublished — path resolves but page is unpublished (ref is valid but page is hidden)
 *   missing     — path not found on target (push would blank the field)
 *
 * @package     PwMcp
 * @subpackage  MCP Server
 * @author      Peter Knight
 * @license     MIT
 */

import { readFile, readdir } from 'fs/promises';
import { existsSync } from 'fs';
import path from 'path';
import { load as yamlLoad } from 'js-yaml';
import { runPwCommand } from '../cli/runner.js';
import type { PwCommandResult } from '../cli/runner.js';
import { runRemoteCommand } from '../remote/client.js';

// ============================================================================
// TYPES
// ============================================================================

export type RefStatus = 'ok' | 'unpublished' | 'missing';
export type ValidateTarget = 'local' | 'remote';

export interface PageRefHit {
  /** The page that owns the field (local path from pw-mcp dir) */
  sourcePage: string;
  /** The field name containing the reference */
  fieldName: string;
  /** The referenced page path e.g. "/services/foo/" */
  refPath: string;
  /** Source ID stored in the YAML (may differ on target) */
  refId: number | null;
}

export interface RefValidationResult {
  refPath: string;
  status: RefStatus;
  resolvedId?: number;
  title?: string;
  template?: string;
}

export interface PageValidationSummary {
  sourcePage: string;
  fieldName: string;
  refPath: string;
  refId: number | null;
  status: RefStatus;
  resolvedId?: number;
  title?: string;
}

export interface ValidateRefsReport {
  target: ValidateTarget;
  scannedPages: number;
  totalRefs: number;
  ok: number;
  unpublished: number;
  missing: number;
  issues: PageValidationSummary[];
  clean: PageValidationSummary[];
  message?: string;
}

// ============================================================================
// YAML SCANNING
// ============================================================================

/** Recursively collect all page.yaml files under a sync root */
async function findYamlFiles(dir: string): Promise<string[]> {
  const results: string[] = [];
  if (!existsSync(dir)) return results;

  const entries = await readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      const nested = await findYamlFiles(full);
      results.push(...nested);
    } else if (entry.name === 'page.yaml') {
      results.push(full);
    }
  }
  return results;
}

/** Extract every _pageRef from a parsed fields object (handles single + array) */
function extractRefs(
  fields: Record<string, unknown>,
  sourcePage: string,
): PageRefHit[] {
  const hits: PageRefHit[] = [];

  for (const [fieldName, value] of Object.entries(fields)) {
    if (fieldName.startsWith('_') || value === null || value === undefined) continue;

    // Single page ref
    if (
      typeof value === 'object' &&
      !Array.isArray(value) &&
      (value as Record<string, unknown>)._pageRef === true
    ) {
      const ref = value as Record<string, unknown>;
      const refPath = resolvePathFromRef(ref);
      if (refPath) {
        hits.push({ sourcePage, fieldName, refPath, refId: (ref.id as number) ?? null });
      }
      continue;
    }

    // Array of page refs
    if (Array.isArray(value) && value.length > 0) {
      const first = value[0] as Record<string, unknown>;
      if (first?._pageRef === true) {
        for (const item of value as Record<string, unknown>[]) {
          const refPath = resolvePathFromRef(item);
          if (refPath) {
            hits.push({ sourcePage, fieldName, refPath, refId: (item.id as number) ?? null });
          }
        }
      }
    }
  }

  return hits;
}

/** Extract path from a _pageRef object — prefers explicit path, falls back to _comment */
function resolvePathFromRef(ref: Record<string, unknown>): string | null {
  if (typeof ref.path === 'string' && ref.path) return ref.path;
  if (typeof ref._comment === 'string') {
    const parts = ref._comment.split(' @ ');
    const p = parts[1]?.trim();
    if (p) return p;
  }
  return null;
}

/** Scan the sync directory and collect all _pageRef hits */
async function collectAllRefs(syncRoot: string): Promise<{ hits: PageRefHit[]; scannedPages: number }> {
  const yamlFiles = await findYamlFiles(syncRoot);
  const hits: PageRefHit[] = [];

  for (const yamlPath of yamlFiles) {
    try {
      const raw = await readFile(yamlPath, 'utf-8');
      const parsed = yamlLoad(raw) as { fields?: Record<string, unknown> };
      if (!parsed?.fields) continue;

      // Derive a human-readable source label from the yaml path
      const sourcePage = yamlPath
        .replace(syncRoot, '')
        .replace(/\/page\.yaml$/, '')
        .replace(/^\//, '/');

      const found = extractRefs(parsed.fields, sourcePage);
      hits.push(...found);
    } catch {
      // Skip unreadable files
    }
  }

  return { hits, scannedPages: yamlFiles.length };
}

// ============================================================================
// TARGET RESOLUTION
// ============================================================================

/** Query the local site for a batch of paths */
async function resolvePathsLocal(paths: string[]): Promise<Record<string, RefValidationResult>> {
  const result = await runPwCommand('page:exists', [`--paths=${JSON.stringify(paths)}`]);

  if (!result.success || !result.data) {
    return Object.fromEntries(paths.map(p => [p, { refPath: p, status: 'missing' as RefStatus }]));
  }

  const raw = result.data as { results?: Record<string, { exists: boolean; id?: number; title?: string; published?: boolean; template?: string }> };
  return buildResultMap(paths, raw.results ?? {});
}

/** Query the remote site for a batch of paths */
async function resolvePathsRemote(paths: string[]): Promise<Record<string, RefValidationResult>> {
  const result = await runRemoteCommand('page:exists', [], undefined, undefined, undefined, { paths });

  if (!result.success || !result.data) {
    return Object.fromEntries(paths.map(p => [p, { refPath: p, status: 'missing' as RefStatus }]));
  }

  const raw = result.data as { results?: Record<string, { exists: boolean; id?: number; title?: string; published?: boolean; template?: string }> };
  return buildResultMap(paths, raw.results ?? {});
}

function buildResultMap(
  paths: string[],
  raw: Record<string, { exists: boolean; id?: number; title?: string; published?: boolean; template?: string }>,
): Record<string, RefValidationResult> {
  const map: Record<string, RefValidationResult> = {};
  for (const p of paths) {
    const r = raw[p];
    if (!r || !r.exists) {
      map[p] = { refPath: p, status: 'missing' };
    } else if (!r.published) {
      map[p] = { refPath: p, status: 'unpublished', resolvedId: r.id, title: r.title, template: r.template };
    } else {
      map[p] = { refPath: p, status: 'ok', resolvedId: r.id, title: r.title, template: r.template };
    }
  }
  return map;
}

// ============================================================================
// PUBLIC API
// ============================================================================

export interface ValidateRefsOptions {
  /** Where to check: 'local' (default) or 'remote' */
  target?: ValidateTarget;
  /**
   * Root of the sync directory. Defaults to PW_PATH/site/assets/pw-mcp
   * or the current working directory's site/assets/pw-mcp.
   */
  syncRoot?: string;
}

export async function validateRefs(opts: ValidateRefsOptions = {}): Promise<PwCommandResult> {
  const target: ValidateTarget = opts.target ?? (process.env.PW_REMOTE_URL ? 'remote' : 'local');

  // Determine sync root
  const syncRoot =
    opts.syncRoot ??
    (process.env.PW_PATH
      ? path.join(process.env.PW_PATH, 'site/assets/pw-mcp')
      : path.join(process.cwd(), 'site/assets/pw-mcp'));

  if (!existsSync(syncRoot)) {
    return {
      success: false,
      error: `Sync directory not found: ${syncRoot}. Pull some pages first.`,
    };
  }

  // 1. Collect all refs from local YAML files
  const { hits, scannedPages } = await collectAllRefs(syncRoot);

  if (hits.length === 0) {
    return {
      success: true,
      data: {
        target,
        scannedPages,
        totalRefs: 0,
        ok: 0,
        unpublished: 0,
        missing: 0,
        issues: [],
        clean: [],
        message: 'No page reference fields found in synced pages.',
      } satisfies ValidateRefsReport,
    };
  }

  // 2. De-duplicate paths and batch-resolve against the target
  const uniquePaths = [...new Set(hits.map(h => h.refPath))];
  const resolved =
    target === 'remote'
      ? await resolvePathsRemote(uniquePaths)
      : await resolvePathsLocal(uniquePaths);

  // 3. Build per-ref summaries
  const issues: PageValidationSummary[] = [];
  const clean: PageValidationSummary[] = [];

  for (const hit of hits) {
    const r = resolved[hit.refPath] ?? { refPath: hit.refPath, status: 'missing' as RefStatus };
    const summary: PageValidationSummary = {
      sourcePage: hit.sourcePage,
      fieldName:  hit.fieldName,
      refPath:    hit.refPath,
      refId:      hit.refId,
      status:     r.status,
      resolvedId: r.resolvedId,
      title:      r.title,
    };
    if (r.status === 'ok') {
      clean.push(summary);
    } else {
      issues.push(summary);
    }
  }

  const report: ValidateRefsReport = {
    target,
    scannedPages,
    totalRefs:   hits.length,
    ok:          clean.length,
    unpublished: issues.filter(i => i.status === 'unpublished').length,
    missing:     issues.filter(i => i.status === 'missing').length,
    issues,
    clean,
  };

  return { success: true, data: report };
}
