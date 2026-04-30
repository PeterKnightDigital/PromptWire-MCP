/**
 * PromptWire Site Comparison
 *
 * Compares two ProcessWire sites across three dimensions: pages (by content
 * hash), schema (fields + templates), and filesystem (template/module files).
 * Pages are matched by URL path, not page ID, so the comparison works across
 * environments with different auto-increment sequences.
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { runPwCommand, type PwCommandResult } from '../cli/runner.js';
import { runRemoteCommand } from '../remote/client.js';
import { compareSites as compareSchemas } from '../schema/compare.js';
import { compareSiteAssets, type SiteAssetCompareResult } from './page-assets.js';

// ============================================================================
// TYPES
// ============================================================================

export interface PageInventoryItem {
  id:          number;
  path:        string;
  template:    string;
  status:      number;
  modified:    string;
  created:     string;
  contentHash: string;
}

export interface PageInventory {
  siteName:    string;
  generatedAt: string;
  pageCount:   number;
  excluded:    string[];
  pages:       PageInventoryItem[];
}

export interface FileInventoryItem {
  relativePath: string;
  size:         number;
  md5:          string;
  modified:     string;
}

export interface FileInventory {
  siteName:    string;
  generatedAt: string;
  directories: string[];
  extensions:  string[];
  fileCount:   number;
  files:       FileInventoryItem[];
}

export interface PageDiffItem {
  path:     string;
  status:   'identical' | 'modified' | 'localOnly' | 'remoteOnly';
  template: string;
  localId?:       number;
  remoteId?:      number;
  localModified?:  string;
  remoteModified?: string;
  localHash?:  string;
  remoteHash?: string;
}

export interface FileDiffItem {
  relativePath: string;
  status:       'identical' | 'modified' | 'localOnly' | 'remoteOnly';
  localSize?:   number;
  remoteSize?:  number;
  localMd5?:    string;
  remoteMd5?:   string;
}

export interface SiteCompareResult {
  local:  string;
  remote: string;
  pages: {
    compared:    number;
    excluded:    number;
    identical:   number;
    modified:    PageDiffItem[];
    localOnly:   PageDiffItem[];
    remoteOnly:  PageDiffItem[];
  };
  schema: {
    fields:    { sourceOnly: number; targetOnly: number; changed: number; unchanged: number };
    templates: { sourceOnly: number; targetOnly: number; changed: number; unchanged: number };
  };
  files: {
    compared:   number;
    identical:  number;
    modified:   FileDiffItem[];
    localOnly:  FileDiffItem[];
    remoteOnly: FileDiffItem[];
  };
  pageAssets?: SiteAssetCompareResult & {
    /**
     * Set when the assets compare was attempted but the remote endpoint is
     * older than v1.10.0 (so page-assets:inventory does not exist there).
     * Carried as a non-fatal warning rather than failing the whole compare,
     * because the page/schema/file diffs are still valuable independently.
     */
    warning?: string;
  };
}

// ============================================================================
// INVENTORY FETCHERS
// ============================================================================

async function fetchLocalPageInventory(excludeTemplates: string): Promise<PageInventory | null> {
  const args = excludeTemplates ? ['--exclude-templates=' + excludeTemplates] : [];
  const result = await runPwCommand('site:inventory', args);
  if (!result.success) return null;
  return result.data as PageInventory;
}

async function fetchRemotePageInventory(excludeTemplates: string): Promise<PageInventory | null> {
  const remoteUrl = process.env.PW_REMOTE_URL;
  const remoteKey = process.env.PW_REMOTE_KEY;
  if (!remoteUrl || !remoteKey) return null;

  const args = excludeTemplates ? ['--exclude-templates=' + excludeTemplates] : [];
  const result = await runRemoteCommand('site:inventory', args, undefined, remoteUrl, remoteKey);
  if (!result.success) return null;
  return result.data as PageInventory;
}

async function fetchLocalFileInventory(
  directories: string,
  excludePatterns: string
): Promise<FileInventory | null> {
  const args = ['--directories=' + directories];
  if (excludePatterns) args.push('--exclude-patterns=' + excludePatterns);
  const result = await runPwCommand('files:inventory', args);
  if (!result.success) return null;
  return result.data as FileInventory;
}

async function fetchRemoteFileInventory(
  directories: string,
  excludePatterns: string
): Promise<FileInventory | null> {
  const remoteUrl = process.env.PW_REMOTE_URL;
  const remoteKey = process.env.PW_REMOTE_KEY;
  if (!remoteUrl || !remoteKey) return null;

  const args = ['--directories=' + directories];
  if (excludePatterns) args.push('--exclude-patterns=' + excludePatterns);
  const result = await runRemoteCommand('files:inventory', args, undefined, remoteUrl, remoteKey);
  if (!result.success) return null;
  return result.data as FileInventory;
}

