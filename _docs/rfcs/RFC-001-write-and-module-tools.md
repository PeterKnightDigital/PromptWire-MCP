# RFC-001: Database write tool, third-party module data tools, and safer defaults


|                          |                                                                                                                                     |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| **Status**               | Partially implemented — §4.4 and §4.6 shipped in v1.12.0 (11 May 2026); §4.1, §4.2, §4.3, §4.5 deferred pending a second data point |
| **Author**               | Peter Knight                                                                                                                        |
| **Created**              | 2026-05-11                                                                                                                          |
| **Target repo**          | `PromptWire` (module + MCP server)                                                                                                  |
| **Reproduction context** | peterknight.digital deploy of contact + support forms, May 2026                                                                     |


> **Implementation update (2026-05-11):** on review, the larger write-
> tooling proposals here (`pw_db_execute`, `pw_form` family, the
> `pw_site_compare` module-table extension, and the deferred
> `pw_module_call`) are scoped off a single incident and would each add a
> permanent surface for a problem we've hit once. They've been deferred
> until the same shape of problem recurs against a *second* module or in a
> *second* deploy. Re-open this RFC when any of the trigger conditions in
> §11 below are met. The two changes that genuinely paid for themselves
> day-one — the `pw_site_sync` modules-default flip (§4.4) and the
> `pw_health.writesEnabled` fix (§4.6) — shipped in v1.12.0. See the
> CHANGELOG for the implementation details.

> **Note:** this RFC was authored in the `peterknight.digital` project repo
> at `_docs/promptwire-rfc-001-write-and-module-tools.md` and is intended to
> be moved into the PromptWire repo (e.g. `docs/rfcs/`) when work begins.
> Any references to "this project" in evidence below mean
> `peterknight.digital`, not the PromptWire repo itself.

---

## 1. Summary

PromptWire today covers ProcessWire's native data model end-to-end (pages,
fields, templates, file/image fields). Anything outside that — third-party
module data, arbitrary DB writes, per-form module config — has no MCP path
and forces a fall-back to manual admin or one-shot PHP scripts.

This RFC proposes:

1. A guarded `**pw_db_execute`** tool for write SQL.
2. A generic `**pw_form**` tool family for FormBuilder (most common
  third-party blocker hit so far).
3. An `**includeModuleTables**` option on `pw_site_compare`.
4. A **default change** to `pw_site_sync` so it no longer pushes
  `site/modules/`** unless explicitly opted in.
5. (Optional, deferred) A generic `**pw_module_call**` escape hatch for the
  long tail of module-specific operations.

Plus housekeeping: stop reporting `writesEnabled: false` as a cosmetic flag
in `pw_health` — either make it real, or remove it.

---

## 2. Motivation

In a routine session deploying two FormBuilder forms (contact + support) to
production, the following blockers came up in sequence:

1. Need to set `required: true` on N fields in two FormBuilder forms on
  prod. **No MCP tool for this.** `pw_db_query` is read-only.
