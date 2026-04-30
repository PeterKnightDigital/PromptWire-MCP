/**
 * PromptWire Page Pusher
 *
 * Reads a locally-synced page.yaml file, resolves field values (including
 * _file references), and pushes the content to local PW, remote PW, or both.
 *
 * Targets:
 *   'local'      — push to the local MAMP/dev ProcessWire via PHP CLI
 *   'remote'     — push to the remote production site via HTTP API
 *   'both'       — push to local first, then remote
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { readFile, access, readdir, writeFile } from 'fs/promises';
import { existsSync } from 'fs';
import path from 'path';
import { load as yamlLoad } from 'js-yaml';
import { execFile } from 'child_process';
import { promisify } from 'util';
import { runRemoteCommand } from '../remote/client.js';
import type { PwCommandResult } from '../cli/runner.js';

const execFileAsync = promisify(execFile);

// ============================================================================
// TYPES
// ============================================================================

export type PushTarget = 'local' | 'remote' | 'both';

export interface PushPageOptions {
  localPath: string;
  dryRun?: boolean;
  force?: boolean;
  targets?: PushTarget;
  publish?: boolean;
}

interface PageMeta {
  pageId: number;
  canonicalPath: string;
  path?: string; // legacy fallback
  template: string;
  syncDir?: string;
}

interface FieldValue {
  [key: string]: unknown;
}

// ============================================================================
// MAIN ENTRY POINT
// ============================================================================

/**
 * Push a locally-synced page to local PW, remote PW, or both.
 *
 * The localPath can be:
 *   - A directory containing page.yaml  (e.g. "site/assets/pw-mcp/services/mobile-website-design")
 *   - A direct path to page.yaml        (e.g. "site/assets/pw-mcp/services/mobile-website-design/page.yaml")
 */
export async function pushPage(opts: PushPageOptions): Promise<PwCommandResult> {
  const { localPath, dryRun = true, force = false, targets = 'local', publish = false } = opts;

  // Resolve yaml and meta paths
  const yamlPath = localPath.endsWith('page.yaml')
    ? localPath
    : path.join(localPath, 'page.yaml');
  const metaPath = yamlPath.replace('page.yaml', 'page.meta.json');

  if (!existsSync(yamlPath)) {
    return { success: false, error: `page.yaml not found at: ${yamlPath}` };
  }
  if (!existsSync(metaPath)) {
    return { success: false, error: `page.meta.json not found at: ${metaPath} — pull the page first` };
  }

  // Load meta to get canonical page path
  let meta: PageMeta;
  try {
    meta = JSON.parse(await readFile(metaPath, 'utf-8')) as PageMeta;
  } catch {
    return { success: false, error: 'Failed to read page.meta.json' };
  }

  const pagePath = meta.canonicalPath ?? meta.path;
  if (!pagePath) {
    return { success: false, error: 'page.meta.json has no canonicalPath — re-pull the page first' };
  }

  const shouldPushLocal  = targets === 'local'  || targets === 'both';
  const shouldPushRemote = targets === 'remote' || targets === 'both';

  // Validate remote config before doing anything
  if (shouldPushRemote) {
    const remoteUrl = process.env.PW_REMOTE_URL;
    const remoteKey = process.env.PW_REMOTE_KEY;
    if (!remoteUrl || !remoteKey) {
      return {
        success: false,
        error: 'Remote push requires PW_REMOTE_URL and PW_REMOTE_KEY in your MCP server env. ' +
               'Add them to the local MCP server entry in .cursor/mcp.json.',
      };
    }
  }

  const results: Record<string, unknown> = {};
  let hasFailure = false;

  // ── LOCAL PUSH ─────────────────────────────────────────────────────────────
  if (shouldPushLocal) {
    const localResult = await pushToLocal(yamlPath, dryRun, force);
    if (!localResult.success) hasFailure = true;
    results['local'] = localResult.success ? localResult.data : { error: localResult.error };
  }

  // ── REMOTE PUSH ────────────────────────────────────────────────────────────
  if (shouldPushRemote) {
    const remoteResult = await pushToRemote(yamlPath, pagePath, dryRun, publish, meta as PageMeta & { parentPath?: string });
    if (!remoteResult.success) hasFailure = true;
    results['remote'] = remoteResult.success ? remoteResult.data : { error: remoteResult.error };

    // v1.10.1: a successful live remote push now has authoritative
    // knowledge of the remote pageId (from the API response). Record it
    // in the local page.meta.json under ids.remote so subsequent compares
    // and pushes know both sides' ids without re-resolving. Skipped on
    // dry-runs (we don't want to mutate state during a preview) and on
    // failures (no id to record).
    if (remoteResult.success && !dryRun) {
      await recordRemoteIdInMeta(metaPath, remoteResult.data);
    }
  }

  return {
    success: !hasFailure,
    ...(hasFailure ? { error: 'One or more push targets failed — check results for details' } : {}),
    data: {
      pagePath,
      dryRun,
      targets,
      results,
    },
  };
}

