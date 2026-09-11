#!/usr/bin/env node
/**
 * PromptWire YAML round-trip check.
 *
 * The sync layer writes page fields into `page.yaml` and reads them back before a
 * push. v1.13.1 fixed both PHP *writers* (they were emitting an invalid `\'`
 * escape). This checks the other half: that the *reader* decodes what the writers
 * emit. It did not — `parseYamlValue()` stripped the surrounding quotes and left
 * `\"` and `\\` in the value, so the next push wrote those escapes into the field,
 * doubling the backslashes on every pull/push cycle. A summary containing a
 * straight quote came back as `…is \"How much…?\"` (ensoul, 2026-09-11).
 *
 * The invariant is one line:
 *
 *     parseYamlValue(yamlValue($v)) === $v
 *
 * for every value the writer can be asked to quote. No dependencies; Node >= 18.
 *
 * Usage (from the repo root):
 *   node scripts/check-sync-yaml.mjs
 *   PHP_PATH=/Applications/MAMP/bin/php/php8.3.30/bin/php node scripts/check-sync-yaml.mjs
 *   node scripts/check-sync-yaml.mjs --php=/usr/local/bin/php
 *
 * Exit code 0 = invariant holds, 1 = the sync layer cannot round-trip a value.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SYNC_MANAGER = path.join(REPO_ROOT, 'src', 'Sync', 'SyncManager.php');

const phpArg = process.argv.find((a) => a.startsWith('--php='));
const PHP = phpArg ? phpArg.slice('--php='.length) : (process.env.PHP_PATH || 'php');

const fail = (msg, detail) => {
  console.error(`FAIL  ${msg}`);
  if (detail) console.error(`      ${detail}`);
  process.exit(1);
};

if (!existsSync(SYNC_MANAGER)) fail('SyncManager.php not found', SYNC_MANAGER);

// The cases that broke: a quote or backslash alongside a character that forces
// the writer to quote the scalar (: # [ ] { } | > & * ! ?).
const CASES = [
  'As the trend … is "How much does a basement conversion cost to build?"',
  'has "quotes" inside',
  'C:\\temp\\file',
  "it's single quoted",
  'trailing backslash \\',
  'a "quoted" word: with a colon',
  'hash # and bracket [1]',
  'ampersand & pipe | star *',
  'plain value, no special characters',
  'apostrophe’s curly and em—dash',
];

const harness = `<?php
require_once ${JSON.stringify(SYNC_MANAGER)};
$cls = 'PromptWire\\\\Sync\\\\SyncManager';
if (!class_exists($cls)) { fwrite(STDERR, "class not loadable\\\\n"); exit(2); }
$write = new ReflectionMethod($cls, 'yamlValue');
$write->setAccessible(true);
$read = new ReflectionMethod($cls, 'parseYamlValue');
$read->setAccessible(true);
$obj = (new ReflectionClass($cls))->newInstanceWithoutConstructor();
$cases = json_decode(file_get_contents($argv[1]), true);
$out = [];
foreach ($cases as $v) {
    $yaml = $write->invoke($obj, $v, 1);
    $back = $read->invoke($obj, $yaml);
    $out[] = ['value' => $v, 'yaml' => $yaml, 'read' => $back, 'ok' => $back === $v];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
`;

const dir = mkdtempSync(path.join(tmpdir(), 'pw-yaml-'));
const harnessFile = path.join(dir, 'harness.php');
const casesFile = path.join(dir, 'cases.json');
writeFileSync(harnessFile, harness);
writeFileSync(casesFile, JSON.stringify(CASES));

let raw;
try {
  raw = execFileSync(PHP, [harnessFile, casesFile], { encoding: 'utf8' });
} catch (err) {
  fail(
    `could not run PHP (${PHP})`,
    err.stderr ? String(err.stderr).trim().slice(0, 300) : String(err.message).slice(0, 300)
  );
}

let results;
try {
  results = JSON.parse(raw);
} catch {
  fail('harness did not return JSON', String(raw).slice(0, 300));
}

let failed = 0;
for (const r of results) {
  if (r.ok) {
    console.log(`ok    ${JSON.stringify(r.value).slice(0, 62)}`);
  } else {
    failed++;
    console.log(`FAIL  ${JSON.stringify(r.value)}`);
    console.log(`      yaml written : ${r.yaml}`);
    console.log(`      read back as : ${JSON.stringify(r.read)}`);
  }
}

console.log('');
if (failed) {
  console.log(`${failed} of ${results.length} values did not survive the round trip —`);
  console.log('the reader is discarding escapes the writer emitted (see _docs/audits/, Finding F).');
  process.exit(1);
}
console.log(`All ${results.length} values survived the round trip (parseYamlValue(yamlValue(v)) === v).`);
