/**
 * PromptWire Page Rename
 *
 * Renames a ProcessWire page slug on local PW, remote PW, or both.
 * After a local rename, optionally reconciles the pw-mcp sync folder.
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { runPwCommand, type PwCommandResult } from '../cli/runner.js';
import { runRemoteCommand } from '../remote/client.js';

export type RenameTarget = 'local' | 'remote' | 'both';

export interface RenamePageOptions {
  idOrPath: string;
  newName: string;
  dryRun?: boolean;
  targets?: RenameTarget;
  reconcileLocal?: boolean;
  syncDirectory?: string;
}

function buildRenameArgs(opts: RenamePageOptions): string[] {
  const args = [opts.idOrPath, opts.newName];
  if (opts.dryRun === false) {
    args.push('--dry-run=0');
  }
  if (opts.reconcileLocal === false) {
    args.push('--no-reconcile');
  }
  if (opts.syncDirectory) {
    args.push(`--sync-directory=${opts.syncDirectory}`);
  }
  return args;
}

function collectTargetErrors(results: Record<string, PwCommandResult>): string[] {
  const errors: string[] = [];
  for (const [target, result] of Object.entries(results)) {
    if (!result.success) {
      errors.push(`${target}: ${result.error ?? 'rename failed'}`);
      continue;
    }
    const data = result.data as { error?: string } | undefined;
    if (data?.error) {
      errors.push(`${target}: ${data.error}`);
    }
  }
  return errors;
}

/**
 * Rename a page slug via PromptWire CLI (local) and/or remote HTTP API.
 */
export async function renamePage(opts: RenamePageOptions): Promise<PwCommandResult> {
  const {
    idOrPath,
    newName,
    dryRun = true,
    targets = 'local',
    reconcileLocal = true,
    syncDirectory,
  } = opts;

  if (!idOrPath || !newName) {
    return { success: false, error: 'idOrPath and newName are required' };
  }

  const shouldRenameLocal = targets === 'local' || targets === 'both';
  const shouldRenameRemote = targets === 'remote' || targets === 'both';

  if (shouldRenameRemote) {
    const remoteUrl = process.env.PW_REMOTE_URL;
    const remoteKey = process.env.PW_REMOTE_KEY;
    if (!remoteUrl) {
      return {
        success: false,
        error:
          'targets includes remote but PW_REMOTE_URL is not set in this MCP server env.',
      };
    }
    if (!remoteKey) {
      return {
        success: false,
        error:
          'targets includes remote but PW_REMOTE_KEY is not set in this MCP server env.',
      };
    }
  }

  const cmdArgs = buildRenameArgs({
    idOrPath,
    newName,
    dryRun,
    reconcileLocal: shouldRenameLocal ? reconcileLocal : false,
    syncDirectory,
  });

  const results: Record<string, PwCommandResult> = {};

  if (shouldRenameLocal) {
    results.local = await runPwCommand('page:rename', cmdArgs);
  }

  if (shouldRenameRemote) {
    const remoteArgs = buildRenameArgs({
      idOrPath,
      newName,
      dryRun,
      reconcileLocal: false,
    });
    results.remote = await runRemoteCommand('page:rename', remoteArgs);
  }

  const errors = collectTargetErrors(results);
  if (errors.length > 0) {
    return {
      success: false,
      error: errors.join('; '),
      data: {
        idOrPath,
        newName,
        dryRun,
        targets,
        results,
      },
    };
  }

  return {
    success: true,
    data: {
      idOrPath,
      newName,
      dryRun,
      targets,
      results: targets === 'local' || targets === 'remote'
        ? results[targets]?.data ?? results[targets]
        : Object.fromEntries(
            Object.entries(results).map(([key, value]) => [key, value.data ?? value])
          ),
    },
  };
}
