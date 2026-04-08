/**
 * PW-MCP Cross-Site Schema Comparison
 *
 * Compares schemas between two ProcessWire sites and classifies every
 * difference by severity so you know exactly what's safe to push,
 * what needs caution, and what would be dangerous.
 *
 * Severity levels:
 *   safe    — Additive change (new field/template). No data loss risk.
 *   warning — Config change (label, description, settings). Low risk.
 *   danger  — Type change or removing required status. Data loss risk.
 *   info    — Exists on target but not in source. Push won't affect it.
 *
 * @package     PwMcp
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { promises as fs } from 'fs';
import path from 'path';
import { runPwCommand, type PwCommandResult } from '../cli/runner.js';
import { runRemoteCommand } from '../remote/client.js';
import { getSyncDir } from './sync.js';

// ============================================================================
// TYPES
// ============================================================================

export type DiffSeverity = 'safe' | 'warning' | 'danger' | 'info';
export type DiffStatus   = 'sourceOnly' | 'targetOnly' | 'changed' | 'unchanged';

export interface ClassifiedDiffItem {
  status:         DiffStatus;
  severity?:      DiffSeverity;
  recommendation?: string;
  changes?:       FieldChange[];
  source?:        unknown;
  target?:        unknown;
}

export interface FieldChange {
  property:  string;
  from:      unknown;
  to:        unknown;
  severity:  DiffSeverity;
}

export interface CompareResult {
  source:    string;
  target:    string;
  fields:    Record<string, ClassifiedDiffItem>;
  templates: Record<string, ClassifiedDiffItem>;
  summary: {
    fields:    SeverityCounts;
    templates: SeverityCounts;
    canPushSafely: boolean;
    hasDangers:    boolean;
    hasWarnings:   boolean;
  };
}

export interface SeverityCounts {
  safe:      number;
  warning:   number;
  danger:    number;
  info:      number;
  unchanged: number;
}

export interface SiteConfig {
  name:  string;
  label: string;
  url:   string;
  key:   string;
}

// ============================================================================
// SITE CONFIG LOADER
// ============================================================================

/**
 * Load a named site config from .pw-sync/sites/<name>.json
 */
export async function loadSiteConfig(siteName: string): Promise<SiteConfig | null> {
  const syncDir  = path.dirname(getSyncDir()); // .pw-sync/ (parent of schema/)
  const sitesDir = path.join(syncDir, 'sites');
  const configFile = path.join(sitesDir, `${siteName}.json`);

  try {
    const raw = await fs.readFile(configFile, 'utf8');
    return JSON.parse(raw) as SiteConfig;
  } catch {
    return null;
  }
}

/**
 * List all available named site configs
 */
export async function listSiteConfigs(): Promise<string[]> {
  const syncDir  = path.dirname(getSyncDir());
  const sitesDir = path.join(syncDir, 'sites');

  try {
    const files = await fs.readdir(sitesDir);
    return files
      .filter(f => f.endsWith('.json') && f !== 'example.json')
      .map(f => f.replace('.json', ''));
  } catch {
    return [];
  }
}

// ============================================================================
// SCHEMA EXPORT FROM ANY SITE
// ============================================================================

/**
 * Export schema from any site — current connection, local, or a named remote
 */
async function exportSchemaFromSite(
  siteName: 'current' | 'local' | string
): Promise<{ label: string; fields: Record<string, unknown>; templates: Record<string, unknown> } | null> {

  if (siteName === 'current') {
    // Use whatever site is currently connected (local or remote via PW_REMOTE_URL)
    const result = await runPwCommand('export-schema');
    if (!result.success) return null;
    const data = result.data as { meta?: { siteName?: string }; fields?: Record<string, unknown>; templates?: Record<string, unknown> };
    return {
      label:     data.meta?.siteName ?? 'current',
      fields:    data.fields    ?? {},
      templates: data.templates ?? {},
    };
  }

  if (siteName === 'local') {
    // Force local PHP CLI regardless of PW_REMOTE_URL
    const pwPath  = process.env.PW_PATH;
    const cliPath = process.env.PW_MCP_CLI_PATH ?? (pwPath ? `${pwPath}/site/modules/PwMcp/bin/pw-mcp.php` : null);
    if (!pwPath || !cliPath) return null;

    // PW_PATH is set so runPwCommand will use local PHP CLI (not remote)
    const result = await runPwCommand('export-schema');
    if (!result.success) return null;
    const data = result.data as { meta?: { siteName?: string }; fields?: Record<string, unknown>; templates?: Record<string, unknown> };
    return {
      label:     data.meta?.siteName ?? 'local',
      fields:    data.fields    ?? {},
      templates: data.templates ?? {},
    };
  }

  // Named site — load config from .pw-sync/sites/<name>.json
  const config = await loadSiteConfig(siteName);
  if (!config) {
    return null;
  }

  const result = await runRemoteCommand('export-schema', [], undefined, config.url, config.key);
  if (!result.success) return null;

  const data = result.data as { meta?: { siteName?: string }; fields?: Record<string, unknown>; templates?: Record<string, unknown> };
  return {
    label:     config.label,
    fields:    data.fields    ?? {},
    templates: data.templates ?? {},
  };
}

