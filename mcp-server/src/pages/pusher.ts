/**
 * PW-MCP Page Pusher
 *
 * Reads a locally-synced page.yaml file, resolves field values (including
 * _file references), and pushes the content to local PW, remote PW, or both.
 *
 * Targets:
 *   'local'      — push to the local MAMP/dev ProcessWire via PHP CLI
 *   'remote'     — push to the remote production site via HTTP API
 *   'both'       — push to local first, then remote
 *
 * @package     PwMcp
 * @subpackage  MCP Server
 * @author      Peter Knight
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

  // ── LOCAL PUSH ─────────────────────────────────────────────────────────────
  if (shouldPushLocal) {
    const localResult = await pushToLocal(yamlPath, dryRun, force);
    results['local'] = localResult.success ? localResult.data : { error: localResult.error };
  }

  // ── REMOTE PUSH ────────────────────────────────────────────────────────────
  if (shouldPushRemote) {
    const remoteResult = await pushToRemote(yamlPath, pagePath, dryRun, publish);
    results['remote'] = remoteResult.success ? remoteResult.data : { error: remoteResult.error };
  }

  return {
    success: true,
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

  const cliPath = process.env.PW_MCP_CLI_PATH ||
    `${pwPath}/site/modules/PwMcp/bin/pw-mcp.php`;

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

async function pushToRemote(yamlPath: string, pagePath: string, dryRun: boolean, publish = false): Promise<PwCommandResult> {
  // Parse the local YAML to extract field values
  let fields: FieldValue;
  try {
    fields = await parsePageYaml(yamlPath);
  } catch (err) {
    return { success: false, error: `Failed to parse page.yaml: ${err instanceof Error ? err.message : String(err)}` };
  }

  const args = [pagePath];
  if (!dryRun) args.push('--dry-run=0');

  return runRemoteCommand(
    'page:update',
    args,
    undefined,
    undefined,
    undefined,
    { fields, publish },
  );
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

    // Skip complex non-scalar fields (images, page refs) — send only text/scalar fields
    // to avoid overwriting image or relationship data accidentally
    if (Array.isArray(value) && value.length === 0) continue;
    if (Array.isArray(value) && typeof value[0] === 'object') continue;

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
  if (!existsSync(metaPath)) return { success: false, error: `page.meta.json not found: ${metaPath}` };

  let meta: PageMeta & { new?: boolean; template?: string; parentPath?: string; pageName?: string; parentId?: number };
  try {
    meta = JSON.parse(await readFile(metaPath, 'utf-8'));
  } catch {
    return { success: false, error: 'Failed to read page.meta.json' };
  }

  if (!meta.new) {
    return {
      success: false,
      error: 'This page already exists in ProcessWire. Use pw_page_push to update it instead.',
    };
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

  // ── LOCAL PUBLISH ──────────────────────────────────────────────────────────
  if (shouldPushLocal) {
    const localResult = await publishToLocal(yamlPath, dryRun, published);
    results['local'] = localResult.success ? localResult.data : { error: localResult.error };
  }

  // ── REMOTE PUBLISH ─────────────────────────────────────────────────────────
  if (shouldPushRemote) {
    let fields: FieldValue = {};
    try { fields = await parsePageYaml(yamlPath); } catch { /* use empty */ }

    const template   = meta.template   ?? '';
    const parentPath = meta.parentPath ?? '/';
    // Derive pageName from canonicalPath if not stored explicitly
    const pathParts  = (meta.canonicalPath ?? '').split('/').filter(Boolean);
    const pageName   = meta.pageName ?? pathParts[pathParts.length - 1] ?? '';

    const remoteResult = await runRemoteCommand(
      'page:create',
      [template, parentPath, pageName, ...(dryRun ? [] : ['--dry-run=0'])],
      undefined, undefined, undefined,
      { fields, published },
    );
    results['remote'] = remoteResult.success ? remoteResult.data : { error: remoteResult.error };
  }

  return {
    success: true,
    data: { dryRun, published, targets, results },
  };
}

async function publishToLocal(yamlPath: string, dryRun: boolean, published: boolean): Promise<PwCommandResult> {
  const phpPath = process.env.PHP_PATH || 'php';
  const pwPath  = process.env.PW_PATH;
  if (!pwPath) return { success: false, error: 'PW_PATH not set' };

  const cliPath = process.env.PW_MCP_CLI_PATH || `${pwPath}/site/modules/PwMcp/bin/pw-mcp.php`;
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