// ============================================================================
// LOCAL PUSH (PHP CLI)
// ============================================================================

async function pushToLocal(yamlPath: string, dryRun: boolean, force: boolean): Promise<PwCommandResult> {
  const phpPath = process.env.PHP_PATH || 'php';
  const pwPath  = process.env.PW_PATH;

  if (!pwPath) {
    return { success: false, error: 'PW_PATH not set — cannot push to local' };
  }

  const cliPath = process.env.PROMPTWIRE_CLI_PATH ||
    `${pwPath}/site/modules/PromptWire/bin/promptwire.php`;

  const cmdArgs = [cliPath, 'page:push', yamlPath];
  if (!dryRun) cmdArgs.push('--dry-run=0');
  if (force)   cmdArgs.push('--force');

  try {
    const { stdout, stderr } = await execFileAsync(phpPath, cmdArgs, {
      env: { ...process.env, PW_PATH: pwPath },
      timeout: 30_000,
      maxBuffer: 10 * 1024 * 1024,
    });
    if (stderr?.trim()) console.error('Local push stderr:', stderr);
    const data = JSON.parse(stdout);
    return data.error ? { success: false, error: data.error } : { success: true, data };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : 'Local push failed' };
  }
}

// ============================================================================
// REMOTE PUSH (HTTP API via page:update)
// ============================================================================

async function pushToRemote(
  yamlPath: string, pagePath: string, dryRun: boolean,
  publish = false, meta?: PageMeta & { parentPath?: string },
): Promise<PwCommandResult> {
  let fields: FieldValue;
  try {
    fields = await parsePageYaml(yamlPath);
  } catch (err) {
    return { success: false, error: `Failed to parse page.yaml: ${err instanceof Error ? err.message : String(err)}` };
  }

  const args = [pagePath];
  if (!dryRun) args.push('--dry-run=0');

  const result = await runRemoteCommand(
    'page:update',
    args,
    undefined,
    undefined,
    undefined,
    { fields, publish },
  );

  // If the page doesn't exist on remote yet, fall back to creating it.
  // This handles the case where a page was created on the local target
  // first and now needs to be pushed to remote for the first time.
  if (!result.success && result.error?.includes('Page not found') && meta?.template) {
    const pathParts = pagePath.split('/').filter(Boolean);
    const pageName = pathParts[pathParts.length - 1] ?? '';
    const parentPath = meta.parentPath ?? '/' + pathParts.slice(0, -1).join('/') + '/';

    return runRemoteCommand(
      'page:create',
      [meta.template, parentPath, pageName, ...(dryRun ? [] : ['--dry-run=0'])],
      undefined, undefined, undefined,
      { fields, published: publish },
    );
  }

  return result;
}

// ============================================================================
// YAML PARSER — resolves _file references into inline content
// ============================================================================

/**
 * Parse a page.yaml file and return flat field values, resolving any
 * _file references (e.g. body: { _file: "fields/body.html" }) by reading
 * the referenced file from disk.
 */
async function parsePageYaml(yamlPath: string): Promise<FieldValue> {
  const raw = await readFile(yamlPath, 'utf-8');
  const parsed = yamlLoad(raw) as { fields?: Record<string, unknown> };

  if (!parsed?.fields) {
    throw new Error('page.yaml has no "fields" key');
  }

  const dir = path.dirname(yamlPath);
  const resolved: FieldValue = {};

  for (const [key, value] of Object.entries(parsed.fields)) {
    // Skip metadata keys and null/empty values
    if (key.startsWith('_') || value === null || value === undefined) continue;

    // Resolve _file references
    if (isFileRef(value)) {
      const filePath = path.join(dir, (value as { _file: string })._file);
      try {
        await access(filePath);
        resolved[key] = await readFile(filePath, 'utf-8');
      } catch {
        // File referenced but not found — skip silently
      }
      continue;
    }

    if (Array.isArray(value) && value.length === 0) continue;

    if (Array.isArray(value) && typeof value[0] === 'object') {
      // Pass _pageRef arrays through — the remote API resolves IDs by path.
      // Skip everything else (image/file arrays) to avoid clobbering media.
      const isPageRefArray = (value[0] as Record<string, unknown>)?._pageRef === true;
      if (!isPageRefArray) continue;
    }

    // Single _pageRef objects are passed through as-is; path-first resolution
    // happens in the PHP API handler on the target environment.
    resolved[key] = value;
  }

  return resolved;
}

