/**
 * PromptWire File Sync
 *
 * Synchronises file/image field content between local and remote PW sites.
 * Compares file inventories, transfers only what's missing or changed,
 * and optionally removes files that exist remotely but not locally.
 *
 * Flow:
 *   1. Build local inventory from disk (site/assets/files/{pageId}/)
 *   2. Get remote inventory via file:inventory API call
 *   3. Diff: new, changed (md5 mismatch), deleted
 *   4. Transfer missing/changed files via file:upload
 *   5. Optionally delete remote-only files via file:delete
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { readFile, readdir, stat } from 'fs/promises';
import { existsSync } from 'fs';
import path from 'path';
import { createHash } from 'crypto';
import { load as yamlLoad } from 'js-yaml';
import { runRemoteCommand } from '../remote/client.js';
import { runPwCommand } from '../cli/runner.js';
import type { PwCommandResult } from '../cli/runner.js';

// ============================================================================
// TYPES
// ============================================================================

export type FileSyncTarget = 'local' | 'remote' | 'both';

export interface FileSyncOptions {
  localPath: string;
  targets?: FileSyncTarget;
  dryRun?: boolean;
  deleteRemoteOrphans?: boolean;
}

interface FileEntry {
  filename: string;
  size: number;
  md5: string;
  description?: string | null;
  width?: number;
  height?: number;
}

interface FieldInventory {
  type: 'image' | 'file';
  count: number;
  files: FileEntry[];
}

interface PageMeta {
  pageId: number;
  canonicalPath: string;
  path?: string;
  template: string;
  ids?: {
    local?: { id?: number };
    remote?: { id?: number };
  };
}

interface FieldFileDetail {
  fieldName: string;
  description?: string | null;
}

// PW image variation pattern: name.WIDTHxHEIGHT[-suffix].ext
const VARIATION_PATTERN = /\.\d+x\d+(-[a-z0-9-]+)?\.[a-z]+$/i;

// ============================================================================
// MAIN ENTRY POINT
// ============================================================================

/**
 * Sync files for a locally-pulled page to a remote PW site.
 *
 * Reads the page.meta.json for identity, scans local files,
 * compares with remote inventory, and transfers the diff.
 */
export async function syncFiles(opts: FileSyncOptions): Promise<PwCommandResult> {
  const { localPath, targets = 'remote', dryRun = true, deleteRemoteOrphans = false } = opts;

  const yamlPath = localPath.endsWith('page.yaml')
    ? localPath
    : path.join(localPath, 'page.yaml');
  const metaPath = yamlPath.replace('page.yaml', 'page.meta.json');

  if (!existsSync(metaPath)) {
    return { success: false, error: `page.meta.json not found at ${metaPath} — pull the page first` };
  }

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

  const shouldSyncRemote = targets === 'remote' || targets === 'both';

  if (shouldSyncRemote) {
    if (!process.env.PW_REMOTE_URL || !process.env.PW_REMOTE_KEY) {
      return {
        success: false,
        error: 'Remote file sync requires PW_REMOTE_URL and PW_REMOTE_KEY in your MCP server env.',
      };
    }
  }

  // Build local file inventory from disk
  const pwPath = process.env.PW_PATH;
  if (!pwPath) {
    return { success: false, error: 'PW_PATH not set — cannot locate local files' };
  }

  const localPageId = await resolveLocalPageId(meta, pwPath, pagePath);
  const localFileDir = path.join(pwPath, 'site', 'assets', 'files', String(localPageId));
  if (!existsSync(localFileDir)) {
    return {
      success: true,
      data: {
        pagePath,
        message: 'No local file directory found — page has no files to sync',
        localDir: localFileDir,
      },
    };
  }

  // Field/file mapping from YAML (field name, filename, optional description)
  const fieldFileDetails = await getFieldFileDetails(yamlPath);
  const fieldFileMap = Object.fromEntries(
    Object.entries(fieldFileDetails).map(([filename, detail]) => [filename, [detail.fieldName]]),
  ) as Record<string, string[]>;

  // Build local inventory
  const localInventory = await buildLocalInventory(localFileDir, fieldFileMap, fieldFileDetails);

  const results: Record<string, unknown> = {};

  if (shouldSyncRemote) {
    const remoteResult = await syncToRemote(
      pagePath,
      localInventory,
      fieldFileDetails,
      dryRun,
      deleteRemoteOrphans,
    );
    results['remote'] = remoteResult;
  }

  return {
    success: true,
    data: {
      pagePath,
      dryRun,
      targets,
      localFileDir,
      localFileCount: Object.values(localInventory).reduce((sum, f) => sum + f.files.length, 0),
      results,
    },
  };
}

// ============================================================================
// LOCAL INVENTORY — scan disk for original files
// ============================================================================

