#!/usr/bin/env node
/**
 * Generic module push: deploy any ProcessWire module from a local checkout
 * to a remote PromptWire-equipped site via the `files:push` API.
 *
 * Companion to push-self-to-remote.mjs (which deploys PromptWire itself).
 * Useful for keeping third-party modules (MediaHub, FormBuilder,
 * LoginRegisterPro, etc.) in sync between development and production
 * without rolling your own SCP/FTP pipeline.
 *
 * Usage:
 *   PW_REMOTE_URL=... PW_REMOTE_KEY=... \
 *     node scripts/push-module-to-remote.mjs \
 *       --source=/path/to/local/site/modules/MediaHub \
 *       --prefix=site/modules/MediaHub/ \
 *       [--exclude=tests,docs and planning] \
 *       [--dry-run]
 *
 * Safety:
 *   - --prefix MUST start with `site/modules/` (matches the remote
 *     `filesPush` allowed-prefix check); any other value is rejected.
 *   - Refuses to walk `/` or $HOME as a source to prevent obvious
 *     foot-guns.
 *   - `.git/`, `node_modules/`, IDE dirs, and related detritus are
 *     pruned at walk time so development artefacts never ship. Extra
 *     directory names can be added via `--exclude=name1,name2,...` —
 *     matching is by basename at any depth (not glob patterns), so
 *     `--exclude=docs and planning` skips every directory named exactly
 *     that, wherever it appears in the tree. Useful for keeping
 *     internal PRDs, scratch notes, and test fixtures out of production.
 *   - `--dry-run` prints the full file list and payload size without
 *     contacting the remote.
 *
 * The remote PromptWire must be at v1.6+ (when files:push landed).
 */

import { readFileSync, statSync } from 'fs';
import { readdir } from 'fs/promises';
import path from 'path';

const REMOTE_URL = process.env.PW_REMOTE_URL;
const REMOTE_KEY = process.env.PW_REMOTE_KEY;
const DRY_RUN    = process.argv.includes('--dry-run');

function getArg(name) {
  const prefix = `--${name}=`;
  const arg = process.argv.find((a) => a.startsWith(prefix));
  return arg ? arg.slice(prefix.length) : null;
}

const SOURCE_RAW        = getArg('source');
const REMOTE_PREFIX_RAW = getArg('prefix');
const EXCLUDE_RAW       = getArg('exclude');

if (!REMOTE_URL || !REMOTE_KEY) {
  console.error('PW_REMOTE_URL and PW_REMOTE_KEY env vars are required.');
  process.exit(1);
}

if (!SOURCE_RAW || !REMOTE_PREFIX_RAW) {
  console.error('Usage: node push-module-to-remote.mjs --source=<path> --prefix=<site/modules/NAME/> [--dry-run]');
  process.exit(1);
}

const SOURCE_ROOT = path.resolve(SOURCE_RAW);

// Normalise REMOTE_PREFIX: ensure a trailing slash. Reject anything that
// doesn't live under `site/modules/` to match the remote filesPush
// allowed-prefix check (the remote API rejects out-of-prefix paths with a
// 500, so we catch it client-side with a clearer error).
let REMOTE_PREFIX = REMOTE_PREFIX_RAW;
if (!REMOTE_PREFIX.endsWith('/')) REMOTE_PREFIX += '/';
if (!REMOTE_PREFIX.startsWith('site/modules/')) {
  console.error(`--prefix must start with "site/modules/". Got: ${REMOTE_PREFIX_RAW}`);
  process.exit(1);
}

try {
  const stat = statSync(SOURCE_ROOT);
  if (!stat.isDirectory()) {
    console.error(`--source is not a directory: ${SOURCE_ROOT}`);
    process.exit(1);
  }
} catch {
  console.error(`--source does not exist: ${SOURCE_ROOT}`);
  process.exit(1);
}

if (SOURCE_ROOT === '/' || SOURCE_ROOT === process.env.HOME) {
  console.error(`Refusing to walk ${SOURCE_ROOT} — pick a specific module directory.`);
  process.exit(1);
}

// Mirrors filesInventory's INVENTORY_PRUNE_DIRS from v1.9.3 plus a few
// JS ecosystem extras (source modules commonly include build artefacts).
// Extended at parse time by --exclude=.
const SKIP_DIRS = new Set([
  '.git', '.svn', '.hg',
  'node_modules',
  '.cursor', '.idea', '.vscode',
  '__pycache__', '.next', '.cache',
]);