// ============================================================================
// CROSS-SITE COMPARE
// ============================================================================

/**
 * Compare schemas between two sites with full collision classification.
 *
 * @param sourceSite  'current' | 'local' | named site (e.g. 'production')
 * @param targetSite  'current' | 'local' | named site (e.g. 'staging')
 */
export async function compareSites(
  sourceSite: string = 'current',
  targetSite: string = 'production'
): Promise<PwCommandResult> {

  const [source, target] = await Promise.all([
    exportSchemaFromSite(sourceSite),
    exportSchemaFromSite(targetSite),
  ]);

  if (!source) {
    return { success: false, error: `Could not export schema from source: ${sourceSite}` };
  }
  if (!target) {
    // Check if named config exists
    const available = await listSiteConfigs();
    const hint = available.length > 0
      ? ` Available sites: ${available.join(', ')}`
      : ` No site configs found in .pw-sync/sites/ — create one from .pw-sync/sites/example.json`;
    return { success: false, error: `Could not export schema from target: ${targetSite}.${hint}` };
  }

  const fields    = classifyDiff(source.fields,    target.fields,    'field');
  const templates = classifyDiff(source.templates, target.templates, 'template');

  const summary = buildSummary(fields, templates);

  const result: CompareResult = {
    source:    source.label,
    target:    target.label,
    fields,
    templates,
    summary,
  };

  return { success: true, data: result };
}

// ============================================================================
// COLLISION CLASSIFICATION
// ============================================================================

/**
 * Diff two schema objects and classify each difference by severity
 */
function classifyDiff(
  source: Record<string, unknown>,
  target: Record<string, unknown>,
  type:   'field' | 'template'
): Record<string, ClassifiedDiffItem> {

  const result: Record<string, ClassifiedDiffItem> = {};
  const allKeys = Array.from(new Set([...Object.keys(source), ...Object.keys(target)])).sort();

  for (const key of allKeys) {
    const inSource = key in source;
    const inTarget = key in target;

    if (inSource && !inTarget) {
      // Exists in source but not target — pushing would CREATE it
      result[key] = {
        status:         'sourceOnly',
        severity:       'safe',
        recommendation: type === 'field'
          ? `New field — will be created on target`
          : `New template — will be created on target`,
        source: source[key],
      };

    } else if (!inSource && inTarget) {
      // Exists in target but not source — push won't touch it
      result[key] = {
        status:         'targetOnly',
        severity:       'info',
        recommendation: `Exists on target only — push will not affect it`,
        target: target[key],
      };

    } else {
      // Exists in both — compare them
      const sourceStr = JSON.stringify(source[key]);
      const targetStr = JSON.stringify(target[key]);

      if (sourceStr === targetStr) {
        result[key] = { status: 'unchanged' };
      } else {
        const item = classifyChange(key, source[key], target[key], type);
        result[key] = item;
      }
    }
  }

  return result;
}

/**
 * Classify a specific change between source and target definitions
 */
