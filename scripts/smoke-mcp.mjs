#!/usr/bin/env node
/**
 * PromptWire MCP smoke test.
 *
 * Answers the question "is the MCP server I'm about to use actually the build
 * I think it is?" — the one question v1.13.0 could not answer, because the
 * handshake version was a hardcoded literal while dist/ was stale (see
 * _docs/audits/2026-09-11-mcp-release-integrity-audit.md).
 *
 * It checks four layers, in order of how they can lie to you:
 *
 *   1. Source    declared tools (name: 'pw_x') == dispatched tools (case 'pw_x')
 *   2. Version   module @version == its getModuleInfo version == package.json
 *   3. Artifact  dist/index.js exists and is newer than every src/*.ts file
 *   4. Runtime   a real MCP handshake over stdio, asserting the server reports
 *                the package.json version and lists exactly the source's tools
 *
 * If PW_PATH is set it also calls pw_health, so a passing run means the server
 * can genuinely talk to a site — not merely that it started.
 *
 * Usage (from the repo root):
 *   node scripts/smoke-mcp.mjs                     # all checks, health if PW_PATH is set
 *   PW_PATH=/path/to/site node scripts/smoke-mcp.mjs
 *   node scripts/smoke-mcp.mjs --no-health         # skip the live site call
 *   node scripts/smoke-mcp.mjs --allow-stale       # tolerate a stale dist/ (not recommended)
 *
 * Exit code 0 = every check passed, 1 = something is wrong.
 * No dependencies; Node >= 18.
 */

import { spawn } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { createInterface } from 'node:readline';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT  = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SERVER_DIR = path.join(REPO_ROOT, 'mcp-server');
const SRC_DIR    = path.join(SERVER_DIR, 'src');
const SRC_ENTRY  = path.join(SRC_DIR, 'index.ts');
const DIST_ENTRY = path.join(SERVER_DIR, 'dist', 'index.js');
const MANIFEST   = path.join(SERVER_DIR, 'package.json');

const NO_HEALTH   = process.argv.includes('--no-health');
const ALLOW_STALE = process.argv.includes('--allow-stale');
const PW_PATH     = process.env.PW_PATH;

const results = [];
let failed = 0;

function pass(label, detail = '') {
  results.push(`\u2713 ${label}${detail ? ` \u2014 ${detail}` : ''}`);
}
function fail(label, detail = '') {
  failed++;
  results.push(`\u2717 ${label}${detail ? ` \u2014 ${detail}` : ''}`);
}
function skip(label, detail = '') {
  results.push(`\u00b7 ${label}${detail ? ` \u2014 ${detail}` : ''}`);
}

// ---------------------------------------------------------------------------
// 1. Source invariants
// ---------------------------------------------------------------------------

if (!existsSync(SRC_ENTRY)) {
  console.error(`Source entry not found: ${SRC_ENTRY}`);
  process.exit(1);
}

const source = readFileSync(SRC_ENTRY, 'utf8');

const declared = new Set(
  [...source.matchAll(/name:\s*'((?:pw_)[a-z0-9_]+)'/g)].map((m) => m[1])
);
const dispatched = new Set(
  [...source.matchAll(/case\s+'((?:pw_)[a-z0-9_]+)'/g)].map((m) => m[1])
);

const orphans = [...dispatched].filter((n) => !declared.has(n));      // handled but unlisted
const deadCodes = [...declared].filter((n) => !dispatched.has(n));    // listed but no handler

if (declared.size === 0) {
  fail('source: no tool declarations found', SRC_ENTRY);
} else if (orphans.length === 0 && deadCodes.length === 0) {
  pass('source: declared tools == dispatched tools', `${declared.size} tools`);
} else {
  if (deadCodes.length) fail('source: declared but not dispatched', deadCodes.join(', '));
  if (orphans.length) fail('source: dispatched but not declared', orphans.join(', '));
}

// ---------------------------------------------------------------------------
// 2. Built artifact
// ---------------------------------------------------------------------------