if (EXCLUDE_RAW) {
  EXCLUDE_RAW.split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .forEach((name) => SKIP_DIRS.add(name));
}

const SKIP_EXACT_FILES = new Set([
  '.DS_Store', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml',
]);

// Superset of push-self's list: third-party modules often ship fonts,
// source maps, and assorted asset types that PromptWire itself doesn't.
const ALLOWED_EXTENSIONS = new Set([
  'php', 'module', 'inc',
  'js', 'mjs', 'cjs', 'map',
  'css', 'scss', 'less',
  'json', 'yaml', 'yml',
  'md', 'html', 'htm', 'txt',
  'png', 'svg', 'gif', 'jpg', 'jpeg', 'webp', 'avif', 'ico',
  'woff', 'woff2', 'ttf', 'otf', 'eot',
]);

async function walk(dir, out) {
  let entries;
  try {
    entries = await readdir(dir, { withFileTypes: true });
  } catch {
    return;
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (SKIP_DIRS.has(entry.name) || entry.name.startsWith('.')) continue;
      await walk(full, out);
    } else if (entry.isFile()) {
      if (SKIP_EXACT_FILES.has(entry.name)) continue;
      const ext = path.extname(entry.name).slice(1).toLowerCase();
      if (ALLOWED_EXTENSIONS.size > 0 && ext && !ALLOWED_EXTENSIONS.has(ext)) continue;
      if (!ext && entry.name !== 'LICENSE' && entry.name !== 'README') continue;
      out.push(full);
    }
  }
}

const localFiles = [];
await walk(SOURCE_ROOT, localFiles);

if (localFiles.length === 0) {
  console.error(`No files found under ${SOURCE_ROOT} matching the allowed-extension filter.`);
  process.exit(1);
}

console.log(`Source:        ${SOURCE_ROOT}`);
console.log(`Remote prefix: ${REMOTE_PREFIX}`);
if (EXCLUDE_RAW) {
  console.log(`Extra excludes: ${EXCLUDE_RAW}`);
}
console.log(`Found ${localFiles.length} files to push.`);

const payload = localFiles
  .map((full) => {
    const rel  = REMOTE_PREFIX + path.relative(SOURCE_ROOT, full);
    const size = statSync(full).size;
    return {
      relativePath:  rel,
      contentBase64: readFileSync(full).toString('base64'),
      size,
    };
  });

const totalBytes = payload.reduce((acc, p) => acc + p.size, 0);
console.log(`Total payload size: ${(totalBytes / 1024).toFixed(1)} KB across ${payload.length} files.`);

if (DRY_RUN) {
  console.log('\nFiles that WOULD be pushed:');
  payload.forEach((p) => console.log(`  ${p.relativePath}  (${p.size} bytes)`));
  console.log('\n--dry-run set: not contacting remote.');
  process.exit(0);
}

// files:push is JSON-string flag based; split into batches of 50 to avoid
// oversized URL-encoded args. Drop the size field before JSON.stringify
// (only relativePath + contentBase64 are accepted server-side).
const BATCH = 50;
let totalWritten = 0;
let totalSkipped = 0;
let batchIdx = 0;

for (let i = 0; i < payload.length; i += BATCH) {
  batchIdx++;
  const batch = payload.slice(i, i + BATCH).map(({ relativePath, contentBase64 }) => ({
    relativePath, contentBase64,
  }));
  const filesJson = JSON.stringify(batch);

  const body = {
    command: 'files:push',
    args:    [`--files=${filesJson}`, '--confirm'],
  };

  console.log(`\nBatch ${batchIdx}: pushing ${batch.length} files...`);

  let res;
  try {
    res = await fetch(REMOTE_URL, {
      method: 'POST',
      headers: {
        'Content-Type':        'application/json',
        'X-PromptWire-Key':    REMOTE_KEY,
        'X-PromptWire-Client': 'push-module-to-remote/1.0',
      },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(120_000),
    });
  } catch (err) {
    console.error(`  Network error: ${err.message}`);
    process.exit(1);
  }

  if (!res.ok) {
    console.error(`  HTTP ${res.status}: ${await res.text()}`);
    process.exit(1);
  }

  const data = await res.json();
  if (data.error) {
    console.error(`  Remote error: ${data.error}`);
    process.exit(1);
  }
  totalWritten += data.written ?? 0;
  totalSkipped += data.skipped ?? 0;
  console.log(`  written: ${data.written ?? 0}  skipped: ${data.skipped ?? 0}`);
}

console.log(`\nDone. Total written: ${totalWritten}, skipped: ${totalSkipped}.`);