// ============================================================================
// PUBLISH NEW PAGE — create a scaffolded page on local, remote, or both
// ============================================================================

export interface PublishPageOptions {
  localPath: string;
  dryRun?: boolean;
  published?: boolean;
  targets?: PushTarget;
}

/**
 * Publish a new page (created with page:new scaffold) to local PW, remote, or both.
 * Reads page.meta.json for template/parent/name and page.yaml for field content.
 */
export async function publishPage(opts: PublishPageOptions): Promise<PwCommandResult> {
  const { localPath, dryRun = true, published = false, targets = 'local' } = opts;

  const yamlPath = localPath.endsWith('page.yaml')
    ? localPath
    : path.join(localPath, 'page.yaml');
  const metaPath = yamlPath.replace('page.yaml', 'page.meta.json');

  if (!existsSync(yamlPath)) return { success: false, error: `page.yaml not found: ${yamlPath}` };

  let meta: PageMeta & { new?: boolean; template?: string; parentPath?: string; pageName?: string; parentId?: number };

  if (existsSync(metaPath)) {
    try {
      meta = JSON.parse(await readFile(metaPath, 'utf-8'));
    } catch {
      return { success: false, error: 'Failed to read page.meta.json' };
    }

    // Don't hard-block when meta.new is false — the page may exist on one
    // target but not the other.  Each target's handler already rejects
    // duplicates gracefully (local PHP: "Page already exists"; remote API: 409).
  } else {
    // Auto-generate meta from page.yaml + directory structure
    let parsed: { fields?: Record<string, unknown> };
    try {
      parsed = yamlLoad(await readFile(yamlPath, 'utf-8')) as { fields?: Record<string, unknown> };
    } catch {
      return { success: false, error: 'Failed to parse page.yaml for auto-generated meta' };
    }

    // Derive parentPath and pageName from directory structure relative to promptwire sync root
    const dirPath = path.dirname(yamlPath);
    const pageName = path.basename(dirPath);
    const parentDir = path.dirname(dirPath);

    // Walk up to find the promptwire sync root marker (e.g. site/assets/pw-mcp)
    const syncMarker = 'site/assets/pw-mcp';
    const idx = dirPath.indexOf(syncMarker);
    let parentPath = '/';
    if (idx !== -1) {
      const relPath = parentDir.substring(idx + syncMarker.length);
      parentPath = relPath ? relPath + '/' : '/';
    }

    meta = {
      new: true,
      canonicalPath: parentPath + pageName + '/',
      pageId: 0,
      template: '',
      parentPath,
      pageName,
    } as PageMeta & { new: boolean; template: string; parentPath: string; pageName: string };
  }

  const shouldPushLocal  = targets === 'local'  || targets === 'both';
  const shouldPushRemote = targets === 'remote' || targets === 'both';

  if (shouldPushRemote && (!process.env.PW_REMOTE_URL || !process.env.PW_REMOTE_KEY)) {
    return {
      success: false,
      error: 'Remote publish requires PW_REMOTE_URL and PW_REMOTE_KEY in your MCP server env.',
    };
  }

  const results: Record<string, unknown> = {};
  let hasFailure = false;

  // ── LOCAL PUBLISH ──────────────────────────────────────────────────────────
  if (shouldPushLocal) {
    const localResult = await publishToLocal(yamlPath, dryRun, published);
    if (!localResult.success) hasFailure = true;
    results['local'] = localResult.success ? localResult.data : { error: localResult.error };
  }

  // ── REMOTE PUBLISH ─────────────────────────────────────────────────────────
  if (shouldPushRemote) {
    const parsedFields = await parsePageYaml(yamlPath).catch(() => null);
    if (!parsedFields) {
      hasFailure = true;
      results['remote'] = { error: `Failed to parse YAML at ${yamlPath} — cannot publish with empty fields` };
    } else {
      const template   = meta.template   ?? '';
      const pathParts  = (meta.canonicalPath ?? '').split('/').filter(Boolean);
      const pageName   = meta.pageName ?? pathParts[pathParts.length - 1] ?? '';
      // Derive parentPath from canonicalPath when not explicitly set on the meta.
      // Pulled pages only carry canonicalPath; without this the publish would
      // default to '/' and create the page at the site root.
      const derivedParentPath = pathParts.length > 1
        ? '/' + pathParts.slice(0, -1).join('/') + '/'
        : '/';
      const parentPath = meta.parentPath ?? derivedParentPath;

      const remoteResult = await runRemoteCommand(
        'page:create',
        [template, parentPath, pageName, ...(dryRun ? [] : ['--dry-run=0'])],
        undefined, undefined, undefined,
        { fields: parsedFields, published },
      );
      if (!remoteResult.success) hasFailure = true;
      results['remote'] = remoteResult.success ? remoteResult.data : { error: remoteResult.error };

      // v1.10.1: record the freshly-created remote id into the local meta
      // so the local page.meta.json now knows both sides' ids. Same
      // best-effort, dry-run-aware behaviour as the pushPage equivalent.
      if (remoteResult.success && !dryRun) {
        await recordRemoteIdInMeta(metaPath, remoteResult.data);
      }
    }
  }

  return {
    success: !hasFailure,
    ...(hasFailure ? { error: 'One or more publish targets failed — check results for details' } : {}),
    data: { dryRun, published, targets, results },
  };
}

