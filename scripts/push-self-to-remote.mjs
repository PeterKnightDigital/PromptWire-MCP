#!/usr/bin/env node
/**
 * One-off script: push the PromptWire module's own source files from the
 * local checkout to a remote PromptWire-equipped site via files:push.
 *
 * Used during the v1.8.x release validation against peterknight.digital
 * production where pw_site_sync's directory filter does not constrain
 * scope and a v1.8.0 path-handling bug hides symlinked PromptWire from
 * the inventory diff. This bypasses both by addressing only the
 * PromptWire/* tree directly.
 *
 * Usage (from repo root):
 *   PW_REMOTE_URL=... PW_REMOTE_KEY=... node scripts/push-self-to-remote.mjs [--dry-run]
 */

import { readFileSync, statSync } from 'fs';
import { readdir } from 'fs/promises';
import path from 'path';

const REMOTE_URL = process.env.PW_REMOTE_URL;
const REMOTE_KEY = process.env.PW_REMOTE_KEY;
const DRY_RUN    = process.argv.includes('--dry-run');

if (!REMOTE_URL || !REMOTE_KEY) {
  console.error('PW_REMOTE_URL and PW_REMOTE_KEY env vars are required.');
  process.exit(1);
}

// Walk the local PromptWire repo and collect every file we want to deploy.
// The remote allowed-prefix list (filesPush in CommandRouter) requires paths
// rooted at site/modules/, so each file's relativePath must include that
// prefix as it will exist on the remote ProcessWire install.
const REPO_ROOT  = path.resolve(process.cwd());
const REMOTE_PREFIX = 'site/modules/PromptWire/';

// Skip noise: build artefacts, node_modules, git internals, OS junk, scripts/.
const SKIP_DIRS = new Set([
  '.git', 'node_modules', '.cursor', '.idea', '.vscode',
  'mcp-server',  // mcp-server runs locally only; no need on production
  'scripts',     // tooling, not runtime
]);

const SKIP_EXACT_FILES = new Set([
  '.DS_Store', 'package-lock.json',
  // v1.9.2 — Repo docs are tracked for GitHub readers but ProcessWire does
  // not load any of them at runtime. Keeping them out of the production push
  // (a) shrinks each deploy from ~556KB to ~470KB and (b) closes the
  // SESSION-NEXT.md disclosure: that file is gitignored precisely because
  // it holds internal infra notes, but earlier versions of this script
  // ignored .gitignore entirely and pushed it anyway, leaving it readable
  // at site/modules/PromptWire/SESSION-NEXT.md on the remote. The existing
  // file on production must be deleted manually (FTP / hosting panel) —
  // skipping it here only prevents future deploys from re-uploading it.
  'ROADMAP.md', 'README.md', 'CHANGELOG.md', 'SESSION-NEXT.md',
]);

const ALLOWED_EXTENSIONS = new Set([
  'php', 'module', 'js', 'css', 'json', 'md', 'html', 'txt', 'png', 'svg',
  'gif', 'jpg', 'jpeg', 'webp',
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
      // Files with no extension (e.g. LICENSE) — keep at root only.
      if (!ext && entry.name !== 'LICENSE' && entry.name !== 'README') continue;
      out.push(full);
    }
  }
}

const localFiles = [];
await walk(REPO_ROOT, localFiles);

console.log(`Found ${localFiles.length} files to push.`);

const payload = localFiles
  .map((full) => {
    const rel = REMOTE_PREFIX + path.relative(REPO_ROOT, full);
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
        'Content-Type':      'application/json',
        'X-PromptWire-Key':  REMOTE_KEY,
        'X-PromptWire-Client': 'push-self-to-remote/1.0',
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