/**
 * Resolve the local page ID for reading site/assets/files/{pageId}/.
 * After a remote pull, meta.pageId may be the remote id — prefer ids.local.id,
 * then path lookup via get-page on the local site.
 */
async function resolveLocalPageId(
  meta: PageMeta,
  pwPath: string,
  pagePath: string,
): Promise<number> {
  const localId = meta.ids?.local?.id;
  if (localId) return localId;

  const fromPath = await findLocalPageId(pwPath, pagePath);
  if (fromPath) return fromPath;

  return meta.pageId;
}

async function findLocalPageId(pwPath: string, pagePath: string): Promise<number | null> {
  const result = await runPwCommand('get-page', [pagePath, '--summary']);
  if (!result.success || !result.data) return null;
  const pageId = (result.data as { id?: number })?.id;
  return pageId ?? null;
}

/**
 * Read page.yaml to build filename → { fieldName, description }.
 */
async function getFieldFileDetails(yamlPath: string): Promise<Record<string, FieldFileDetail>> {
  try {
    const raw = await readFile(yamlPath, 'utf-8');
    const parsed = yamlLoad(raw) as { fields?: Record<string, unknown> };
    if (!parsed?.fields) return {};

    const details: Record<string, FieldFileDetail> = {};
    for (const [fieldName, value] of Object.entries(parsed.fields)) {
      if (!Array.isArray(value)) continue;
      for (const item of value) {
        if (typeof item === 'object' && item !== null && 'filename' in item) {
          const filename = (item as { filename: string }).filename;
          details[filename] = {
            fieldName,
            description: (item as { description?: string | null }).description ?? null,
          };
        }
      }
    }
    return details;
  } catch {
    return {};
  }
}

/**
 * Scan the local page directory and build a file inventory,
 * filtering out PW image variations.
 */
async function buildLocalInventory(
  dirPath: string,
  fieldFileMap: Record<string, string[]>,
  fieldFileDetails: Record<string, FieldFileDetail>,
): Promise<Record<string, FieldInventory>> {
  const allFiles = await readdir(dirPath);

  // Filter out variations and hidden files
  const originals = allFiles.filter(f => {
    if (f.startsWith('.')) return false;
    if (VARIATION_PATTERN.test(f)) return false;
    return true;
  });

  // Build entries with md5 hashes
  const entries: FileEntry[] = [];
  for (const filename of originals) {
    const filePath = path.join(dirPath, filename);
    const fileStat = await stat(filePath);
    if (!fileStat.isFile()) continue;

    const content = await readFile(filePath);
    const md5 = createHash('md5').update(content).digest('hex');
    entries.push({
      filename,
      size: fileStat.size,
      md5,
      description: fieldFileDetails[filename]?.description ?? null,
    });
  }

  // Group by field using the YAML mapping
  const inventory: Record<string, FieldInventory> = {};

  // Create a reverse lookup: filename → field name
  const filenameToField: Record<string, string> = {};
  for (const [fieldName, filenames] of Object.entries(fieldFileMap)) {
    for (const fn of filenames) {
      filenameToField[fn] = fieldName;
    }
  }

  for (const entry of entries) {
    const fieldName = filenameToField[entry.filename] ?? '_unmatched';
    if (!inventory[fieldName]) {
      inventory[fieldName] = { type: 'file', count: 0, files: [] };
    }
    inventory[fieldName].files.push(entry);
    inventory[fieldName].count = inventory[fieldName].files.length;
  }

  return inventory;
}

// ============================================================================
// REMOTE SYNC — compare and transfer
// ============================================================================

