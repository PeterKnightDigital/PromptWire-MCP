/**
 * PromptWire Site Sync
 *
 * Orchestrates a full site synchronisation: compare → backup → maintenance →
 * push schema / pages / files → verify → maintenance off.
 *
 * Uses existing PromptWire infrastructure for each step:
 * - WireDatabaseBackup (via backup:create) for database snapshots
 * - pw_schema_push for field/template sync
 * - pw_page_push / pw_page_publish for page content
 * - files:push for template/module file deployment
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import fs from 'fs';
import * as path from 'path';
import { runPwCommand, type PwCommandResult } from '../cli/runner.js';
import { runRemoteCommand } from '../remote/client.js';
import { compareSites, type SiteCompareResult, type FileDiffItem, type PageDiffItem } from './site-compare.js';
import { schemaPush } from '../schema/sync.js';
import { syncFiles } from '../pages/file-sync.js';

// ============================================================================
// TYPES
// ============================================================================

export interface SiteSyncOptions {
  direction:          'local-to-remote' | 'remote-to-local';
  scope:              'all' | 'pages' | 'schema' | 'files';
  excludeTemplates?:  string[];
  excludePages?:      string[];
  excludeFilePatterns?: string[];
  backup:             boolean;
  maintenance:        boolean;
  dryRun:             boolean;
}

interface StepResult {
  step:    string;
  success: boolean;
  detail:  Record<string, unknown>;
}

export interface SiteSyncResult {
  success:        boolean;
  dryRun:         boolean;
  direction:      string;
  scope:          string;
  steps:          StepResult[];
  backupId?:      string;
  maintenanceOn?: boolean;
  error?:         string;
}

// ============================================================================
// SYNC ORCHESTRATOR
// ============================================================================

export async function syncSites(options: SiteSyncOptions): Promise<PwCommandResult> {
  const pwPath    = process.env.PW_PATH;
  const remoteUrl = process.env.PW_REMOTE_URL;
  const remoteKey = process.env.PW_REMOTE_KEY;

  if (!pwPath) {
    return { success: false, error: 'PW_PATH is required for site sync (local site).' };
  }
  if (!remoteUrl || !remoteKey) {
    return { success: false, error: 'PW_REMOTE_URL and PW_REMOTE_KEY are required for site sync (remote site).' };
  }

  const result: SiteSyncResult = {
    success:   true,
    dryRun:    options.dryRun,
    direction: options.direction,
    scope:     options.scope,
    steps:     [],
  };

  // ── Step 1: Compare ─────────────────────────────────────────────────────
  const compareResult = await compareSites({
    excludeTemplates:    options.excludeTemplates,
    excludePages:        options.excludePages,
    excludeFilePatterns: options.excludeFilePatterns,
  });

  if (!compareResult.success || !compareResult.data) {
    return { success: false, error: `Comparison failed: ${compareResult.error}` };
  }

  const diff = compareResult.data as SiteCompareResult;
  result.steps.push({
    step: 'compare',
    success: true,
    detail: {
      pagesModified:  diff.pages.modified.length,
      pagesLocalOnly: diff.pages.localOnly.length,
      schemaChanged:  diff.schema.fields.changed + diff.schema.templates.changed,
      filesModified:  diff.files.modified.length,
      filesLocalOnly: diff.files.localOnly.length,
    },
  });

  const hasWork =
    diff.pages.modified.length > 0 ||
    diff.pages.localOnly.length > 0 ||
    diff.schema.fields.changed > 0 ||
    diff.schema.fields.sourceOnly > 0 ||
    diff.schema.templates.changed > 0 ||
    diff.schema.templates.sourceOnly > 0 ||
    diff.files.modified.length > 0 ||
    diff.files.localOnly.length > 0;

  if (!hasWork) {
    result.steps.push({ step: 'summary', success: true, detail: { message: 'Sites are already in sync. Nothing to do.' } });
    return { success: true, data: result };
  }

  // In dry-run mode, report what would happen and stop
  if (options.dryRun) {
    const plan: Record<string, unknown> = {};

    if (options.scope === 'all' || options.scope === 'schema') {
      plan.schema = {
        fieldsToSync:    diff.schema.fields.changed + diff.schema.fields.sourceOnly,
        templatesToSync: diff.schema.templates.changed + diff.schema.templates.sourceOnly,
      };
    }

    if (options.scope === 'all' || options.scope === 'pages') {
      plan.pages = {
        toUpdate: diff.pages.modified.map(p => p.path),
        toCreate: diff.pages.localOnly.map(p => p.path),
        note: 'File/image assets for synced pages will also be transferred.',
      };
    }

    if (options.scope === 'all' || options.scope === 'files') {
      plan.files = {
        toUpdate:  diff.files.modified.map(f => f.relativePath),
        toCreate:  diff.files.localOnly.map(f => f.relativePath),
      };
    }

    if (options.backup)      plan.wouldBackup = true;
    if (options.maintenance) plan.wouldEnableMaintenance = true;

    result.steps.push({ step: 'plan', success: true, detail: plan });
    return { success: true, data: result };
  }

  // ── Step 2: Backup (if requested) ───────────────────────────────────────
  if (options.backup) {
    const target = options.direction === 'local-to-remote' ? 'remote' : 'local';
    const backupCmd = target === 'remote'
      ? runRemoteCommand('backup:create', ['Pre-sync backup'], undefined, remoteUrl, remoteKey)
      : runPwCommand('backup:create', ['Pre-sync backup']);

    const backupResult = await backupCmd;

    if (!backupResult.success) {
      result.success = false;
      result.error = `Backup failed: ${backupResult.error}`;
      result.steps.push({ step: 'backup', success: false, detail: { error: backupResult.error } });
      return { success: false, data: result, error: result.error };
    }

    const backupData = backupResult.data as Record<string, unknown>;
    result.backupId = (backupData?.timestamp as string) ?? 'unknown';
    result.steps.push({ step: 'backup', success: true, detail: { backupId: result.backupId } });
  }

  // ── Step 3: Maintenance mode (if requested) ─────────────────────────────
  if (options.maintenance) {
    const target = options.direction === 'local-to-remote' ? 'remote' : 'local';
    const maintCmd = target === 'remote'
      ? runRemoteCommand('maintenance:on', ['PromptWire sync in progress'], undefined, remoteUrl, remoteKey)
      : runPwCommand('maintenance:on', ['PromptWire sync in progress']);

    const maintResult = await maintCmd;

    if (!maintResult.success) {
      result.steps.push({ step: 'maintenance', success: false, detail: { error: maintResult.error } });
      // Non-fatal — continue with sync
    } else {
      result.maintenanceOn = true;
      result.steps.push({ step: 'maintenance', success: true, detail: { enabled: true } });
    }
  }

  // ── Step 4: Push schema ─────────────────────────────────────────────────
  if (options.scope === 'all' || options.scope === 'schema') {
    const schemaHasWork =
      diff.schema.fields.changed > 0 ||
      diff.schema.fields.sourceOnly > 0 ||
      diff.schema.templates.changed > 0 ||
      diff.schema.templates.sourceOnly > 0;

    if (schemaHasWork) {
      const schemaResult = await schemaPush(false);

      result.steps.push({
        step: 'schema',
        success: schemaResult.success,
        detail: schemaResult.data as Record<string, unknown> ?? { error: schemaResult.error },
      });

      if (!schemaResult.success) {
        return abortSync(result, `Schema push failed: ${schemaResult.error}`, options, remoteUrl, remoteKey);
      }
    }
  }

  // ── Step 5: Push pages ──────────────────────────────────────────────────
  if (options.scope === 'all' || options.scope === 'pages') {
    const pagesToSync = [
      ...diff.pages.modified,
      ...diff.pages.localOnly,
    ];

    if (pagesToSync.length > 0) {
      const pageResults = await pushPages(pagesToSync, options.direction, remoteUrl, remoteKey);

      const failed = pageResults.filter(r => !r.success);
      result.steps.push({
        step: 'pages',
        success: failed.length === 0,
        detail: {
          total:    pagesToSync.length,
          success:  pagesToSync.length - failed.length,
          failed:   failed.length,
          failures: failed.map(f => ({ path: f.path, error: f.error })),
        },
      });

      if (failed.length > 0) {
        return abortSync(result, `${failed.length} page(s) failed to sync`, options, remoteUrl, remoteKey);
      }
    }
  }

  // ── Step 6: Sync page file/image assets ──────────────────────────────────
  if (options.scope === 'all' || options.scope === 'pages') {
    const pagesWithFiles = [
      ...diff.pages.modified,
      ...diff.pages.localOnly,
    ];

    if (pagesWithFiles.length > 0 && options.direction === 'local-to-remote') {
      const fileSyncResults = await syncPageFiles(pagesWithFiles, pwPath);

      const synced = fileSyncResults.filter(r => r.success);
      const skipped = fileSyncResults.filter(r => r.skipped);
      const failed = fileSyncResults.filter(r => !r.success && !r.skipped);

      result.steps.push({
        step: 'page-files',
        success: failed.length === 0,
        detail: {
          total:   pagesWithFiles.length,
          synced:  synced.length,
          skipped: skipped.length,
          failed:  failed.length,
          failures: failed.map(f => ({ path: f.path, error: f.error })),
        },
      });

      // File sync failures are non-fatal — the page content is already pushed
      if (failed.length > 0) {
        result.steps.push({
          step: 'page-files-warning',
          success: true,
          detail: { note: `${failed.length} page file sync(s) failed. Page content was synced. Use pw_file_sync to retry individual pages.` },
        });
      }
    }
  }

  // ── Step 7: Push template/module files ──────────────────────────────────
  if (options.scope === 'all' || options.scope === 'files') {
    const filesToSync = [
      ...diff.files.modified,
      ...diff.files.localOnly,
    ];

    if (filesToSync.length > 0) {
      const fileResult = await pushFiles(filesToSync, options.direction, pwPath, remoteUrl, remoteKey);

      result.steps.push({
        step: 'files',
        success: fileResult.success,
        detail: fileResult.detail,
      });

      if (!fileResult.success) {
        return abortSync(result, `File push failed: ${fileResult.error}`, options, remoteUrl, remoteKey);
      }
    }
  }

  // ── Step 8: Disable maintenance ─────────────────────────────────────────
  if (result.maintenanceOn) {
    const target = options.direction === 'local-to-remote' ? 'remote' : 'local';
    const offCmd = target === 'remote'
      ? runRemoteCommand('maintenance:off', [], undefined, remoteUrl, remoteKey)
      : runPwCommand('maintenance:off');

    const offResult = await offCmd;
    result.maintenanceOn = !offResult.success;
    result.steps.push({
      step: 'maintenance-off',
      success: offResult.success,
      detail: offResult.success ? { disabled: true } : { error: offResult.error },
    });
  }

  result.steps.push({ step: 'complete', success: true, detail: { message: 'Sync completed successfully.' } });
  return { success: true, data: result };
}

// ============================================================================
// HELPERS
// ============================================================================

async function abortSync(
  result: SiteSyncResult,
  error: string,
  options: SiteSyncOptions,
  remoteUrl: string,
  remoteKey: string,
): Promise<PwCommandResult> {
  result.success = false;
  result.error = error;

  // Deliberately leave maintenance ON so the half-synced site isn't exposed
  if (result.maintenanceOn) {
    result.steps.push({
      step: 'abort',
      success: false,
      detail: {
        error,
        maintenanceLeftOn: true,
        backupId: result.backupId,
        note: 'Maintenance mode left ON. Use pw_maintenance to disable after investigating. Use pw_backup to restore if needed.',
      },
    });
  }

  return { success: false, data: result, error };
}

interface PagePushResult {
  path:    string;
  success: boolean;
  error?:  string;
}

async function pushPages(
  pages: PageDiffItem[],
  direction: string,
  remoteUrl: string,
  remoteKey: string,
): Promise<PagePushResult[]> {
  const results: PagePushResult[] = [];

  // Sort so parents come before children
  const sorted = [...pages].sort((a, b) => a.path.localeCompare(b.path));

  for (const page of sorted) {
    if (direction === 'local-to-remote') {
      // Use pw_page_push with targets=remote for existing pages
      // For new pages (localOnly), use page:publish
      const isNew = page.status === 'localOnly';
      let pushResult: PwCommandResult;

      if (isNew) {
        pushResult = await runRemoteCommand(
          'page:publish',
          [page.path],
          undefined,
          remoteUrl,
          remoteKey,
        );
      } else {
        // Pull the page content locally, then push to remote
        const pullResult = await runPwCommand('page:pull', [page.path]);
        if (!pullResult.success) {
          results.push({ path: page.path, success: false, error: `Pull failed: ${pullResult.error}` });
          continue;
        }

        // Determine sync dir path for this page
        const syncDir = `site/assets/pw-mcp${page.path}`;
        pushResult = await runPwCommand('page:push', [syncDir, '--targets=remote', '--confirm']);
      }

      results.push({
        path: page.path,
        success: pushResult.success,
        error: pushResult.success ? undefined : pushResult.error,
      });
    } else {
      // remote-to-local: pull from remote
      const pullResult = await runRemoteCommand(
        'page:export',
        [page.path],
        undefined,
        remoteUrl,
        remoteKey,
      );

      results.push({
        path: page.path,
        success: pullResult.success,
        error: pullResult.success ? undefined : pullResult.error,
      });
    }
  }

  return results;
}

interface FilePushResult {
  success: boolean;
  error?:  string;
  detail:  Record<string, unknown>;
}

interface PageFileSyncResult {
  path:     string;
  success:  boolean;
  skipped:  boolean;
  error?:   string;
  detail?:  Record<string, unknown>;
}

async function syncPageFiles(
  pages: PageDiffItem[],
  pwPath: string,
): Promise<PageFileSyncResult[]> {
  const results: PageFileSyncResult[] = [];

  for (const page of pages) {
    // Check if the page has been pulled (has a sync directory)
    const syncDir = path.join(pwPath, 'site', 'assets', 'pw-mcp', page.path.replace(/^\/|\/$/g, '').replace(/\//g, '/'));
    const metaPath = path.join(pwPath, 'site', 'assets', 'pw-mcp', page.path.slice(1), 'page.meta.json');

    if (!fs.existsSync(metaPath)) {
      results.push({ path: page.path, success: true, skipped: true });
      continue;
    }

    try {
      const localPath = path.join(pwPath, 'site', 'assets', 'pw-mcp', page.path.slice(1));
      const fileSyncResult = await syncFiles({
        localPath,
        targets: 'remote',
        dryRun: false,
        deleteRemoteOrphans: false,
      });

      if (fileSyncResult.success) {
        const data = fileSyncResult.data as Record<string, unknown>;
        const remoteResult = data?.results as Record<string, Record<string, unknown>> | undefined;
        const summary = remoteResult?.remote?.summary as Record<string, unknown> | undefined;
        const noFiles = data?.message && String(data.message).includes('no files');

        results.push({
          path: page.path,
          success: true,
          skipped: !!noFiles,
          detail: summary ?? undefined,
        });
      } else {
        results.push({
          path: page.path,
          success: false,
          skipped: false,
          error: fileSyncResult.error,
        });
      }
    } catch (err) {
      results.push({
        path: page.path,
        success: false,
        skipped: false,
        error: (err as Error).message,
      });
    }
  }

  return results;
}

async function pushFiles(
  files: FileDiffItem[],
  direction: string,
  pwPath: string,
  remoteUrl: string,
  remoteKey: string,
): Promise<FilePushResult> {
  if (direction === 'local-to-remote') {
    // Read each file locally and push to remote via files:push
    const filesToPush: { relativePath: string; contentBase64: string }[] = [];
    const errors: string[] = [];

    for (const file of files) {
      const fullPath = path.join(pwPath, file.relativePath);
      try {
        const content = fs.readFileSync(fullPath);
        filesToPush.push({
          relativePath: file.relativePath,
          contentBase64: content.toString('base64'),
        });
      } catch (err) {
        errors.push(`Could not read ${file.relativePath}: ${(err as Error).message}`);
      }
    }

    if (errors.length > 0 && filesToPush.length === 0) {
      return { success: false, error: errors.join('; '), detail: { errors } };
    }

    // Send in batches to avoid oversized payloads (max ~50 files per batch)
    const batchSize = 50;
    let totalWritten = 0;
    let totalSkipped = 0;

    for (let i = 0; i < filesToPush.length; i += batchSize) {
      const batch = filesToPush.slice(i, i + batchSize);
      const filesJson = JSON.stringify(batch);

      const pushResult = await runRemoteCommand(
        'files:push',
        [`--files=${filesJson}`, '--confirm'],
        undefined,
        remoteUrl,
        remoteKey,
      );

      if (pushResult.success && pushResult.data) {
        const data = pushResult.data as { written?: number; skipped?: number };
        totalWritten += data.written ?? 0;
        totalSkipped += data.skipped ?? 0;
      } else {
        return {
          success: false,
          error: `Remote file push failed: ${pushResult.error}`,
          detail: { written: totalWritten, batch: i / batchSize + 1 },
        };
      }
    }

    return {
      success: true,
      detail: { written: totalWritten, skipped: totalSkipped, readErrors: errors },
    };
  } else {
    // remote-to-local: fetch files from remote and write locally
    // For now, report that this direction needs manual SFTP
    return {
      success: false,
      error: 'Remote-to-local file sync is not yet implemented. Use SFTP to pull files.',
      detail: { files: files.map(f => f.relativePath) },
    };
  }
}