let manifest = null;
try {
  manifest = JSON.parse(readFileSync(MANIFEST, 'utf8'));
} catch (err) {
  fail('manifest: mcp-server/package.json is unreadable', err.message);
}

// ---------------------------------------------------------------------------
// 1b. Version declarations
//
// A release version lives in three places, and a missed one is invisible until
// something downstream reports the old number: the module docblock, the same
// module's getModuleInfo(), and the MCP manifest. v1.13.1 shipped with the
// getModuleInfo key left behind — hence this check.
// ---------------------------------------------------------------------------

const MODULE_FILE = path.join(REPO_ROOT, 'PromptWire.module.php');

if (!manifest) {
  skip('version: declarations agree', 'package.json unreadable');
} else if (!existsSync(MODULE_FILE)) {
  fail('version: PromptWire.module.php is missing', MODULE_FILE);
} else {
  const php = readFileSync(MODULE_FILE, 'utf8');
  const docblock = (php.match(/@version\s+([0-9][0-9.]*)/) || [])[1] || null;
  const infoVersion = (php.match(/'version'\s*=>\s*'([0-9][0-9.]*)'/) || [])[1] || null;

  if (!docblock || !infoVersion) {
    fail('version: could not read the module version', `docblock=${docblock} getModuleInfo=${infoVersion}`);
  } else if (docblock !== infoVersion) {
    fail('version: module docblock disagrees with getModuleInfo', `@version ${docblock} vs 'version' => ${infoVersion}`);
  } else if (docblock !== manifest.version) {
    fail('version: module and MCP manifest disagree', `module ${docblock}, package.json ${manifest.version}`);
  } else {
    pass('version: declarations agree', `module + mcp-server both ${docblock}`);
  }
}

if (!existsSync(DIST_ENTRY)) {
  fail('artifact: dist/index.js is missing', 'run: npm run build (in mcp-server/)');
} else {
  const distMtime = statSync(DIST_ENTRY).mtimeMs;

  // Recursive walk kept manual: readdirSync({recursive:true}) needs Node 20.1+,
  // and this script runs on the declared floor (engines: >=18).
  const srcFiles = [];
  (function walk(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (entry.name.endsWith('.ts')) srcFiles.push(full);
    }
  })(SRC_DIR);

  const newest = srcFiles
    .map((f) => ({ f, mtime: statSync(f).mtimeMs }))
    .sort((a, b) => b.mtime - a.mtime)[0];

  if (newest && newest.mtime > distMtime) {
    const label = path.relative(SERVER_DIR, newest.f);
    if (ALLOW_STALE) {
      skip('artifact: dist/ predates source', `${label} is newer (--allow-stale)`);
    } else {
      fail('artifact: dist/ predates source', `${label} is newer \u2014 rebuild: npm run build`);
    }
  } else {
    pass('artifact: dist/index.js is current', `${srcFiles.length} source files, none newer`);
  }
}

// ---------------------------------------------------------------------------
// 3. Runtime handshake
// ---------------------------------------------------------------------------

const PROTOCOL_VERSION = '2024-11-05';
const pending = new Map();
const stderrLines = [];
let child = null;
let nextId = 1;

function rpc(method, params, timeoutMs = 20000) {
  const id = nextId++;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new Error(`timed out after ${timeoutMs}ms calling ${method}`));
    }, timeoutMs);
    pending.set(id, {
      resolve: (v) => { clearTimeout(timer); resolve(v); },
      reject: (e) => { clearTimeout(timer); reject(e); },
    });
    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n');
  });
}

function notify(method, params) {
  child.stdin.write(JSON.stringify({ jsonrpc: '2.0', method, params }) + '\n');
}

function stopChild() {
  if (child && child.exitCode === null && !child.killed) child.kill();
}