// ============================================================================
// DIFF LOGIC
// ============================================================================

function diffPages(local: PageInventory, remote: PageInventory): SiteCompareResult['pages'] {
  const localByPath  = new Map(local.pages.map(p => [p.path, p]));
  const remoteByPath = new Map(remote.pages.map(p => [p.path, p]));

  const allPaths = new Set([...localByPath.keys(), ...remoteByPath.keys()]);

  let identical = 0;
  const modified:   PageDiffItem[] = [];
  const localOnly:  PageDiffItem[] = [];
  const remoteOnly: PageDiffItem[] = [];

  for (const path of allPaths) {
    const lp = localByPath.get(path);
    const rp = remoteByPath.get(path);

    if (lp && rp) {
      if (lp.contentHash === rp.contentHash) {
        identical++;
      } else {
        modified.push({
          path,
          status:   'modified',
          template: lp.template,
          localId:       lp.id,
          remoteId:      rp.id,
          localModified:  lp.modified,
          remoteModified: rp.modified,
          localHash:  lp.contentHash,
          remoteHash: rp.contentHash,
        });
      }
    } else if (lp && !rp) {
      localOnly.push({
        path,
        status:   'localOnly',
        template: lp.template,
        localId:  lp.id,
        localModified: lp.modified,
      });
    } else if (!lp && rp) {
      remoteOnly.push({
        path,
        status:   'remoteOnly',
        template: rp.template,
        remoteId: rp.id,
        remoteModified: rp.modified,
      });
    }
  }

  const totalExcluded = local.excluded.length > 0
    ? (local.pageCount + remote.pageCount) - allPaths.size
    : 0;

  return {
    compared:  allPaths.size,
    excluded:  totalExcluded,
    identical,
    modified,
    localOnly,
    remoteOnly,
  };
}

function diffFiles(local: FileInventory, remote: FileInventory): SiteCompareResult['files'] {
  const localByPath  = new Map(local.files.map(f => [f.relativePath, f]));
  const remoteByPath = new Map(remote.files.map(f => [f.relativePath, f]));

  const allPaths = new Set([...localByPath.keys(), ...remoteByPath.keys()]);

  let identical = 0;
  const modified:   FileDiffItem[] = [];
  const localOnly:  FileDiffItem[] = [];
  const remoteOnly: FileDiffItem[] = [];

  for (const rp of allPaths) {
    const lf = localByPath.get(rp);
    const rf = remoteByPath.get(rp);

    if (lf && rf) {
      if (lf.md5 === rf.md5) {
        identical++;
      } else {
        modified.push({
          relativePath: rp,
          status:    'modified',
          localSize:  lf.size,
          remoteSize: rf.size,
          localMd5:   lf.md5,
          remoteMd5:  rf.md5,
        });
      }
    } else if (lf && !rf) {
      localOnly.push({
        relativePath: rp,
        status:    'localOnly',
        localSize: lf.size,
        localMd5:  lf.md5,
      });
    } else if (!lf && rf) {
      remoteOnly.push({
        relativePath: rp,
        status:    'remoteOnly',
        remoteSize: rf.size,
        remoteMd5:  rf.md5,
      });
    }
  }

  return {
    compared:  allPaths.size,
    identical,
    modified,
    localOnly,
    remoteOnly,
  };
}

// ============================================================================
// MAIN COMPARE FUNCTION
// ============================================================================

