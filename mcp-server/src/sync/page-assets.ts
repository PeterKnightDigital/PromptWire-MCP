/**
 * PromptWire Page Assets Sync
 *
 * Synchronises the on-disk asset directory for a page
 * (`site/assets/files/{pageId}/`) between local and remote ProcessWire
 * sites. Distinct from `pages/file-sync.ts`, which iterates a page's
 * file/image fieldgroup and only sees files attached as `Pagefiles`.
 *
 * The page-assets approach walks the directory directly and catches
 * files that:
 *
 *   - Were uploaded via the standard `InputfieldFile` / `InputfieldImage`
 *     widgets — these end up in the same per-page directory.
 *   - Were placed there by custom modules (notably MediaHub) that store
 *     module-managed files keyed by page id but do not expose them via
 *     `$page->template->fieldgroup` iteration. The previous field-aware
 *     path skipped all of these silently.
 *
 * PW image variations (`name.WxH[-suffix].ext`) are filtered by default —
 * they're regenerated on demand from originals and would otherwise produce
 * noisy diffs purely because each environment has a different cache state.
 *
 * Two directions are supported:
 *
 *   - local → remote: read each missing/changed file from local disk,
 *     base64-encode it, and POST `page-assets:upload` to the remote API.
 *     Optionally deletes remote-only files via `page-assets:delete`.
 *   - remote → local: pulls each missing/changed file from the remote
 *     API via `page-assets:download` (returns the file as base64), writes
 *     it to local disk, and optionally removes local-only files. Closes
 *     the gap left open by the v1.7 pages/file-sync, which explicitly
 *     refused remote → local with "use SFTP".
 *
 * Pages are matched by canonical PW path so the local and remote pageIds
 * can differ — only the pageId on the side being read/written is used to
 * resolve the on-disk directory.
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { mkdir, readdir, readFile, stat, unlink, writeFile, utimes } from 'fs/promises';
import { existsSync } from 'fs';
import path from 'path';
import { createHash } from 'crypto';
import { runRemoteCommand } from '../remote/client.js';
import { runPwCommand } from '../cli/runner.js';
import type { PwCommandResult } from '../cli/runner.js';

// ============================================================================
// TYPES
// ============================================================================

export type PageAssetsDirection = 'local-to-remote' | 'remote-to-local';

export interface PageAssetEntry {
  relativePath: string;
  size:         number;
  md5:          string;
  modified?:    string;
}

export interface PageAssetInventory {
  pageId:   number;
  pagePath: string;
  template?: string;
  assets:   PageAssetEntry[];
}

export interface PageAssetDiff {
  pagePath:    string;
  toUpload:    PageAssetEntry[];   // present locally, missing/changed remotely (l→r)
  toDownload:  PageAssetEntry[];   // present remotely, missing/changed locally (r→l)
  toDeleteRemote: PageAssetEntry[];
  toDeleteLocal:  PageAssetEntry[];
  unchanged:   number;
}

export interface PageAssetSyncOptions {
  pageRef:           string;            // page id or PW path
  direction:         PageAssetsDirection;
  dryRun:            boolean;
  deleteOrphans:     boolean;
  includeVariations: boolean;
}

export interface PageAssetCompareOptions {
  excludeTemplates?: string[];
  includeVariations?: boolean;
}

// PW image variation pattern: name.WIDTHxHEIGHT[-suffix].ext
const VARIATION_PATTERN = /\.\d+x\d+(-[a-z0-9-]+)?\.[a-z]+$/i;

/**
 * Asset snapshot stored inside page.meta.json under the `pageAssets` key,
 * written by SyncManager::buildPageAssetSnapshot at pull time. Captures
 * the source side's view of site/assets/files/{pageId}/ as of the last
 * pull, so subsequent compare/push/pull calls can report what has drifted
 * since — without a fresh remote inventory call.
 */