/**
 * Update the local page.meta.json to record the remote pageId after a
 * successful live remote push or publish.
 *
 * Why this matters: prior to v1.10.1 the local meta only ever knew the
 * id of whichever side last wrote it. Recording the remote id back into
 * the LOCAL meta after a successful remote operation closes the loop —
 * the local meta now carries both sides' ids, exactly as if the user
 * had pulled from both sides in turn, but without the second round-trip.
 *
 * Best-effort: if the local meta is missing or unreadable, we silently
 * skip rather than failing the push. The remote push already succeeded;
 * the next `pw_page_pull` on this directory will fix the meta.
 */
async function recordRemoteIdInMeta(metaPath: string, remoteData: unknown): Promise<void> {
  if (!existsSync(metaPath)) return;
  if (!remoteData || typeof remoteData !== 'object') return;

  // page:update / page:create both return { pageId } on success.
  const pageId = (remoteData as { pageId?: number; id?: number }).pageId
    ?? (remoteData as { pageId?: number; id?: number }).id;
  if (typeof pageId !== 'number' || pageId <= 0) return;

  try {
    const raw  = await readFile(metaPath, 'utf8');
    const meta = JSON.parse(raw) as Record<string, unknown>;

    const existingIds = (meta.ids && typeof meta.ids === 'object')
      ? meta.ids as Record<string, unknown>
      : {};

    existingIds.remote = {
      id:         pageId,
      lastSeenAt: new Date().toISOString(),
    };

    meta.ids = existingIds;
    await writeFile(metaPath, JSON.stringify(meta, null, 2), 'utf8');
  } catch {
    // Best-effort — see docblock.
  }
}

async function publishToLocal(yamlPath: string, dryRun: boolean, published: boolean): Promise<PwCommandResult> {
  const phpPath = process.env.PHP_PATH || 'php';
  const pwPath  = process.env.PW_PATH;
  if (!pwPath) return { success: false, error: 'PW_PATH not set' };

  const cliPath = process.env.PROMPTWIRE_CLI_PATH || `${pwPath}/site/modules/PromptWire/bin/promptwire.php`;
  const cmdArgs = [cliPath, 'page:publish', yamlPath];
  if (!dryRun)   cmdArgs.push('--dry-run=0');
  if (published) cmdArgs.push('--published');

  try {
    const { stdout, stderr } = await execFileAsync(phpPath, cmdArgs, {
      env: { ...process.env, PW_PATH: pwPath },
      timeout: 30_000,
      maxBuffer: 10 * 1024 * 1024,
    });
    if (stderr?.trim()) console.error('Local publish stderr:', stderr);
    const data = JSON.parse(stdout);
    return data.error ? { success: false, error: data.error } : { success: true, data };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : 'Local publish failed' };
  }
}

