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

import { readFile, access } from 'fs/promises';
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
    }
  }

  return {
    success: !hasFailure,
    ...(hasFailure ? { error: 'One or more publish targets failed — check results for details' } : {}),
    data: { dryRun, published, targets, results },
  };
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