interface PageAssetSnapshot {
  pageId:          number;
  capturedAt:      string;
  directoryExists: boolean;
  assetCount:      number;
  totalBytes:      number;
  directoryHash:   string | null;
  assets:          PageAssetEntry[];
}

interface PageMetaWithAssets {
  pageId?:        number;
  canonicalPath?: string;
  pageAssets?:    PageAssetSnapshot;
}

// ============================================================================
// LOCAL FILESYSTEM HELPERS
// ============================================================================

/**
 * Resolve a page id/path against the local PW CLI to a {pageId, pagePath}
 * pair. Both forms are accepted because callers will typically have one or
 * the other depending on how they entered the workflow.
 */
async function resolveLocalPage(pageRef: string): Promise<{ pageId: number; pagePath: string } | null> {
  const result = await runPwCommand('get-page', [pageRef, '--summary']);
  if (!result.success || !result.data) return null;
  const data = result.data as { id?: number; path?: string };
  if (!data.id || !data.path) return null;
  return { pageId: data.id, pagePath: data.path };
}

async function buildLocalInventory(
  pwPath: string,
  pageId: number,
  includeVariations: boolean,
): Promise<PageAssetEntry[]> {
  const pageDir = path.join(pwPath, 'site', 'assets', 'files', String(pageId));
  if (!existsSync(pageDir)) return [];

  const entries: PageAssetEntry[] = [];
  await walk(pageDir, '', entries, includeVariations);
  entries.sort((a, b) => a.relativePath.localeCompare(b.relativePath));
  return entries;
}

async function walk(
  baseDir: string,
  relPrefix: string,
  out: PageAssetEntry[],
  includeVariations: boolean,
): Promise<void> {
  let dirents;
  try {
    dirents = await readdir(path.join(baseDir, relPrefix), { withFileTypes: true });
  } catch {
    return;
  }
  for (const ent of dirents) {
    if (ent.name.startsWith('.')) continue;
    const childRel = relPrefix ? path.join(relPrefix, ent.name) : ent.name;
    if (ent.isDirectory()) {
      await walk(baseDir, childRel, out, includeVariations);
      continue;
    }
    if (!ent.isFile()) continue;
    if (!includeVariations && VARIATION_PATTERN.test(ent.name)) continue;

    const full = path.join(baseDir, childRel);
    const st   = await stat(full);
    const buf  = await readFile(full);
    const md5  = createHash('md5').update(buf).digest('hex');
    out.push({
      relativePath: childRel.split(path.sep).join('/'),
      size:         st.size,
      md5,
      modified:     new Date(st.mtimeMs).toISOString(),
    });
  }
}

// ============================================================================
// REMOTE INVENTORY
// ============================================================================

async function fetchRemoteInventory(
  pageRef: string,
  includeVariations: boolean,
): Promise<PageAssetInventory | null> {
  const args = [pageRef];
  if (includeVariations) args.push('--include-variations');
  const result = await runRemoteCommand('page-assets:inventory', args);
  if (!result.success || !result.data) return null;
  const data = result.data as PageAssetInventory;
  if (typeof data.pageId !== 'number') return null;
  return data;
}

// ============================================================================
// SNAPSHOT (page.meta.json `pageAssets` key)
// ============================================================================

/**
 * Locate the page.meta.json that mirrors the given canonical PW path under
 * the local sync tree (site/assets/pw-mcp/<path>/page.meta.json), parse it,
 * and return the embedded asset snapshot — or null if the page has never
 * been pulled, or if the meta predates v1.10.0 (so the pageAssets key is
 * missing). Callers treat null as "no baseline to compare against" and
 * fall back to the live inventory.
 */