function isFileRef(value: unknown): boolean {
  return (
    typeof value === 'object' &&
    value !== null &&
    !Array.isArray(value) &&
    '_file' in value
  );
}

// ============================================================================
// BULK PUSH (v1.8.2+)
// ============================================================================

export interface PushPagesBulkOptions {
  directory: string;
  dryRun?:   boolean;
  force?:    boolean;
  targets?:  PushTarget;
  publish?:  boolean;
}

interface BulkPushItem {
  syncDir: string;
  pagePath: string;
  success: boolean;
  error?: string;
  result?: unknown;
}

/**
 * Walk a sync directory tree, find every page.yaml, and push each page to the
 * chosen target(s). Reuses the proven per-page pushPage() logic so all the
 * cross-environment safeguards (path-based page lookup, _pageRef resolution,
 * file refs) apply uniformly to every page in the tree.
 *
 * Sorts by canonical PW path so parents are pushed before children, which
 * matters when child pages are new and need a parent that was just created.
 *
 * Pages are pushed serially. Pushing in parallel would race on the remote
 * page tree mutex and produce confusing "page already exists" errors.
 */
export async function pushPagesBulk(opts: PushPagesBulkOptions): Promise<PwCommandResult> {
  const {
    directory,
    dryRun  = true,
    force   = false,
    targets = 'remote',
    publish = false,
  } = opts;

  if (!existsSync(directory)) {
    return { success: false, error: `Directory not found: ${directory}` };
  }

  if (targets === 'remote' || targets === 'both') {
    const remoteUrl = process.env.PW_REMOTE_URL;
    const remoteKey = process.env.PW_REMOTE_KEY;
    if (!remoteUrl || !remoteKey) {
      return {
        success: false,
        error:
          'Bulk remote push requires PW_REMOTE_URL and PW_REMOTE_KEY in this MCP server\'s env. ' +
          'Add them to the local MCP server entry in .cursor/mcp.json.',
      };
    }
  }

  // Discover all page.yaml files under the directory.
  const yamlPaths: string[] = [];
  await walkForYaml(directory, yamlPaths);

  if (yamlPaths.length === 0) {
    return {
      success: true,
      data: {
        directory,
        targets,
        dryRun,
        pushed: 0,
        message: 'No page.yaml files found under directory.',
      },
    };
  }

  // Read meta to derive canonical path for ordering.
  const candidates: { syncDir: string; pagePath: string }[] = [];
  for (const yamlPath of yamlPaths) {
    const syncDir = path.dirname(yamlPath);
    const metaPath = path.join(syncDir, 'page.meta.json');
    let pagePath = syncDir;
    if (existsSync(metaPath)) {
      try {
        const meta = JSON.parse(await readFile(metaPath, 'utf-8')) as PageMeta;
        pagePath = meta.canonicalPath ?? meta.path ?? syncDir;
      } catch {
        // Fall back to syncDir for ordering — pushPage will surface the error.
      }
    }
    candidates.push({ syncDir, pagePath });
  }

  // Parents before children so newly-created parents exist when their kids push.
  candidates.sort((a, b) => a.pagePath.localeCompare(b.pagePath));

  const items: BulkPushItem[] = [];
  let succeeded = 0;
  let failed    = 0;

  for (const { syncDir, pagePath } of candidates) {
    const pushResult = await pushPage({
      localPath: syncDir,
      dryRun,
      force,
      targets,
      publish,
    });

    if (pushResult.success) succeeded++; else failed++;

    items.push({
      syncDir,
      pagePath,
      success: pushResult.success,
      ...(pushResult.success ? { result: pushResult.data } : { error: pushResult.error }),
    });
  }

  return {
    success: failed === 0,
    ...(failed > 0 ? { error: `${failed} of ${items.length} pages failed to push — check items[] for details` } : {}),
    data: {
      directory,
      targets,
      dryRun,
      total:     items.length,
      succeeded,
      failed,
      items,
    },
  };
}

/**
 * Recursively collect all page.yaml file paths under `dir`.
 * Skips hidden directories (.git, .DS_Store) and node_modules for safety.
 */
async function walkForYaml(dir: string, out: string[]): Promise<void> {
  let entries;
  try {
    entries = await readdir(dir, { withFileTypes: true });
  } catch {
    return;
  }

  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name.startsWith('.') || entry.name === 'node_modules') continue;
      await walkForYaml(full, out);
    } else if (entry.isFile() && entry.name === 'page.yaml') {
      out.push(full);
    }
  }
}
