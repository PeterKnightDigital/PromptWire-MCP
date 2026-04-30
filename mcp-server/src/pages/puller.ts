/**
 * PromptWire Page Puller (remote source variant)
 *
 * Companion to pages/pusher.ts. Used by `pw_page_pull source: "remote"` to
 * fetch a page's editable content from a remote ProcessWire site over HTTP
 * and mirror it into the *local* sync tree, so edits made directly in the
 * production admin can be brought back to local for further work.
 *
 * The remote side runs `page:export-yaml` (added in v1.8.3) which returns
 * the YAML and meta inline as a single self-contained JSON response — no
 * filesystem writes happen on the production server, and no stray sync
 * directory is left behind.
 *
 * Cross-environment safety: writes are addressed by canonicalPath rather
 * than DB id so the local tree mirrors the remote URL structure even when
 * auto-increment sequences differ between environments.
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { mkdir, writeFile } from 'fs/promises';
import { createHash } from 'crypto';
import path from 'path';
import { runRemoteCommand } from '../remote/client.js';
import type { PwCommandResult } from '../cli/runner.js';

// ============================================================================
// TYPES
// ============================================================================

export interface PullPageFromRemoteOptions {
  /** Page ID or PW path (e.g. "/about/", "1192). */
  idOrPath: string;
  /**
   * Absolute path to the local PW install root (defaults to PW_PATH env).
   * The sync root inside it is always `site/assets/pw-mcp` (PW convention).
   */
  pwPath?:  string;
  /**
   * Optional override of the sync root within the local PW install.
   * Path is resolved relative to pwPath. Defaults to `site/assets/pw-mcp`
   * to match SyncManager's default.
   */
  syncRoot?: string;
}

interface ExportPayload {
  success?:       boolean;
  pageId:         number;
  canonicalPath:  string;
  template:       string;
  title:          string;
  yaml:           string;
  meta:           Record<string, unknown>;
  fieldCount:     number;
}

// ============================================================================
// MAIN ENTRY POINT
// ============================================================================

/**
 * Pull a single page from the remote site and write it into the local sync
 * tree. Mirrors the directory layout that local `page:pull` produces so the
 * downstream tooling (pushPage, sync:status, etc.) treats both origins
 * identically.
 *
 * Verifies the round-trip by re-hashing the YAML written to disk and
 * comparing it to the contentHash the remote returned. Surfaces a mismatch
 * as a non-fatal warning rather than failing the pull, because the YAML is
 * still on disk and useful — but the operator should know if the hash drifted
 * (indicates encoding issues or middleware mangling the response body).
 */
export async function pullPageFromRemote(
  opts: PullPageFromRemoteOptions
): Promise<PwCommandResult> {
  const { idOrPath } = opts;
  const pwPath = opts.pwPath ?? process.env.PW_PATH;

  if (!pwPath) {
    return {
      success: false,
      error:
        'pw_page_pull source="remote" needs PW_PATH so the fetched YAML can be written into the local sync tree. ' +
        'Set PW_PATH in the MCP server env (the same value the local CLI uses).',
    };
  }

  if (!process.env.PW_REMOTE_URL) {
    return {
      success: false,
      error:
        'pw_page_pull source="remote" needs PW_REMOTE_URL + PW_REMOTE_KEY in the MCP server env to reach the production API.',
    };
  }

  const syncRoot = path.resolve(
    pwPath,
    opts.syncRoot ?? 'site/assets/pw-mcp'
  );

  // 1. Fetch the YAML payload from the remote site.
  const remote = await runRemoteCommand('page:export-yaml', [idOrPath]);
  if (!remote.success) return remote;

  const payload = remote.data as ExportPayload | undefined;
  if (!payload || typeof payload.yaml !== 'string' || !payload.canonicalPath) {
    return {
      success: false,
      error:
        'Remote page:export-yaml returned an unexpected payload — make sure the remote PromptWire is at v1.8.3 or later.',
    };
  }

  // 2. Resolve the local sync directory using canonicalPath (mirrors PHP's
  // SyncManager::getLocalPath logic so layouts agree exactly).
  const localPagePath = canonicalPathToLocalDir(syncRoot, payload.canonicalPath);

  try {
    await mkdir(localPagePath, { recursive: true });
  } catch (err) {
    return {
      success: false,
      error: `Failed to create local sync dir ${localPagePath}: ${err instanceof Error ? err.message : String(err)}`,
    };
  }

  // 3. Write page.yaml and page.meta.json, exactly mirroring local pullPage.
  const yamlPath = path.join(localPagePath, 'page.yaml');
  const metaPath = path.join(localPagePath, 'page.meta.json');

  try {
    await writeFile(yamlPath, payload.yaml, 'utf8');
    await writeFile(metaPath, JSON.stringify(payload.meta, null, 2), 'utf8');
  } catch (err) {
    return {
      success: false,
      error: `Failed to write sync files: ${err instanceof Error ? err.message : String(err)}`,
    };
  }

  // 4. Re-hash the on-disk YAML and compare to the contentHash the remote
  // emitted. A mismatch is non-fatal but worth surfacing — the YAML is
  // already on disk, so the operator can still work with it.
  const localContentHash = createHash('md5').update(payload.yaml, 'utf8').digest('hex');
  const remoteContentHash = String(payload.meta?.contentHash ?? '');
  const hashMatch = remoteContentHash === '' || remoteContentHash === localContentHash;

  return {
    success: true,
    data: {
      pageId:        payload.pageId,
      canonicalPath: payload.canonicalPath,
      template:      payload.template,
      title:         payload.title,
      fieldCount:    payload.fieldCount,
      source:        'remote',
      localPath:     path.relative(pwPath, localPagePath),
      files: {
        yaml: path.relative(pwPath, yamlPath),
        meta: path.relative(pwPath, metaPath),
      },
      contentHashMatch: hashMatch,
      ...(hashMatch ? {} : {
        warning:
          `Remote contentHash ${remoteContentHash} != local re-hash ${localContentHash}. ` +
          'YAML is on disk and usable, but a hash mismatch can indicate response encoding issues.',
      }),
    },
  };
}

// ============================================================================
// PATH HELPERS
// ============================================================================

/**
 * Convert a canonical PW path ("/about/" or "/") to the local sync directory.
 * Matches SyncManager::getLocalPath behaviour:
 *   "/"                 → <syncRoot>/home
 *   "/about/"           → <syncRoot>/about
 *   "/services/seo/"    → <syncRoot>/services/seo
 */
function canonicalPathToLocalDir(syncRoot: string, canonicalPath: string): string {
  const trimmed = canonicalPath.replace(/^\/+|\/+$/g, '');
  if (trimmed === '') {
    return path.join(syncRoot, 'home');
  }
  return path.join(syncRoot, ...trimmed.split('/'));
}