async function loadPulledSnapshot(
  pwPath: string,
  canonicalPath: string,
): Promise<PageAssetSnapshot | null> {
  const trimmed = canonicalPath.replace(/^\/+|\/+$/g, '');
  const segments = trimmed === '' ? ['home'] : trimmed.split('/');
  const metaPath = path.join(pwPath, 'site', 'assets', 'pw-mcp', ...segments, 'page.meta.json');

  if (!existsSync(metaPath)) return null;

  try {
    const raw = await readFile(metaPath, 'utf-8');
    const meta = JSON.parse(raw) as PageMetaWithAssets;
    if (!meta.pageAssets || typeof meta.pageAssets !== 'object') return null;
    return meta.pageAssets;
  } catch {
    return null;
  }
}

/**
 * Diff a live inventory against the snapshot captured at pull time.
 *
 * Useful for two questions:
 *   - Local: "what has changed in site/assets/files/{localId}/ since I
 *     last pulled?" — answered without a remote round-trip.
 *   - Remote: "what has changed on the remote side since I pulled?" —
 *     when the snapshot came from a remote pull, this surfaces direct
 *     production edits made between the pull and now.
 */
function diffAgainstSnapshot(
  current: PageAssetEntry[],
  snapshot: PageAssetSnapshot,
): {
  capturedAt:    string;
  unchanged:     number;
  added:         string[];
  removed:       string[];
  modified:      string[];
  totalDrifted:  number;
} {
  const snap = new Map(snapshot.assets.map(a => [a.relativePath, a]));
  const cur  = new Map(current.map(a => [a.relativePath, a]));

  const added:    string[] = [];
  const removed:  string[] = [];
  const modified: string[] = [];
  let   unchanged = 0;

  for (const [rel, cf] of cur) {
    const sf = snap.get(rel);
    if (!sf) added.push(rel);
    else if (sf.md5 !== cf.md5) modified.push(rel);
    else unchanged++;
  }
  for (const rel of snap.keys()) {
    if (!cur.has(rel)) removed.push(rel);
  }

  return {
    capturedAt:   snapshot.capturedAt,
    unchanged,
    added,
    removed,
    modified,
    totalDrifted: added.length + removed.length + modified.length,
  };
}

// ============================================================================
// DIFF
// ============================================================================

/**
 * Build the per-page diff between two inventories. Direction-agnostic; the
 * sync caller picks which side(s) of the diff to act on.
 */
export function diffAssets(local: PageAssetEntry[], remote: PageAssetEntry[]): {
  changed:    Array<{ relativePath: string; localMd5: string; remoteMd5: string }>;
  localOnly:  PageAssetEntry[];
  remoteOnly: PageAssetEntry[];
  unchanged:  number;
} {
  const localByPath  = new Map(local.map(f => [f.relativePath, f]));
  const remoteByPath = new Map(remote.map(f => [f.relativePath, f]));
  const all = new Set([...localByPath.keys(), ...remoteByPath.keys()]);

  const changed: Array<{ relativePath: string; localMd5: string; remoteMd5: string }> = [];
  const localOnly: PageAssetEntry[] = [];
  const remoteOnly: PageAssetEntry[] = [];
  let unchanged = 0;

  for (const rel of all) {
    const lf = localByPath.get(rel);
    const rf = remoteByPath.get(rel);
    if (lf && rf) {
      if (lf.md5 === rf.md5) {
        unchanged++;
      } else {
        changed.push({ relativePath: rel, localMd5: lf.md5, remoteMd5: rf.md5 });
      }
    } else if (lf) {
      localOnly.push(lf);
    } else if (rf) {
      remoteOnly.push(rf);
    }
  }

  return { changed, localOnly, remoteOnly, unchanged };
}

// ============================================================================
// SYNC
// ============================================================================

/**
 * Sync the assets directory of a single page in the chosen direction.
 *
 * Returns a structured report with per-file outcomes. Failures of
 * individual files are non-fatal — the report shows which files
 * succeeded / failed so the operator can re-try on the smaller set.
 */