async function syncToRemote(
  pagePath: string,
  localInventory: Record<string, FieldInventory>,
  fieldFileDetails: Record<string, FieldFileDetail>,
  dryRun: boolean,
  deleteOrphans: boolean,
): Promise<Record<string, unknown>> {
  // 1. Get remote inventory
  const inventoryResult = await runRemoteCommand('file:inventory', [pagePath]);
  if (!inventoryResult.success) {
    return { error: `Failed to get remote inventory: ${inventoryResult.error}` };
  }

  const remoteFields = (inventoryResult.data as { fields?: Record<string, FieldInventory> })?.fields ?? {};

  // 2. Diff each field
  const toUpload: Array<{ fieldName: string; filename: string; reason: string; metadataOnly?: boolean }> = [];
  const toDelete: Array<{ fieldName: string; filename: string }> = [];
  const unchanged: string[] = [];

  const descriptionsMatch = (
    localDesc: string | null | undefined,
    remoteDesc: string | null | undefined,
  ): boolean => (localDesc ?? '') === (remoteDesc ?? '');

  for (const [fieldName, localField] of Object.entries(localInventory)) {
    if (fieldName === '_unmatched') continue;
    const remoteField = remoteFields[fieldName];
    const remoteFileMap = new Map<string, FileEntry>();
    if (remoteField) {
      for (const f of remoteField.files) {
        remoteFileMap.set(f.filename, f);
      }
    }

    for (const localFile of localField.files) {
      const remoteFile = remoteFileMap.get(localFile.filename);
      const yamlDesc = fieldFileDetails[localFile.filename]?.description ?? localFile.description ?? null;
      if (!remoteFile) {
        toUpload.push({ fieldName, filename: localFile.filename, reason: 'new' });
      } else if (remoteFile.md5 !== localFile.md5) {
        toUpload.push({ fieldName, filename: localFile.filename, reason: 'changed' });
      } else if (!descriptionsMatch(yamlDesc, remoteFile.description)) {
        toUpload.push({
          fieldName,
          filename: localFile.filename,
          reason: 'description',
          metadataOnly: true,
        });
      } else {
        unchanged.push(localFile.filename);
      }
    }

    // Check for remote-only files (candidates for deletion)
    if (deleteOrphans && remoteField) {
      const localFilenames = new Set(localField.files.map(f => f.filename));
      for (const remoteFile of remoteField.files) {
        if (!localFilenames.has(remoteFile.filename)) {
          toDelete.push({ fieldName, filename: remoteFile.filename });
        }
      }
    }
  }

  // Also check remote fields that don't exist locally (all files are orphans)
  if (deleteOrphans) {
    for (const [fieldName, remoteField] of Object.entries(remoteFields)) {
      if (localInventory[fieldName]) continue;
      for (const remoteFile of remoteField.files) {
        toDelete.push({ fieldName, filename: remoteFile.filename });
      }
    }
  }

  if (dryRun) {
    return {
      dryRun: true,
      summary: {
        toUpload: toUpload.length,
        toDelete: toDelete.length,
        unchanged: unchanged.length,
      },
      uploads: toUpload,
      deletes: toDelete,
      unchanged,
    };
  }

  // 3. Execute uploads
  const pwPath = process.env.PW_PATH!;
  const uploadResults: Array<Record<string, unknown>> = [];

  for (const item of toUpload) {
    // Find the local page directory from the meta
    // We need the pageId to locate the file on disk
    const localPageDir = await findLocalPageDir(pwPath, pagePath);
    if (!localPageDir) {
      uploadResults.push({
        filename: item.filename,
        error: 'Could not locate local file directory',
      });
      continue;
    }

    const filePath = path.join(localPageDir, item.filename);
    if (!existsSync(filePath)) {
      uploadResults.push({
        filename: item.filename,
        error: `File not found on disk: ${filePath}`,
      });
      continue;
    }

    const fileContent = item.metadataOnly ? null : await readFile(filePath);
    const base64 = fileContent ? fileContent.toString('base64') : '';
    const description =
      fieldFileDetails[item.filename]?.description ??
      localInventory[item.fieldName]?.files.find(f => f.filename === item.filename)?.description ??
      null;

    const result = await runRemoteCommand(
      'file:upload',
      [pagePath, '--dry-run=0'],
      undefined,
      undefined,
      undefined,
      undefined,
      {
        fieldName: item.fieldName,
        filename: item.filename,
        data: base64,
        description,
        metadataOnly: item.metadataOnly ?? false,
      },
    );

    uploadResults.push({
      filename: item.filename,
      fieldName: item.fieldName,
      reason: item.reason,
      success: result.success,
      error: result.error ?? undefined,
    });
  }

  // 4. Execute deletes
  const deleteResults: Array<Record<string, unknown>> = [];
  for (const item of toDelete) {
    const result = await runRemoteCommand(
      'file:delete',
      [pagePath, '--dry-run=0'],
      undefined,
      undefined,
      undefined,
      undefined,
      {
        fieldName: item.fieldName,
        filename: item.filename,
      },
    );
    deleteResults.push({
      filename: item.filename,
      fieldName: item.fieldName,
      success: result.success,
      error: result.error ?? undefined,
    });
  }

  return {
    dryRun: false,
    summary: {
      uploaded: uploadResults.filter(r => r.success).length,
      uploadFailed: uploadResults.filter(r => !r.success).length,
      deleted: deleteResults.filter(r => r.success).length,
      unchanged: unchanged.length,
    },
    uploads: uploadResults,
    deletes: deleteResults,
  };
}

/**
 * Find the local site/assets/files/{pageId}/ directory for a page path.
 */
async function findLocalPageDir(pwPath: string, pagePath: string): Promise<string | null> {
  const pageId = await findLocalPageId(pwPath, pagePath);
  if (!pageId) return null;

  const dir = path.join(pwPath, 'site', 'assets', 'files', String(pageId));
  return existsSync(dir) ? dir : null;
}