export async function compareSites(options: {
  excludeTemplates?: string[];
  excludePages?: string[];
  includeDirs?: string[];
  excludeFilePatterns?: string[];
  /**
   * Include the per-page assets diff (site/assets/files/{pageId}/) in the
   * report. Defaults to true. Pass false to skip the extra inventory call
   * if you only care about pages/schema/templates and want a faster run.
   */
  includePageAssets?: boolean;
}): Promise<PwCommandResult> {
  const pwPath    = process.env.PW_PATH;
  const remoteUrl = process.env.PW_REMOTE_URL;
  const remoteKey = process.env.PW_REMOTE_KEY;

  if (!pwPath) {
    return { success: false, error: 'PW_PATH is required for site comparison (local site).' };
  }
  if (!remoteUrl || !remoteKey) {
    return { success: false, error: 'PW_REMOTE_URL and PW_REMOTE_KEY are required for site comparison (remote site).' };
  }

  const excludeTemplatesStr = (options.excludeTemplates ?? ['user', 'role', 'permission', 'admin']).join(',');
  const dirsStr = (options.includeDirs ?? ['site/templates', 'site/modules']).join(',');
  const excludeFilePatternsStr = (options.excludeFilePatterns ?? ['site/modules/PromptWire/*']).join(',');

  const wantPageAssets = options.includePageAssets !== false;

  // Fetch all four inventories in parallel. The page-assets compare is
  // additive — when the remote site is on an older PromptWire that doesn't
  // ship page-assets:inventory, the compare result carries a warning
  // instead of failing the whole report.
  const [
    localPages,
    remotePages,
    localFiles,
    remoteFiles,
    schemaResult,
    pageAssetsResult,
  ] = await Promise.all([
    fetchLocalPageInventory(excludeTemplatesStr),
    fetchRemotePageInventory(excludeTemplatesStr),
    fetchLocalFileInventory(dirsStr, excludeFilePatternsStr),
    fetchRemoteFileInventory(dirsStr, excludeFilePatternsStr),
    compareSchemas('current', 'production'),
    wantPageAssets
      ? compareSiteAssets({ excludeTemplates: options.excludeTemplates })
      : Promise.resolve<PwCommandResult>({ success: false, error: 'skipped' }),
  ]);

  if (!localPages) {
    return { success: false, error: 'Failed to fetch page inventory from local site.' };
  }
  if (!remotePages) {
    return { success: false, error: 'Failed to fetch page inventory from remote site. Check PW_REMOTE_URL and PW_REMOTE_KEY.' };
  }
  if (!localFiles) {
    return { success: false, error: 'Failed to fetch file inventory from local site.' };
  }
  if (!remoteFiles) {
    return { success: false, error: 'Failed to fetch file inventory from remote site.' };
  }

  // Diff pages
  let pageDiff = diffPages(localPages, remotePages);

  // Apply page path exclusions
  if (options.excludePages && options.excludePages.length > 0) {
    const excludePaths = new Set(options.excludePages);
    pageDiff = {
      ...pageDiff,
      modified:   pageDiff.modified.filter(p => !excludePaths.has(p.path)),
      localOnly:  pageDiff.localOnly.filter(p => !excludePaths.has(p.path)),
      remoteOnly: pageDiff.remoteOnly.filter(p => !excludePaths.has(p.path)),
    };
  }

  // Diff files
  const fileDiff = diffFiles(localFiles, remoteFiles);

  // Extract schema summary from the compare result
  let schemaSummary = {
    fields:    { sourceOnly: 0, targetOnly: 0, changed: 0, unchanged: 0 },
    templates: { sourceOnly: 0, targetOnly: 0, changed: 0, unchanged: 0 },
  };

  if (schemaResult.success && schemaResult.data) {
    const sd = schemaResult.data as {
      summary?: {
        fields?:    { safe?: number; warning?: number; danger?: number; info?: number; unchanged?: number };
        templates?: { safe?: number; warning?: number; danger?: number; info?: number; unchanged?: number };
      };
      fields?:    Record<string, { status?: string }>;
      templates?: Record<string, { status?: string }>;
    };

    if (sd.fields) {
      for (const item of Object.values(sd.fields)) {
        if (item.status === 'sourceOnly') schemaSummary.fields.sourceOnly++;
        else if (item.status === 'targetOnly') schemaSummary.fields.targetOnly++;
        else if (item.status === 'changed') schemaSummary.fields.changed++;
        else if (item.status === 'unchanged') schemaSummary.fields.unchanged++;
      }
    }
    if (sd.templates) {
      for (const item of Object.values(sd.templates)) {
        if (item.status === 'sourceOnly') schemaSummary.templates.sourceOnly++;
        else if (item.status === 'targetOnly') schemaSummary.templates.targetOnly++;
        else if (item.status === 'changed') schemaSummary.templates.changed++;
        else if (item.status === 'unchanged') schemaSummary.templates.unchanged++;
      }
    }
  }

  const result: SiteCompareResult = {
    local:  localPages.siteName,
    remote: remotePages.siteName,
    pages:  pageDiff,
    schema: schemaSummary,
    files:  fileDiff,
  };

  if (wantPageAssets) {
    if (pageAssetsResult.success && pageAssetsResult.data) {
      result.pageAssets = pageAssetsResult.data as SiteAssetCompareResult;
    } else if (pageAssetsResult.error && pageAssetsResult.error !== 'skipped') {
      // Non-fatal: surface as a warning so the rest of the report is usable
      // even when only the assets compare failed (e.g. remote API hasn't
      // been upgraded to v1.10.0 yet so page-assets:inventory is missing).
      result.pageAssets = {
        pagesCompared:  0,
        pagesIdentical: 0,
        pagesDiffer:    0,
        totals:         { changed: 0, localOnly: 0, remoteOnly: 0 },
        diffs:          [],
        warning:        `Page-assets compare unavailable: ${pageAssetsResult.error}`,
      };
    }
  }

  return { success: true, data: result };
}