2. Tried to disable read-only mode based on `pw_health` reporting
  `writesEnabled: false`. Spent time on this before discovering the flag
   is **cosmetic** (per the project's own deployment playbook). Misleading
   surface.
3. Wrote a one-shot PHP script (`_pw-set-form-required.php`), SFTP'd to
  prod root, ran via browser, self-deleted. Worked, but is friction we'd
   like to remove for any future module-config change.
4. After the deploy, prod forms looked nothing like local. Cause: the
  `framework` setting (Uikit3 vs Basic) had drifted between sites. **
   `pw_site_compare` couldn't see this** because it lives in FormBuilder's
   `forms` table, not in pages/fields/templates.
5. Earlier in the session, a `pw_site_sync` dry-run pulled the entire
  `site/modules/`** tree into the plan by default. Easy footgun for a
   template-only deploy.

Each of these is solvable in isolation; together they suggest PromptWire
needs a thin layer of "things outside PW core" so we don't keep falling
back to scripts.

---

## 3. Goals and non-goals

**Goals**

- Cover write paths for arbitrary tables behind explicit safety rails.
- Cover FormBuilder explicitly (highest-traffic third-party module in our
stack) with module-API-aware tools.
- Make cross-site drift detection extensible to module-owned tables.
- Make `pw_site_sync` defaults match the most common intent.
- Stop misleading users with cosmetic flags.

**Non-goals**

- Generic write coverage for every third-party PW module out of the box
(use `pw_module_call` escape hatch + per-module tools as demand emerges).
- Replacing the FormBuilder admin UI with MCP tools — only the operations
we routinely script are in scope.
- Anything outside PromptWire (e.g. ProcessWire's Markup Regions and
`$config->styles` rendering, which bit the same workflow but is a PW
template author's responsibility).

---

## 4. Proposed design

### 4.1 `pw_db_execute` — guarded write SQL

**Status: deferred (2026-05-11).** Evidence is one form-required toggle.
The May 2026 workaround (one-shot PHP script, SFTP'd, browser-run, self-
deleting) is friction but works, bootstraps through PW so hooks fire and
caches clear, and is auditable. Building a guarded write-SQL tool means
owning a perpetual security surface (injection, multi-statement,
dangerous-keyword detection, transaction edge cases, audit log rotation,
redaction rules) for a problem hit once. Re-open when the same
operation has been scripted three times or a second module needs
arbitrary writes.

```
pw_db_execute({
  sql: string,                      // single statement
  expectedRowCount?: number,        // refuse if affected > expected * tolerance
  expectedRowCountTolerance?: number, // default 1.5
  confirm: true,                    // required; tool refuses without it
  dangerous?: boolean,              // required for DROP, TRUNCATE, DELETE without WHERE, ALTER
  site: 'local' | 'remote' | 'both' // 'both' runs sequentially with rollback on first failure
})
```

Behaviour:

- **Server-side enforcement.** Mirror the client-side checks in CommandRouter
PHP so a misconfigured client cannot bypass safety rails.
- **Auto-transaction.** Wraps in `START TRANSACTION` / `COMMIT`. Rolls back
on any error or row-count mismatch.
- **Returns:** `{ affectedRows, before: [...], after: [...], duration_ms }`.
`before` / `after` are sampled rows (configurable cap, default 10) for
visual confirmation in chat.
- **Refuses by default:** statements without `WHERE` for `UPDATE` / `DELETE`,
any `DROP` / `TRUNCATE` / `ALTER`. Override with `dangerous: true`.
- **Single statement only.** No multi-statement payloads, no `;`-stacked
queries (defence in depth against SQL injection in tool args).

### 4.2 `pw_form` — FormBuilder tool family

**Status: deferred (2026-05-11).** Same reasoning as §4.1 — `set_required`
was needed once. If the same operation comes up a second time, build the
smallest possible thing first (single `pw_form_set_required` tool, not
the full umbrella) and only grow the family if it justifies itself.

Single tool with an `action` parameter; cheaper to maintain than five
descriptors and easier to extend.

```
pw_form({
  action: 'list' | 'get' | 'set_required' | 'set_framework' | 'compare',
  name?: string,                    // form name, required for non-list actions
  fields?: { [fieldName]: boolean }, // for set_required
  framework?: 'Uikit3' | 'Basic' | 'Bootstrap5', // for set_framework
  site: 'local' | 'remote' | 'both',
  dryRun?: boolean                  // default true for write actions
})
```

Implementation: uses FormBuilder's own API (`$forms->load(...)` /
`$forms->save(...)`) so module hooks fire and FormBuilder's caches are
invalidated. Avoids the brittleness of writing directly to `forms.data`
JSON via SQL.

`compare` action diffs framework, theme, action URL, and per-field
`required` flags between two sites — the specific drift class that
`pw_site_compare` currently misses.

### 4.3 `pw_site_compare` extension

**Status: deferred (2026-05-11).** The framework-drift incident was
diagnosed in five minutes once we knew where to look. Re-open when drift
detection becomes a recurring source of bugs (likely only on a multi-
FormBuilder-site deploy).

Add an `includeModuleTables` option (default `false`):

```
pw_site_compare({
  ...existing,
  includeModuleTables?: boolean | string[]  // true = all known, or list specific tables
})
```

Initial supported tables (configurable):

- `forms` (FormBuilder)
- Add per request as more modules need it.

Returns a new `moduleTables` section in the diff output, listing rows that
exist on one side but not the other, plus rows whose JSON payloads differ
(field-level diff for known JSON columns).

### 4.4 `pw_site_sync` default change — exclude `site/modules/**`

**Status: shipped in v1.12.0 (2026-05-11).** Implemented as proposed,
with two minor refinements vs. the original draft:

- The flag is named `pushModules` (not `includeModules`) because in a
sync context "include" reads ambiguously — it could mean "include in
the diff" or "include in the push". `pushModules` is unambiguous.
- The pattern is `site/modules/*` (single `*`), not `site/modules/`**.
PHP's `fnmatch()` on the inventory side already treats `*` as
matching path separators, so the single-star form covers the whole
tree recursively and matches the existing `site/modules/PromptWire/*`
convention used elsewhere.

Dry-run plans now surface a `files.modulesExcluded` block when the auto-
exclusion is in effect, so the change is never silent on first
encounter. `pw_site_compare` was deliberately *not* changed — the
diagnostic tool still shows module diffs by default, only the action
tool's defaults are stricter.

### 4.5 `pw_module_call` — generic escape hatch (deferred)

**Status: deferred (2026-05-11).** Already correctly identified as
"defer" in the original draft; the May 2026 implementation review
confirmed it. Re-open only after a second module needs it AND the §4.1
write-SQL surface has stabilised — `pw_module_call` adds at least as
much risk and shouldn't land before the simpler path has been proven.

```
pw_module_call({
  module: string,                   // e.g. 'FormBuilder', 'MediaHub'
  method: string,
  args?: unknown[],
  site: 'local' | 'remote' | 'both',
  confirm: true
})
```

Off by default. Opt-in via a `PROMPTWIRE_ALLOW_MODULE_CALL` constant in
`site/config-promptwire.php`. Gives us a way to handle the long tail
without writing a bespoke tool every time.

**Defer this** until we see >1 module needing it. Premature for now.

### 4.6 Housekeeping: `pw_health.writesEnabled`

**Status: shipped in v1.12.0 (2026-05-11).** Took option (a) — wired the
flag to the actual gate state. Implementation: `php_sapi_name() === 'cli'`
short-circuits to `enabled: true / reason: 'cli'`; web invocations check
`PROMPTWIRE_API_KEY` (set in any reachable web caller because the API
endpoint validates it before reaching `health()`) and append
`+ip-allowlist` when `PROMPTWIRE_ALLOWED_IPS` is configured, `+http`
when `PROMPTWIRE_ALLOW_HTTP` is set. A new sibling field
`writesEnabledReason` carries the breakdown so the *why* is visible
without trial and error — addresses the original incident's failure mode
(seeing `false`, assuming writes are blocked, chasing a non-issue) by
making the field both honest and self-explanatory.

---

## 5. Alternatives considered

- **Per-action tools instead of `pw_form` umbrella** (`pw_form_list`,
`pw_form_get`, `pw_form_set_required`, `pw_form_set_framework`,
`pw_form_compare`). Rejected: five tool descriptors for one module is
too much surface; harder to extend with new actions later.
- **Raw SQL only, no `pw_form`.** Rejected: bypasses module hooks and
cache invalidation. Direct `UPDATE forms SET data = JSON_SET(...)` was
the workaround in the May 2026 incident; it works but is brittle and
loses the cache clear.
- **Allow mutations in `pw_db_query` itself with a flag.** Rejected:
splitting read and write into separate tools makes intent explicit at
the call site and lets each carry different defaults (read = no
confirmation, write = `confirm: true`).
- **Drop `pw_site_sync`'s modules support entirely.** Rejected: we do
occasionally want to push a module update; opt-in via
`includeModules: true` keeps the door open without making it the
default.

---

## 6. Migration and backwards compatibility

- `pw_db_execute` is additive. No breaking change.
- `pw_form` is additive.
- `pw_site_compare`'s new option defaults to `false` — existing callers
unaffected.
- `pw_site_sync` default change is the **only breaking change**. Any
existing scripts or saved prompts that expect modules to be included
will silently stop pushing them. Mitigations:
  - Major version bump.
  - Loud deprecation warning in the previous minor: "Default is changing
  in vN+1; pass `includeModules: true` to preserve current behaviour."
  - Changelog entry with the exact replacement snippet.
- `pw_health.writesEnabled` removal/repurpose is technically breaking for
anyone scripting against the field. Same warning approach.

---

## 7. Security considerations

- `**pw_db_execute` is the single highest-risk surface added here.**
Dual-layer enforcement (MCP client + CommandRouter PHP) is mandatory.
- `**pw_module_call` is even higher risk** — it's why it's deferred and
config-gated.
- All write tools must respect the existing IP allowlist
(`PROMPTWIRE_ALLOWED_IPS`) and key auth.
- New tools should log to `site/assets/logs/promptwire-writes.log` with:
timestamp, caller IP, command, args (with sensitive values redacted),
success/failure. Operators can audit after the fact.
- Consider rate-limiting destructive operations (`pw_db_execute` with
`dangerous: true`, `pw_module_call`) to N per minute per key.

---

## 8. Open questions

1. `**expectedRowCount` enforcement strictness.** Is `tolerance: 1.5`
  sensible (allow 50% over) or too loose? Should it default to exact?
2. `**pw_form` action set.** Is `set_required` + `set_framework` enough
  to cover 80% of FormBuilder ops, or do we also need `set_field_label`,
   `add_field`, `remove_field` from day one?
3. **Module table whitelist for `pw_site_compare`.** Should the supported
  tables list live in module code or in a config file users can extend?
4. **Backup before write.** Should `pw_db_execute` (and `pw_form`'s write
  actions) auto-snapshot the affected table before writing, like
   `pw_site_sync` does for the whole DB? Cheap insurance, small storage
   cost.
5. **Telemetry.** Worth tracking which gap-closure tools get used most,
  to inform future RFC priorities? Opt-in only.

---

## 9. Out of scope

- Markup Regions / `$config->styles` rendering. Bit the same May 2026
workflow but is a ProcessWire template-author concern, not PromptWire's.
- Generic third-party module coverage beyond FormBuilder. Add per request.
- Replacing the existing CLI surface — this RFC is purely additive on the
MCP/HTTP API side.

---

## 10. Implementation notes

The following sketch maps each change to the layer that has to move:


| Item                        | MCP server (Node)                  | CommandRouter (PHP)                                             | `promptwire-api.php`                                                                        |
| --------------------------- | ---------------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `pw_db_execute`             | New tool descriptor + handler      | New `db:execute` command + safety checks                        | Falls through to CommandRouter                                                              |
| `pw_form`                   | New tool descriptor + handler      | New `form:list/get/set_required/set_framework/compare` commands | Optionally a special-case handler for `form:`* if FormBuilder loading needs early bootstrap |
| `pw_site_compare` extension | Pass `includeModuleTables` through | Extend compare logic; define table registry                     | —                                                                                           |
| `pw_site_sync` default flip | Default arg change                 | —                                                               | —                                                                                           |
| `pw_module_call` (deferred) | New tool descriptor + handler      | New `module:call` with config gate                              | —                                                                                           |
| `pw_health` flag fix        | —                                  | Fix `health()` to compute real value, or remove                 | —                                                                                           |


---

## 11. Reopening conditions

Re-open the deferred sections (§4.1, §4.2, §4.3, §4.5) when **any one** of
the following becomes true:

- The same shape of "no MCP path for module data" hits a *second
distinct module* (not just FormBuilder again).
- The same shape of one-shot PHP script has been written *three times*.
- We're regularly deploying to *more than two sites* and module-table
drift becomes a recurring source of bugs.
- A future incident produces evidence that the May 2026 PHP-script
workaround is meaningfully more expensive than estimated (e.g.
blocked a deploy rather than just adding 20 minutes to it).

Until then, the RFC's instinct is right but the scope was overfit to a
single incident. Two cheap items shipped; the rest is speculative
tooling held until evidence justifies the surface area.

---

*End of RFC-001.*