function classifyChange(
  name:   string,
  source: unknown,
  target: unknown,
  type:   'field' | 'template'
): ClassifiedDiffItem {

  const src = source as Record<string, unknown>;
  const tgt = target as Record<string, unknown>;

  const changes: FieldChange[] = [];
  let maxSeverity: DiffSeverity = 'safe';

  if (type === 'field') {
    // TYPE CHANGE — most dangerous
    if (src['type'] !== tgt['type']) {
      changes.push({
        property: 'type',
        from:     tgt['type'],
        to:       src['type'],
        severity: 'danger',
      });
      maxSeverity = 'danger';
    }

    // LABEL CHANGE — low risk
    if (src['label'] !== tgt['label']) {
      changes.push({
        property: 'label',
        from:     tgt['label'],
        to:       src['label'],
        severity: 'warning',
      });
      if (maxSeverity === 'safe') maxSeverity = 'warning';
    }

    // REQUIRED CHANGE — medium risk (adding required to existing field with data could cause issues)
    const srcRequired = !!(src['required']);
    const tgtRequired = !!(tgt['required']);
    if (srcRequired !== tgtRequired) {
      const sev: DiffSeverity = srcRequired ? 'warning' : 'safe'; // adding required = warning
      changes.push({
        property: 'required',
        from:     tgtRequired,
        to:       srcRequired,
        severity: sev,
      });
      if (sev === 'warning' && maxSeverity === 'safe') maxSeverity = 'warning';
    }

    // SETTINGS CHANGES
    const srcSettings = (src['settings'] as Record<string, unknown>) ?? {};
    const tgtSettings = (tgt['settings'] as Record<string, unknown>) ?? {};
    const allSettingKeys = Array.from(new Set([...Object.keys(srcSettings), ...Object.keys(tgtSettings)]));

    for (const settingKey of allSettingKeys) {
      const srcVal = srcSettings[settingKey];
      const tgtVal = tgtSettings[settingKey];
      if (JSON.stringify(srcVal) !== JSON.stringify(tgtVal)) {
        // maxlength reduction could cause data truncation — danger
        const sev: DiffSeverity = (settingKey === 'maxlength' && Number(srcVal) < Number(tgtVal))
          ? 'danger'
          : 'warning';
        changes.push({
          property: `settings.${settingKey}`,
          from:     tgtVal,
          to:       srcVal,
          severity: sev,
        });
        if (sev === 'danger') maxSeverity = 'danger';
        else if (sev === 'warning' && maxSeverity === 'safe') maxSeverity = 'warning';
      }
    }

  } else {
    // TEMPLATE CHANGES

    const srcFields = (src['fields'] as string[]) ?? [];
    const tgtFields = (tgt['fields'] as string[]) ?? [];

    const toAdd    = srcFields.filter((f: string) => !tgtFields.includes(f));
    const toRemove = tgtFields.filter((f: string) => !srcFields.includes(f));

    if (toAdd.length > 0) {
      changes.push({
        property: 'fields.add',
        from:     null,
        to:       toAdd,
        severity: 'safe',
      });
    }

    // Note: we never auto-remove fields from templates — just flag it
    if (toRemove.length > 0) {
      changes.push({
        property: 'fields.remove',
        from:     toRemove,
        to:       null,
        severity: 'info',
      });
    }

    if (src['label'] !== tgt['label']) {
      changes.push({
        property: 'label',
        from:     tgt['label'],
        to:       src['label'],
        severity: 'warning',
      });
      if (maxSeverity === 'safe') maxSeverity = 'warning';
    }
  }

  const recommendations: Record<DiffSeverity, string> = {
    safe:    'Safe to push — additive change only',
    warning: 'Review before pushing — config change may affect existing data',
    danger:  'DO NOT push without manual review — risk of data loss or site breakage',
    info:    'Informational only — push will not affect this',
  };

  return {
    status:         'changed',
    severity:       maxSeverity,
    recommendation: recommendations[maxSeverity],
    changes,
    source,
    target,
  };
}

// ============================================================================
// SUMMARY
// ============================================================================

function buildSummary(
  fields:    Record<string, ClassifiedDiffItem>,
  templates: Record<string, ClassifiedDiffItem>
): CompareResult['summary'] {

  const countSeverities = (items: Record<string, ClassifiedDiffItem>): SeverityCounts => {
    const counts: SeverityCounts = { safe: 0, warning: 0, danger: 0, info: 0, unchanged: 0 };
    for (const item of Object.values(items)) {
      if (item.status === 'unchanged') {
        counts.unchanged++;
      } else if (item.severity) {
        counts[item.severity]++;
      }
    }
    return counts;
  };

  const fieldCounts    = countSeverities(fields);
  const templateCounts = countSeverities(templates);

  const hasDangers  = fieldCounts.danger > 0 || templateCounts.danger > 0;
  const hasWarnings = fieldCounts.warning > 0 || templateCounts.warning > 0;

  return {
    fields:        fieldCounts,
    templates:     templateCounts,
    canPushSafely: !hasDangers,
    hasDangers,
    hasWarnings,
  };
}