export async function syncPageAssets(opts: PageAssetSyncOptions): Promise<PwCommandResult> {
  const pwPath = process.env.PW_PATH;
  if (!pwPath) {
    return { success: false, error: 'PW_PATH not set — page-assets sync needs the local PW root.' };
  }
  if (!process.env.PW_REMOTE_URL || !process.env.PW_REMOTE_KEY) {
    return {
      success: false,
      error: 'page-assets sync requires PW_REMOTE_URL and PW_REMOTE_KEY in the MCP server env.',
    };
  }

  // Resolve the local page so we have both id (for the on-disk dir) and
  // path (for the remote lookup, since ids don't match across envs).
  //
  // Cross-environment ID handling, in detail:
  //   - The on-disk asset directory on each side is named by THAT side's
  //     own pageId. Local 1234 and remote 5678 may both legitimately
  //     resolve to the same canonical PW path (`/about/`) — this is the
  //     normal state of any two sites that started from independent fresh
  //     installs rather than a database clone.
  //   - We resolve the local page first to learn the local id and the
  //     canonical path. The remote inventory call uses the canonical
  //     PATH (not the local id) so the remote side can resolve to ITS
  //     own id and walk ITS own asset directory. Same way page content
  //     sync already works.
  //   - If the caller passed a numeric pageRef (e.g. `1234`), it is
  //     resolved against the LOCAL site. That id is then translated to
  //     the corresponding remote page via canonical path. This avoids the
  //     trap where a numeric id passed by the operator silently picks up
  //     the wrong page on the remote because the auto-increment sequences
  //     diverged.
  const local = await resolveLocalPage(opts.pageRef);
  if (!local) {
    return {
      success: false,
      error:
        `Page not found locally: ${opts.pageRef}. ` +
        'pw_page_assets resolves the pageRef on the LOCAL site first, then ' +
        'uses the canonical PW path to find the corresponding page on the remote ' +
        '(so id drift between environments is handled automatically). If you only ' +
        'know the remote id, look up its path on the remote with pw_get_page first.',
    };
  }

  const localAssets  = await buildLocalInventory(pwPath, local.pageId, opts.includeVariations);
  const remote       = await fetchRemoteInventory(local.pagePath, opts.includeVariations);
  if (!remote) {
    return {
      success: false,
      error:
        `Failed to fetch remote inventory for ${local.pagePath}. ` +
        'Confirm the page exists on the remote site and the remote PromptWire is at v1.10.0+ ' +
        '(page-assets:inventory was added in that release).',
    };
  }

  // If this page has been pulled into the local sync tree at any point on
  // or after v1.10.0, page.meta.json carries a `pageAssets` snapshot of the
  // source side's view of the asset directory at pull time. Use it to
  // surface drift-since-pull on whichever side the snapshot represents
  // (the snapshot's pageId tells us which env it came from). This is purely
  // additive — when the snapshot is missing or the meta predates v1.10.0,
  // the existing local↔remote diff is unaffected.
  const snapshot = await loadPulledSnapshot(pwPath, local.pagePath);
  let driftSinceLastPull: ReturnType<typeof diffAgainstSnapshot> & {
    snapshotSide: 'local' | 'remote' | 'unknown';
  } | undefined;
  if (snapshot) {
    const compareSide: PageAssetEntry[] = snapshot.pageId === local.pageId
      ? localAssets
      : (snapshot.pageId === remote.pageId ? remote.assets : localAssets);
    const side: 'local' | 'remote' | 'unknown' = snapshot.pageId === local.pageId
      ? 'local'
      : (snapshot.pageId === remote.pageId ? 'remote' : 'unknown');
    driftSinceLastPull = { ...diffAgainstSnapshot(compareSide, snapshot), snapshotSide: side };
  }

  // Surface the per-side ids and the drift flag in every result so the
  // operator can see at a glance which physical disk directory was read
  // / written on each environment. Also catches the failure mode where
  // the same path resolves to two unrelated pages (e.g. local /about/ is
  // a basic-page but remote /about/ is a redirect template) — the asset
  // diff will be huge and the id-drift line will explain why.
  const idDrift = local.pageId !== remote.pageId;

  const diff = diffAssets(localAssets, remote.assets);

  // Build action lists keyed by direction.
  const toTransfer: PageAssetEntry[] = opts.direction === 'local-to-remote'
    ? [...diff.localOnly, ...diff.changed.map(c => localAssets.find(a => a.relativePath === c.relativePath)!).filter(Boolean)]
    : [...diff.remoteOnly, ...diff.changed.map(c => remote.assets.find(a => a.relativePath === c.relativePath)!).filter(Boolean)];

  const toDelete: PageAssetEntry[] = opts.deleteOrphans
    ? (opts.direction === 'local-to-remote' ? diff.remoteOnly : diff.localOnly)
    : [];

  if (opts.dryRun) {
    return {
      success: true,
      data: {
        pagePath:     local.pagePath,
        localPageId:  local.pageId,
        remotePageId: remote.pageId,
        idDrift,
        direction:    opts.direction,
        dryRun:       true,
        summary: {
          unchanged:  diff.unchanged,
          changed:    diff.changed.length,
          localOnly:  diff.localOnly.length,
          remoteOnly: diff.remoteOnly.length,
          toTransfer: toTransfer.length,
          toDelete:   toDelete.length,
        },
        toTransfer: toTransfer.map(f => f.relativePath),
        toDelete:   toDelete.map(f => f.relativePath),
        changed:    diff.changed,
        ...(driftSinceLastPull ? { driftSinceLastPull } : {}),
      },
    };
  }

  // Execute transfers.
  const transferResults: Array<Record<string, unknown>> = [];
  if (opts.direction === 'local-to-remote') {
    for (const entry of toTransfer) {
      const localFull = path.join(pwPath, 'site', 'assets', 'files', String(local.pageId), entry.relativePath);
      try {
        const buf = await readFile(localFull);
        const result = await runRemoteCommand(
          'page-assets:upload',
          [local.pagePath, '--dry-run=0'],
          undefined, undefined, undefined, undefined,
          {
            filename: entry.relativePath,
            data:     buf.toString('base64'),
            modified: entry.modified,
          },
        );
        transferResults.push({
          filename: entry.relativePath,
          success:  result.success,
          ...(result.success ? {} : { error: result.error }),
        });
      } catch (err) {
        transferResults.push({
          filename: entry.relativePath,
          success: false,
          error: `Failed to read local file: ${(err as Error).message}`,
        });
      }
    }
  } else {
    // remote → local: download via API and write to disk.
    const localPageDir = path.join(pwPath, 'site', 'assets', 'files', String(local.pageId));
    if (!existsSync(localPageDir)) {
      await mkdir(localPageDir, { recursive: true });
    }

    for (const entry of toTransfer) {
      const dlResult = await runRemoteCommand(
        'page-assets:download',
        [local.pagePath, `--filename=${entry.relativePath}`],
      );
      if (!dlResult.success || !dlResult.data) {
        transferResults.push({
          filename: entry.relativePath,
          success:  false,
          error:    dlResult.error ?? 'No data returned from remote',
        });
        continue;
      }

      const payload = dlResult.data as { data?: string; md5?: string; modified?: string };
      if (!payload.data) {
        transferResults.push({
          filename: entry.relativePath,
          success:  false,
          error:    'Remote response missing base64 data',
        });
        continue;
      }

      try {
        const targetPath = path.join(localPageDir, entry.relativePath);
        await mkdir(path.dirname(targetPath), { recursive: true });
        const buf = Buffer.from(payload.data, 'base64');
        await writeFile(targetPath, buf);
        if (payload.modified) {
          const ts = Date.parse(payload.modified) / 1000;
          if (!Number.isNaN(ts)) {
            await utimes(targetPath, ts, ts);
          }
        }
        transferResults.push({
          filename: entry.relativePath,
          success:  true,
          size:     buf.byteLength,
        });
      } catch (err) {
        transferResults.push({
          filename: entry.relativePath,
          success:  false,
          error:    `Failed to write file: ${(err as Error).message}`,
        });
      }
    }
  }

  // Execute deletions.
  const deleteResults: Array<Record<string, unknown>> = [];
  for (const entry of toDelete) {
    if (opts.direction === 'local-to-remote') {
      const result = await runRemoteCommand(
        'page-assets:delete',
        [local.pagePath, '--dry-run=0'],
        undefined, undefined, undefined, undefined,
        { filename: entry.relativePath },
      );
      deleteResults.push({
        filename: entry.relativePath,
        success:  result.success,
        ...(result.success ? {} : { error: result.error }),
      });
    } else {
      const localFull = path.join(pwPath, 'site', 'assets', 'files', String(local.pageId), entry.relativePath);
      try {
        await unlink(localFull);
        deleteResults.push({ filename: entry.relativePath, success: true });
      } catch (err) {
        deleteResults.push({
          filename: entry.relativePath,
          success: false,
          error:   (err as Error).message,
        });
      }
    }
  }

  const transferred = transferResults.filter(r => r.success).length;
  const transferFailed = transferResults.filter(r => !r.success).length;
  const deleted = deleteResults.filter(r => r.success).length;
  const deleteFailed = deleteResults.filter(r => !r.success).length;

  return {
    success: transferFailed === 0 && deleteFailed === 0,
    ...(transferFailed + deleteFailed > 0
      ? { error: `${transferFailed + deleteFailed} asset operation(s) failed — see details for which files.` }
      : {}),
    data: {
      pagePath:     local.pagePath,
      localPageId:  local.pageId,
      remotePageId: remote.pageId,
      idDrift,
      direction:    opts.direction,
      dryRun:       false,
      summary: {
        unchanged:    diff.unchanged,
        transferred,
        transferFailed,
        deleted,
        deleteFailed,
      },
      transfers: transferResults,
      deletes:   deleteResults,
      ...(driftSinceLastPull ? { driftSinceLastPull } : {}),
    },
  };
}

