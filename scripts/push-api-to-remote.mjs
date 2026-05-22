#!/usr/bin/env node
/**
 * Deploy promptwire-api.php to a remote ProcessWire site root via files:push.
 *
 * The remote PromptWire module must be at v1.12.1+ (when promptwire-api.php
 * was added to the filesPush allowed-prefix list). If upgrading from an
 * older version, push the module PHP first:
 *
 *   node scripts/push-self-to-remote.mjs
 *   node scripts/push-api-to-remote.mjs
 *
 * Usage (from repo root):
 *   PW_REMOTE_URL=... PW_REMOTE_KEY=... node scripts/push-api-to-remote.mjs [--dry-run]
 *
 * Optional:
 *   --remote-name=promptwire-api.php   Remote filename (default: promptwire-api.php)
 */

import { readFileSync, statSync } from 'fs';
import path from 'path';

const REMOTE_URL = process.env.PW_REMOTE_URL;
const REMOTE_KEY = process.env.PW_REMOTE_KEY;
const DRY_RUN    = process.argv.includes('--dry-run');

function getArg(name) {
  const prefix = `--${name}=`;
  const arg = process.argv.find((a) => a.startsWith(prefix));
  return arg ? arg.slice(prefix.length) : null;
}

const REMOTE_NAME = getArg('remote-name') ?? 'promptwire-api.php';

if (!REMOTE_URL || !REMOTE_KEY) {
  console.error('PW_REMOTE_URL and PW_REMOTE_KEY env vars are required.');
  process.exit(1);
}

const REPO_ROOT = path.resolve(process.cwd());
const API_PATH  = path.join(REPO_ROOT, 'api', 'promptwire-api.php');

let size;
try {
  size = statSync(API_PATH).size;
} catch {
  console.error(`API file not found: ${API_PATH}`);
  process.exit(1);
}

const payload = [{
  relativePath:  REMOTE_NAME,
  contentBase64: readFileSync(API_PATH).toString('base64'),
  size,
}];

console.log(`Source: ${API_PATH}`);
console.log(`Remote: ${REMOTE_NAME}`);
console.log(`Size:   ${(size / 1024).toFixed(1)} KB`);

if (DRY_RUN) {
  console.log('\n--dry-run set: not contacting remote.');
  process.exit(0);
}

const filesJson = JSON.stringify(payload.map(({ relativePath, contentBase64 }) => ({
  relativePath, contentBase64,
})));

const body = {
  command: 'files:push',
  args:    [`--files=${filesJson}`, '--confirm'],
};

let res;
try {
  res = await fetch(REMOTE_URL, {
    method: 'POST',
    headers: {
      'Content-Type':        'application/json',
      'X-PromptWire-Key':    REMOTE_KEY,
      'X-PromptWire-Client': 'push-api-to-remote/1.0',
    },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(120_000),
  });
} catch (err) {
  console.error(`Network error: ${err.message}`);
  process.exit(1);
}

if (!res.ok) {
  console.error(`HTTP ${res.status}: ${await res.text()}`);
  process.exit(1);
}

const data = await res.json();
if (data.error) {
  console.error(`Remote error: ${data.error}`);
  process.exit(1);
}

console.log(`Done. written: ${data.written ?? 0}  skipped: ${data.skipped ?? 0}`);