try {
  child = spawn(process.execPath, [DIST_ENTRY], {
    cwd: SERVER_DIR,
    env: process.env,
    stdio: ['pipe', 'pipe', 'pipe'],
  });

  child.stderr.on('data', (d) => {
    stderrLines.push(...String(d).split('\n').filter((l) => l.trim()));
  });

  child.on('exit', (code) => {
    for (const [id, p] of pending) {
      pending.delete(id);
      p.reject(new Error(`server exited (code ${code}) before answering`));
    }
  });

  createInterface({ input: child.stdout }).on('line', (line) => {
    let msg;
    try { msg = JSON.parse(line); } catch { return; }
    if (msg.id && pending.has(msg.id)) {
      const p = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) p.reject(new Error(msg.error.message ?? JSON.stringify(msg.error)));
      else p.resolve(msg.result);
    }
  });

  const init = await rpc('initialize', {
    protocolVersion: PROTOCOL_VERSION,
    capabilities: {},
    clientInfo: { name: 'promptwire-smoke', version: '1.0.0' },
  });
  notify('notifications/initialized');

  const info = init?.serverInfo ?? {};
  if (info.name !== 'promptwire') {
    fail('handshake: unexpected server name', JSON.stringify(info.name));
  } else if (!manifest) {
    fail('handshake: cannot verify version', 'package.json unreadable');
  } else if (info.version !== manifest.version) {
    fail(
      'handshake: reported version != package.json',
      `server says "${info.version}", manifest says "${manifest.version}" \u2014 rebuild and re-check`
    );
  } else {
    pass('handshake: version matches manifest', `${info.name} ${info.version} (protocol ${init.protocolVersion})`);
  }

  const list = await rpc('tools/list', {});
  const served = new Set((list?.tools ?? []).map((t) => t.name));

  if (served.size === 0) {
    fail('tools/list: server returned no tools');
  } else if (declared.size && served.size !== declared.size) {
    fail('tools/list: count != source', `server has ${served.size}, source declares ${declared.size}`);
  } else if (![...served].every((n) => declared.has(n))) {
    const extra = [...served].filter((n) => !declared.has(n));
    fail('tools/list: names not in source', extra.join(', '));
  } else {
    pass('tools/list: matches source', `${served.size} tools`);
  }

  if (NO_HEALTH) {
    skip('pw_health: skipped', '--no-health');
  } else if (!PW_PATH) {
    skip('pw_health: skipped', 'PW_PATH not set (set it to smoke-test a live site)');
  } else {
    const res = await rpc('tools/call', { name: 'pw_health', arguments: {} }, 45000);
    const text = (res?.content ?? []).filter((c) => c.type === 'text').map((c) => c.text).join('\n');
    let payload = null;
    try { payload = JSON.parse(text); } catch { /* non-JSON error text */ }

    if (res?.isError || payload?.status !== 'ok') {
      fail('pw_health: site not reachable', firstLine(text) || 'no output');
    } else {
      pass(
        'pw_health: site healthy',
        `PW ${payload.pwVersion ?? '?'}${payload.siteName ? ` \u2014 ${payload.siteName}` : ''}`
      );
    }
  }
} catch (err) {
  fail('handshake: could not complete', err.message);
  if (stderrLines.length) {
    for (const l of stderrLines.slice(-5)) console.log(`    server stderr | ${l}`);
  }
} finally {
  stopChild();
}

function firstLine(s) {
  return String(s ?? '').split('\n').map((l) => l.trim()).find(Boolean) ?? '';
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

console.log('PromptWire MCP smoke test\n');
console.log(`  repo      ${REPO_ROOT}`);
console.log(`  artifact  ${path.relative(REPO_ROOT, DIST_ENTRY)}`);
if (manifest) console.log(`  manifest  version ${manifest.version}`);
console.log('');
for (const line of results) console.log(`  ${line}`);
console.log('');

if (failed) {
  console.log(`  ${results.length - failed} passed, ${failed} failed\n`);
  process.exit(1);
}

console.log(`  ${results.length} checks passed\n`);
process.exit(0);