// ============================================================================
// PER-PAGE COMPARE (used by pw_site_compare)
// ============================================================================

export interface SiteAssetCompareEntry {
  pagePath:   string;
  template?:  string;
  /**
   * The same canonical PW path is resolved independently on each side, so
   * a page legitimately can have different DB ids on local vs remote (any
   * site that was created from a fresh schema rather than a DB clone will
   * have its own auto-increment sequence). Both ids are surfaced so the
   * operator can see at a glance whether they match — and so any tooling
   * downstream (logging, audit, on-disk path display) can render the
   * correct id per side rather than guessing.
   */
  localPageId?:  number;
  remotePageId?: number;
  idDrift:        boolean;
  changed:    number;
  localOnly:  number;
  remoteOnly: number;
  identical:  number;
  details?: {
    changed:    Array<{ relativePath: string; localMd5: string; remoteMd5: string }>;
    localOnly:  string[];
    remoteOnly: string[];
  };
}

export interface SiteAssetCompareResult {
  pagesCompared:  number;
  pagesIdentical: number;
  pagesDiffer:    number;
  /**
   * Number of pages that exist on both sides but with different DB ids.
   * Surfaced separately because the operator usually wants to know this
   * up front — it tells them the two sites diverged from independent
   * fresh installs rather than a DB clone, which has implications well
   * beyond the page-assets feature (cross-site Page references, hardcoded
   * page ids in template code, etc.).
   */
  pagesWithIdDrift: number;
  totals: {
    changed:    number;
    localOnly:  number;
    remoteOnly: number;
  };
  diffs: SiteAssetCompareEntry[];
}

/**
 * Compare on-disk page assets between local and remote across all pages
 * that have an assets directory on either side. Returns a per-page summary
 * of what differs (no transfers; this is the read-only side that feeds
 * pw_site_compare's report).
 */
export async function compareSiteAssets(
  options: PageAssetCompareOptions = {},
): Promise<PwCommandResult> {
  const pwPath = process.env.PW_PATH;
  if (!pwPath) {
    return { success: false, error: 'PW_PATH is required for page-assets comparison.' };
  }
  if (!process.env.PW_REMOTE_URL || !process.env.PW_REMOTE_KEY) {
    return { success: false, error: 'PW_REMOTE_URL and PW_REMOTE_KEY are required for page-assets comparison.' };
  }

  const includeVariations = options.includeVariations === true;
  const excludeTemplatesStr = (options.excludeTemplates ?? ['user', 'role', 'permission', 'admin']).join(',');

  const localArgs = ['--all-pages'];
  if (includeVariations) localArgs.push('--include-variations');
  if (excludeTemplatesStr) localArgs.push(`--exclude-templates=${excludeTemplatesStr}`);

  const remoteArgs = ['--all-pages'];
  if (includeVariations) remoteArgs.push('--include-variations');
  if (excludeTemplatesStr) remoteArgs.push(`--exclude-templates=${excludeTemplatesStr}`);

  const [localResult, remoteResult] = await Promise.all([
    runPwCommand('page-assets:inventory', localArgs),
    runRemoteCommand('page-assets:inventory', remoteArgs),
  ]);

  if (!localResult.success) {
    return { success: false, error: `Local page-assets inventory failed: ${localResult.error}` };
  }
  if (!remoteResult.success) {
    return { success: false, error: `Remote page-assets inventory failed: ${remoteResult.error}` };
  }

  const localPages  = (localResult.data  as { pages?: Record<string, PageAssetInventory> }).pages ?? {};
  const remotePages = (remoteResult.data as { pages?: Record<string, PageAssetInventory> }).pages ?? {};

  const allPaths = new Set([...Object.keys(localPages), ...Object.keys(remotePages)]);

  const diffs: SiteAssetCompareEntry[] = [];
  let pagesIdentical = 0;
  let pagesWithIdDrift = 0;
  let totalChanged = 0;
  let totalLocalOnly = 0;
  let totalRemoteOnly = 0;

  for (const pagePath of [...allPaths].sort()) {
    const lp = localPages[pagePath];
    const rp = remotePages[pagePath];
    const local  = lp?.assets ?? [];
    const remote = rp?.assets ?? [];

    // A page is considered "drifted" only when both sides have it AND the
    // ids differ. A page that exists on only one side is a different
    // problem (page-content drift, not id drift) and is already reported
    // by pw_site_compare's pages section.
    const idDrift = !!(lp && rp && lp.pageId !== rp.pageId);
    if (idDrift) pagesWithIdDrift++;

    const d = diffAssets(local, remote);
    if (d.changed.length === 0 && d.localOnly.length === 0 && d.remoteOnly.length === 0) {
      pagesIdentical++;
      continue;
    }

    totalChanged    += d.changed.length;
    totalLocalOnly  += d.localOnly.length;
    totalRemoteOnly += d.remoteOnly.length;

    diffs.push({
      pagePath,
      template:     lp?.template ?? rp?.template,
      localPageId:  lp?.pageId,
      remotePageId: rp?.pageId,
      idDrift,
      changed:    d.changed.length,
      localOnly:  d.localOnly.length,
      remoteOnly: d.remoteOnly.length,
      identical:  d.unchanged,
      details: {
        changed:    d.changed,
        localOnly:  d.localOnly.map(f => f.relativePath),
        remoteOnly: d.remoteOnly.map(f => f.relativePath),
      },
    });
  }

  const result: SiteAssetCompareResult = {
    pagesCompared:  allPaths.size,
    pagesIdentical,
    pagesDiffer:    diffs.length,
    pagesWithIdDrift,
    totals: {
      changed:    totalChanged,
      localOnly:  totalLocalOnly,
      remoteOnly: totalRemoteOnly,
    },
    diffs,
  };

  return { success: true, data: result };
}
