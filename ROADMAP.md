# Roadmap

Improvements gathered from real-world deployments. Items are roughly ordered by leverage — i.e. how much friction they remove from the most common "ship schema + new pages from local to production" workflow.

This is a working list, not a release plan. Issues / PRs against any of these are welcome.

---

## v1.8.0 — Module-aware Site Sync (Modules, Users, Custom Tables)

A second-generation site sync that handles ProcessWire data living **outside** the page tree: module configs, module install state, user accounts, and custom module-owned database tables (e.g. FormBuilder forms). Closes the gap revealed by deploying a multi-module site to production for the first time using v1.7.0.

### The problem

v1.7.0's `pw_site_sync` handles three dimensions: schema, pages, and template/module files. In production it became clear that this is necessary but not sufficient. A true "replica site" also requires:

- **Module configs** (`modules.data`) — Login Register Pro, FormBuilder, SeoNeo settings live here, with embedded references to local-only IDs (field IDs, role IDs, page IDs) that don't match the remote.
- **Module install state** — pushing the `.module.php` file is not the same as installing it. Modules need an explicit install step that runs the module's `___install()` and updates `modules.installed`.
- **Schema "membership" changes** — schema sync today updates field/template settings, but does not assign newly-created fields into existing template fieldgroups. Adding `seoneo_tab` to 32 templates after installing SeoNeo required 32 manual edits.
- **User accounts** — additive only, never overwrite. Must preserve hashed password + salt so users can keep logging in.
- **Module-owned custom tables** — FormBuilder's `forms` table holds form definitions and we want it pushable; `forms_entries` holds customer submissions and **must never be overwritten or erased in the production direction** (data loss risk).

Without these, a "fresh-install" of a complex production site requires hours of manual admin clicks and SQL inserts after `pw_site_sync` finishes.

### Design principles (extends v1.7.0)

6. **Translate IDs by name across environments.** Local field/role/page IDs differ from remote. Module configs that store them as raw integers must be translated using name → ID lookups on the target before being written.
7. **Additive-by-default for tables outside the page system.** Users, forms, and other module-owned rows: never overwrite when matched by natural key (name/email for users; name for forms). The user must opt in to overwrite per-row.
8. **Customer-data-bearing tables are append-only from local.** `forms_entries`, `lic_*` (license activations), and any future "submissions" tables follow this rule. Pulling from production for backup is fine; pushing local entries to production is refused at the registry layer (no opt-in flag overrides this — wrong tool for that job).

### New PHP commands (CommandRouter)

#### `module:install` / `module:uninstall`

Wraps `$modules->install($class)` / `$modules->uninstall($class)`. Refreshes module cache before and after. Returns the installed module's class name and isInstalled status. Refuses to install modules whose files don't exist on disk (no implicit upload).

#### `module:config:get` / `module:config:set`

Wraps `$modules->getModuleConfigData()` / `$modules->saveModuleConfigData()`. Idempotent; `set` accepts the full data object and replaces it.

#### `page:set-status`

Set the `status` flags on an existing page (hidden, unpublished, locked). Used during migrations to retire old pages on production without deleting them. Accepts `path`, `hidden: bool`, `unpublished: bool`, `locked: bool`. Returns the new combined status integer.

In our deployment, we needed this to hide old `/account/login/`, `/account/register/`, and `/account/forgot-password/` pages on production after the unified `/account/sign-in/` LRP page replaced them. Today this requires admin UI clicks per page.

#### `template:create` / `field:assign-to-template`

`template:create` accepts a name + initial fields list and creates a Template + Fieldgroup pair. Refuses if a template with the name already exists. Used to handle templates that are auto-created by modules at install time but not picked up by schema sync (e.g. LRP's `login-register` template).

`field:assign-to-template` accepts a template name + array of field names and adds the fields to the template's fieldgroup, preserving any existing fields. Idempotent — fields already present are skipped. This is the missing piece for "I just installed SeoNeo, now please put `seoneo_tab` on every content template."

#### `users:list` / `users:create`

`users:list` returns all users with name, email, roles, and selected `member_*` fields.

`users:create` accepts:
- `name`, `email` (required)
- `pass_hash` + `pass_salt` (optional — when both present, the `field_pass` row is updated directly to preserve the original credentials so the user can log in with their existing password)
- `roles` (array of role names; resolved to remote IDs)
- Any `member_*` field values

Refuses to create if a user with the same name OR email already exists. Returns the existing user's ID in that case so the caller knows to skip rather than fail.

#### `tables:dump` / `tables:apply`

Generic mechanism for module-owned tables. `tables:dump` returns the rows of a named table (paginated). `tables:apply` accepts rows and either INSERTs or UPDATEs based on a configurable conflict-key. A registry maps table names to:

- `conflictKey` — natural-key column (e.g. `name` for `forms`, `email` for users, `lic_key` for licenses)
- `mode` — `"additive"` (INSERT only when conflictKey not present), `"upsert"` (INSERT or UPDATE), or `"append-only"` (INSERT only, never matches existing — for entries/submissions tables)
- `direction` — `"any"`, `"local-to-remote"`, `"remote-to-local"`, or `"pull-only"` (refuses any push regardless of `mode`)

Default registry ships with these entries (extensible by user config):

| Table | Mode | Direction | Notes |
|---|---|---|---|
| `forms` | additive | local-to-remote | FormBuilder form definitions |
| `forms_entries` | append-only | pull-only | **Customer submissions — never overwritten** |
| `lic_activations` | append-only | pull-only | License activation events |
| (user tables) | (handled by `users:create`) | local-to-remote | Specialised: must preserve hashed password |

### New MCP tools (TypeScript)

#### `pw_module_install` / `pw_module_uninstall`

#### `pw_module_config`
- `action: "get" | "set" | "translate-and-set"`
- `class` — module class name
- `data` — for set actions
- For `translate-and-set`: accepts a config with placeholder names and resolves them to remote IDs server-side. E.g.:

  ```json
  {
    "registerFields_byName": ["email", "pass", "member_first_name", "member_last_name"],
    "loginRoles_byName": ["login-register", "member"],
    "pageId_byPath": "/account/sign-in/"
  }
  ```

  The remote PHP resolves each `*_byName` and `*_byPath` to the appropriate ID and writes the standard config keys (`registerFields`, `loginRoles`, `pageId`). Removes the need for the caller to issue separate `resolve-fields` / `resolve-roles` / `resolve-paths` calls.

#### `pw_users_sync`
- `direction: "local-to-remote"` (only direction supported initially)
- `mode: "additive"` (default — never overwrites existing remote users)
- Fetches local user list, fetches remote user list, identifies users present locally but not remotely (matched by email AND name), and creates them on remote with hashed password preserved.

#### `pw_tables_sync`
- `tables` — array of table names, or `"all"` to use the registry
- `direction`, `mode`, `dryRun`
- Honours each table's registry entry for safety constraints. The registry **cannot** be overridden to push to a `pull-only` table — that's a hard constraint, not a default.

### Schema sync gaps (extends existing tools)

The following gaps in `pw_schema_push` and `pw_schema_compare` were uncovered during the production deployment and should be fixed alongside v1.8.0:

1. **Fieldgroup membership is not synced.** When a field already exists on the remote with a different ID than local, and a local template's fieldgroup gains that field, the remote template's fieldgroup is not updated. Fix: after pushing a template, diff the local fieldgroup against the remote fieldgroup by field name and add the missing assignments.

2. **`new` templates report "unchanged"** when they don't exist on the remote at all. `pw_schema_compare` appears to only compare templates existing on both sides, missing the "local only" case. Repro: `pw_site_sync --scope=schema` returned `templates: {created: 0, updated: 0}` despite a local-only `login-register` template. We had to create it manually on remote.

3. **Templates auto-created by modules at install time** (LRP's `login-register`, SeoNeo's `seoneo_tab` field) need to either be picked up by schema sync after install, or excluded from schema diff (since they'll be created by the module install). Today they fall in a gap: schema sync ignores them, and module install on remote may not create them at the right time.

### Integration into `pw_site_sync`

A new `scope: "all"` (or `"full"`) value runs everything in dependency order:

1. Schema (fields → templates → fieldgroup assignments)
2. Files (templates, modules)
3. **Modules** (refresh → install pending → push translated configs)
4. Pages (parents before children, new before existing)
5. **Users** (additive)
6. **Module-owned tables** (registry-driven; `forms` etc.)
7. Verify

Each phase respects the same `excludeTemplates`, `backup`, `maintenance`, and `dryRun` parameters from v1.7.0.

### Safety guardrails

- **Whitelist of writable modules** for `module:install` and `module:config:set`. Default whitelist: any module that's already installed locally. Prevents an MCP agent from installing arbitrary modules from the internet.
- **`forms_entries`, `lic_activations`, and similar are pull-only at the registry layer.** No flag opts in — these tables can only be pulled to local for backup/analysis. Pushing requires editing the registry config explicitly, which is intentional friction.
- **User passwords are never logged.** The `pass_hash` / `pass_salt` fields are flagged sensitive in the response payload and replaced with `"<redacted>"` in any echo back.
- **Module install on remote requires the file to be on disk first.** Refuses with a clear error if not, so an agent can't accidentally trigger an install of a module whose code isn't yet shipped.

### Pre-existing PromptWire bugs to fix in this cycle

Items worth pulling forward into v1.8.0 because they were hit during the same session:

- **`pw_db_query --site=remote` silently runs against local DB.** The `--site` flag is consumed but not honoured. **This was the single highest-cost bug** of the deployment — caused multiple hours of misdiagnosis where production was assumed to be in sync (or modules assumed installed) when neither was true. Fix priority should match the impact: route to the remote DB when `--site=remote` is passed, or remove the flag entirely so callers know they're getting local data and use a different mechanism for remote queries.
- **`filesInventory` excludes files with `.module` extension.** FormBuilder and LoginRegisterPro both ship core files as `.module` (without `.php`), so they were silently omitted from `pw_site_sync`. Add `.module` to the default extension list.
- **`filesInventory` does not follow symlinks.** SeoNeo and StemplatesPro were locally symlinked to sibling repos; sync silently skipped them. Either follow symlinks (and document) or warn loudly when a synced directory contains an unexpanded symlink.
- **`pw_health.writesEnabled` is hardcoded to `false`.** Confusing during incident response — operators see writes are blocked when they aren't. Wire it to a real config (e.g. `PROMPTWIRE_READ_ONLY` env var) or remove the field. Existing roadmap item #5 covers this.
- **`pw_site_compare` and `pw_page_push` dry-run report phantom changes from value-format roundtripping.** After a successful page push, compare still flags the page as "modified" because the diff renderer normalises one side and not the other:
  - `blog_date` (datetime field): stored value reads back as epoch integer (`1775692800`) on one side and ISO string (`2026-04-09T00:00:00.000Z`) on the other. Same instant, different render → flagged different.
  - `blog_tags` / `blog_images` (Page fields): one side renders pipe-separated (`1187|1188`), the other comma-space separated (`1187, 1188`). Same IDs, different separator → flagged different.
  - **Effect:** after pushing 88 pages successfully, 18 still appear in `pw_site_compare` as "modified" with zero real differences. The operator either keeps pushing the same pages forever (no-op writes) or learns to ignore the compare output. Both are bad. Fix: normalise both sides to the storage representation before diffing, or pre-cast the local YAML value to the same type as the remote read.

### Compare/sync consistency

`pw_site_compare` and the actual sync engines should report the same numbers. During the v1.8.0 deployment, compare reported `schemaChanged: 33` before, during, and after a successful schema sync. The number never decremented. Possible causes:

- Compare looks at a different criterion than sync acts on (e.g. compare counts fieldgroup membership drift, sync only updates field/template settings)
- Compare results are cached and not invalidated after a sync
- Compare counts something that sync deliberately ignores (system templates, etc.)

Whichever it is, the user-facing metric should match. A compare that perpetually says "33 changes pending" after a successful sync is worse than no number at all — it conditions the operator to ignore the compare output.

### Cache invalidation visibility

After file pushes that affect modules, PW's module cache must be refreshed before the new state is visible. Today this happens implicitly inside some operations and not others, with no observability. `?reset=1` admin pings return 200 but require an authenticated session to actually do anything; without one they're silently no-ops.

Proposed: a `pw_cache_clear` MCP tool that hits an authenticated CommandRouter endpoint and returns confirmation that the clear actually happened. Stop relying on `?reset=1` pings, which are an admin UI primitive misused as an API.

### Sensible compare → sync feedback loop

The current compare returns counts (e.g. "123 modified pages") but no ordered list of what would be pushed. During the deployment we never knew which 123 pages would be touched and didn't push pages at all because of that uncertainty. Result: at session end, **production still has 123 stale pages and 43 missing pages** because the operator (me) couldn't confidently scope a page push.

A `pw_site_compare` that returns:
- A grouped, ordered list of changes (by template, by section)
- Per-page "what would change" summary (which fields differ)
- A `pushPlan` array that can be passed back to `pw_site_sync` to push exactly those items and no others

…would convert "I'm not sure if pushing pages will surprise me" into "I can see exactly what pushes, opt in per-page if I want, and verify after."

### Diagnostic / observability improvements

The biggest unblock during the v1.8.0 deployment came not from new sync automation but from **accurate diagnostics**. `pw_db_query` lies, `pw_health` lies about writes, and there's no way to ask the remote "what modules are actually installed?" or "what fields are on this template right now?". A small set of read-only inspection tools would prevent most "I thought X was Y" misdiagnoses:

- **`pw_modules_list`** — class, isInstalled, fileExists, file path, version, install error if any. For all installed modules or a specified list. (We built `list-modules` as a one-off.)
- **`pw_inspect_template`** — fields currently on a template's fieldgroup, family settings, access rules. (We built `inspect-template` as a one-off.)
- **`pw_users_list`** — name, email, roles, member_* fields. (We built `list-users` as a one-off.)
- **`pw_resolve`** — bulk name → ID resolver for fields, roles, templates, pages on a given target. The pattern recurred constantly during ID translation; a single tool would replace half a dozen ad-hoc queries.

These are cheap to add, hard to misuse, and would have eliminated the need for a custom diagnostic endpoint in the deployment session.

### Pull a single page from remote into local

`pw_page_pull` and `pw_pages_pull` always read from the **local** ProcessWire (the one the MCP server's PHP CLI is connected to). There is no `--site=remote` (or `source: "remote"`) option. The asymmetry is glaring once you notice it: `pw_page_push` accepts `targets: "local" | "remote" | "both"` but `pw_page_pull` is local-only.

Real-world scenario hit during the v1.8.0 deployment session: an editor changed a TinyMCE body field on production (removed three inline images). To get that change reflected in the local MAMP DB and the local YAML, the workflow had to be:

1. Spin up a temporary remote read endpoint in `_init.php` that exposes `$page->getUnformatted($field)`.
2. Push the modified `_init.php` to remote via `pw_site_sync scope=files`.
3. `curl` the field value from prod into a local file.
4. Overwrite `site/assets/pw-mcp/<page>/fields/body.html` with the fetched content.
5. `pw_page_push targets: "local"` to write the change to local MAMP DB.
6. Restore `_init.php`, push files again to clean up the endpoint.

This is **6 manual steps and a custom diagnostic endpoint** for what should be one tool call. Proposed:

- **`pw_page_pull source: "remote"`** — fetch the page from production over HTTP, rewrite the local YAML + field files, optionally also write to local MAMP DB. Mirror the existing `targets` semantics on push.
- Field-level variant: `pw_page_field_pull pageIdOrPath:1192 field:body source:remote` — for cases where you want only one field updated, leaving everything else local.
- Should respect the same authenticated POST endpoint pattern as the rest of PromptWire — no token-in-querystring workaround.

This is the inverse of the push pipeline that already exists, so most of the plumbing (HTTP API auth, page-by-path resolution on remote, YAML serialiser) is already in place.

### Process gaps / first-time deployment friction

These are operator-experience issues that hit the very first production deployment. None are fundamental architectural problems, but they're the kind of thing that makes the difference between "I can deploy in 5 minutes" and "I spent 4 hours figuring out why nothing worked."

- **Write-protection default.** First-time deployment required disabling write protection somewhere before sync would actually write anything. The error path was unclear; happy path needs a clear bootstrap section in the README.
- **`pw_logs` returns the index but not the content.** Asking for a specific log by `name:` returned the catalogue of available logs (with sizes/dates) but not the actual log entries. Workaround required FTP/SSH to the server to read logs directly.
- **No `pw_cache_clear`.** After file pushes affecting modules, the module cache must be refreshed for changes to be visible to subsequent inspect calls. We worked around this by adding `refresh-modules` to a custom diagnostic endpoint.
- **Repeated unauthenticated requests trip hosting firewalls.** Plesk's Fail2Ban blocked our public IP after a flurry of `?_pkdops=TOKEN` requests during diagnostics. A native MCP tool calling its own POST-only authenticated endpoint wouldn't trip this — only the workaround pattern of GET requests with a token query string did. Future diagnostic tooling should use the same authenticated POST endpoint as the rest of PromptWire.

### Out of scope for this round

- **Bidirectional user sync** — too easy to create duplicates or roll back roles. Only local→remote, additive.
- **Module uninstall on remote.** Easy to add when needed; deferred until a real use case appears.
- **Module dependency resolution.** If module A requires module B and B isn't installed, install fails; we report and let the user decide. No auto-install of dependencies.
- **Pulling `forms_entries` for analysis.** Allowed by the registry, but a dedicated `pw_entries_pull` tool with date/form filters can wait for a v1.8.x point release if useful.

---

## v1.7.0 — Site Sync, Backup & Maintenance Mode

A full-site comparison and deployment workflow for keeping local development and remote production in sync, without overwriting production-only data (user accounts, license pages, purchase records).

### The problem

Local dev and remote production drift apart over time. The old solution — clone the entire database with Duplicator — is no longer viable when production has data that must not be overwritten: user accounts, Paddle-generated license pages, purchase records. Page IDs differ across environments. A selective, intelligent sync is needed.

### Design principles

1. **Compare first, act second.** Every sync starts with a read-only comparison. Nothing is written until you've reviewed the report and explicitly confirmed.
2. **Path-based, not ID-based.** Pages are matched by URL path, not page ID. This is consistent with how `pw_page_push` already works.
3. **Exclude by template.** Production-only data (users, licenses, orders) is filtered out by template name using a configurable exclusion list. Exclusions can use wildcards (e.g. `license_*`).
4. **Backup before you break.** Before any sync writes, a snapshot of what's about to be overwritten is captured on the target. Rollback is always possible.
5. **Maintenance mode is optional but integrated.** If enabled, the remote site shows a maintenance page to visitors during sync and is automatically restored when the operation completes (or left on if something fails, so you can investigate).

### Workflow

The intended usage:

```
1. "Compare my local site against production, excluding user, role, and permission templates."
   → Read-only report: pages differ, schema drift, file changes

2. "Sync everything to production. Back up first and enable maintenance mode."
   → Backup remote state
   → Maintenance mode on
   → Push schema, pages, files
   → Verify
   → Maintenance mode off
   → Summary report
```

### New PHP commands (CommandRouter)

These run server-side inside ProcessWire, callable via both the local CLI and the remote HTTP API.

#### `maintenance:on` / `maintenance:off` / `maintenance:status`

Toggle a maintenance flag. Implementation: write/delete `site/assets/cache/maintenance.flag`. The PromptWire module hooks `Page::render` at `before` priority and serves a configurable maintenance page to all non-superuser requests when the flag is present.

Critical constraint: the PromptWire API endpoint must still respond during maintenance mode. The hook must exclude the API endpoint URL from the maintenance check, or use a middleware approach that only affects front-end rendering.

The maintenance page can be:
- A static HTML file shipped with the module (`maintenance.html`)
- A custom ProcessWire template page if the user has set one up
- A simple "Site is undergoing maintenance, back shortly" default

#### `site:inventory`

Returns a compact manifest of every page on the site:

```json
{
  "pages": [
    {
      "id": 1001,
      "path": "/about/",
      "template": "pages_about",
      "modified": "2026-04-21T15:30:00Z",
      "contentHash": "a3f8c9..."
    }
  ],
  "generatedAt": "2026-04-21T22:00:00Z"
}
```

The `contentHash` is an MD5 of the page's serialised field values (same algorithm `pw_page_pull` uses for `revisionHash`). This allows comparison without transferring full content: if hashes match, the page is identical.

Parameters:
- `excludeTemplates` — comma-separated template names or wildcards to omit from the inventory
- `includeSystemPages` — boolean, default false (excludes `admin`, `trash`, system pages)

#### `files:inventory`

Returns a manifest of files in specified directories:

```json
{
  "files": [
    {
      "relativePath": "site/templates/home.php",
      "size": 2048,
      "md5": "b7e4d1...",
      "modified": "2026-04-15T10:00:00Z"
    }
  ]
}
```

Parameters:
- `directories` — array of directory paths relative to PW root, default `["site/templates", "site/modules"]`
- `extensions` — file extensions to include, default `["php", "js", "css", "json", "latte", "twig"]`
- `excludePatterns` — array of glob patterns to skip (e.g. `["site/modules/PromptWire/**", "*.bak"]`)

#### `files:push`

Accepts one or more files as base64-encoded content and writes them to the specified paths on the target. Validates that paths are within allowed directories (site/templates, site/modules, site/init.php, etc.). Dry-run by default.

Before writing, creates a backup of each file being overwritten at `site/assets/pw-mcp/.backups/{timestamp}/{relative-path}`.

#### `backup:create`

Creates a targeted backup of what's about to be changed:
- SQL dump of specified tables (or all non-excluded page tables) using ProcessWire's `$database->exec()`
- File copies of templates/modules being replaced
- Stored in `site/assets/pw-mcp/.backups/{timestamp}/`

Parameters:
- `scope` — `"full"`, `"pages"`, `"schema"`, `"files"`, or `"auto"` (determined by what the subsequent sync will touch)
- `excludeTemplates` — same exclusion list as site:inventory
- `label` — optional human label for the backup

Returns:
- Backup path
- Size
- Contents summary (N pages, N files, N tables)

#### `backup:list` / `backup:restore`

`backup:list` shows available backups with timestamp, label, size, and scope.

`backup:restore` applies a backup. Schema and pages are restored through the existing ProcessWire API (not raw SQL), so hooks and caches are handled correctly. Files are copied back from the backup directory. Dry-run by default.

### New MCP tools (TypeScript)

#### `pw_site_compare`

The primary comparison tool. Fetches inventories from both local and remote, diffs them across three dimensions.

Parameters:
- `excludeTemplates` — array of template names/wildcards to ignore (default: `["user", "role", "permission", "admin"]`)
- `excludePages` — array of specific page paths to ignore
- `includeDirs` — directories to compare for file sync (default: `["site/templates", "site/modules"]`)
- `excludeFilePatterns` — glob patterns to skip in file comparison

Returns a structured report:

```
Schema
  Fields:     2 only-local, 0 only-remote, 3 differ
  Templates:  1 only-local, 0 only-remote, 1 differs

Pages (542 compared, 63 excluded)
  Identical:  520
  Modified:   14  (local newer: 12, remote newer: 2)
  Local only: 8   (new pages not yet on production)
  Remote only: 0  (after exclusions)

Files (site/templates: 24 files, site/modules: 180 files)
  Identical:  196
  Modified:   6
  Local only: 2
  Remote only: 0
```

Each section is expandable for details (which fields differ, which pages changed, which files differ).

#### `pw_site_sync`

Executes a sync based on comparison results.

Parameters:
- `direction` — `"local-to-remote"`, `"remote-to-local"`, or `"bidirectional"`
- `scope` — `"all"`, `"pages"`, `"schema"`, `"files"`, or an explicit list of items
- `excludeTemplates` — same as compare
- `backup` — boolean, default true. Creates a backup on the target before writing
- `maintenance` — boolean, default false. Enables maintenance mode on the target for the duration
- `dryRun` — boolean, default true
- `conflictStrategy` — `"skip"`, `"local-wins"`, `"remote-wins"`, `"ask"` (for bidirectional)

The execution order:
1. Run `pw_site_compare` internally to get the current diff
2. If `backup: true`, create a backup on the target via `backup:create`
3. If `maintenance: true`, enable maintenance mode on the target
4. Push schema changes (fields first, then templates)
5. Push page content (parents before children for new pages)
6. Push file changes
7. Run a post-sync comparison to verify
8. If `maintenance: true` and sync succeeded, disable maintenance mode
9. Return a summary report

If any step fails:
- Maintenance mode stays ON (deliberate — don't expose a half-synced site)
- The error is reported with the backup path so the user can restore or fix manually
- Remaining steps are skipped

#### `pw_maintenance`

Simple maintenance mode control.

Parameters:
- `action` — `"on"`, `"off"`, `"status"`
- `message` — optional custom maintenance message (for `"on"`)
- `targets` — `"local"`, `"remote"`, `"both"`

#### `pw_backup`

Standalone backup management.

Parameters:
- `action` — `"create"`, `"list"`, `"restore"`
- `scope` — for create: `"full"`, `"pages"`, `"schema"`, `"files"`
- `backupId` — for restore: the timestamp/ID of a backup to restore
- `excludeTemplates` — templates to exclude from backup
- `targets` — `"local"`, `"remote"`
- `dryRun` — boolean, default true (for restore)

### Default template exclusions

Out of the box, the following templates are excluded from comparison and sync:

**Always excluded (ProcessWire system):**
- `admin`, `user`, `role`, `permission`

**Suggested exclusions for sites with commerce/accounts (user-configurable):**
- `license_*` (Paddle license pages)
- Account templates if they contain user-generated data

These defaults live in a config file (`.pw-sync/sync-config.json`) so they persist between sessions:

```json
{
  "excludeTemplates": ["user", "role", "permission", "admin"],
  "excludePages": ["/trash/"],
  "includeDirs": ["site/templates", "site/modules"],
  "excludeFilePatterns": ["site/modules/PromptWire/**", "*.bak"],
  "defaultBackup": true,
  "defaultMaintenance": false
}
```

### Maintenance mode implementation detail

The maintenance hook is installed by PromptWire's `init()` method:

```php
public function init() {
    $flagFile = $this->wire('config')->paths->assets . 'cache/maintenance.flag';
    if (file_exists($flagFile) && !$this->wire('user')->isSuperuser()) {
        // Don't block the API endpoint
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, 'promptwire') === false) {
            header('HTTP/1.1 503 Service Temporarily Unavailable');
            header('Retry-After: 300');
            include __DIR__ . '/maintenance.html';
            exit;
        }
    }
}
```

The flag file approach is simple and reliable: no database dependency, no cache dependency, works even if ProcessWire is partially broken. The API endpoint URL check uses a substring match rather than the exact filename, since the user may have renamed it.

### Implementation phases

**Phase 1 — Compare & Inventory (no writes)**
- PHP: `site:inventory`, `files:inventory`
- TypeScript: `pw_site_compare`
- This is immediately useful even without sync — just knowing what's drifted is valuable

**Phase 2 — Maintenance mode**
- PHP: `maintenance:on/off/status`, hook in `init()`
- TypeScript: `pw_maintenance`
- Ship `maintenance.html` default page with module

**Phase 3 — Backup**
- PHP: `backup:create`, `backup:list`, `backup:restore`
- TypeScript: `pw_backup`
- Backup directory structure and cleanup

**Phase 4 — Sync**
- PHP: `files:push` (pages already use existing push infrastructure)
- TypeScript: `pw_site_sync`
- Sync config file (`.pw-sync/sync-config.json`)
- Orchestration: backup → maintenance → sync → verify → maintenance off

### What this replaces

This feature replaces the "clone the whole database" workflow for keeping dev and production in sync. Database backup and restore modules remain the right tools for full-site cloning (new server provisioning, disaster recovery from bare metal). This feature is for incremental, selective, ongoing sync during active development.

### Open questions

1. **Bidirectional conflict resolution UI.** When both local and remote have changed the same page, the tool needs a way to present the conflict and let the user choose. For MCP this is tricky — the agent would need to show a diff and ask. The simplest v1 approach: skip conflicts and report them, let the user resolve manually with `pw_page_pull` / `pw_page_push`.

2. **Large file transfers.** Template PHP files are small. But `site/modules/` can contain large directories (e.g. MediaHub with its assets). We may need chunked transfer or a size limit with a "use SFTP for these" fallback message.

3. **Database table sync.** Some modules create custom tables outside ProcessWire's page system. v1 of this feature ignores them. If needed later, `backup:create` could optionally dump specific tables, and a `db:push` command could apply them.

4. **Concurrent access during sync.** Maintenance mode prevents front-end visitors from seeing a half-synced site, but it doesn't lock the PW admin. A superuser could edit a page mid-sync. The backup provides a safety net, but a "sync lock" flag that warns admin users would be a nice addition in a future version.

---

## High leverage

### 1. `pw_pages_push` — support pushing local scaffolds to a remote target

**Today:** `pw_pages_push` walks the *remote* filesystem (under `site/assets/pw-mcp/`) and creates / updates pages from whatever sync directories exist there. That works for environments where you've already shipped the files via SFTP / git, but it's the wrong primitive when you want to deploy a batch of brand-new pages from a local working copy to production.

**Symptom in the wild:** dry-running `pw_pages_push` against a remote target with `new: true` scaffolds in your local `site/assets/pw-mcp/` returns *"Directory not found: …/site/assets/pw-mcp/<branch>"* because the remote has no copy of those files. The current workaround is to fall back to `pw_page_publish` and publish each new page individually.

**Proposed approach:**
- Add `source: "local" | "remote"` (default `"remote"` for back-compat).
- When `source: "local"`, read the scaffolds from the local sync dir, POST each `page.meta.json` + `page.yaml` to the remote API the same way `pw_page_publish` does, and aggregate the results.
- Honour `dryRun`, `parentPath` filtering, and `new: true` / `pageId` semantics consistently across both modes.

### 2. `pw_schema_push` — apply nested Fieldtype settings

**Today:** `pw_schema_push` reliably creates fields and templates and updates top-level field settings (label, description, tags, columnWidth, etc.). Some Fieldtype-specific *nested* settings — most visibly `FieldtypePage` `parent_id` / `template_id` selectors, which live on the field's "Input" tab in admin — don't always make it across, even when present in the local `fields.json` dump. The field appears to push successfully, but `pw_schema_compare` immediately afterwards flags the same settings as still differing.

**Proposed approach:**
- Audit which Fieldtype `___getConfigInputfields()` outputs are actually round-tripped through `pw_schema_pull` → `pw_schema_push`. `FieldtypePage`, `FieldtypeOptions`, `FieldtypeRepeater`, `FieldtypePageTable`, `FieldtypeFile/Image` are the obvious suspects.
- For each, ensure the dump captures the canonical setting names (e.g. `parent_id`, `template_id`, `derefAsPage`, `inputfield`) and that the push assigns them via `$field->set()` *before* `$fields->save($field)`.
- Add a regression fixture under `examples/` so this doesn't quietly regress.

### 3. System templates — explicit opt-in for `user` / `role` / `admin`

**Today:** `pw_schema_push` silently skips changes to ProcessWire system templates. That's a sensible default — accidentally clobbering the `user` template is a footgun — but it means legitimate, intentional changes (adding `member_*` fields to the `user` template, for example) have to be done by hand in admin, which defeats the schema-as-code workflow.

**Proposed approach:**
- Add `--includeSystemTemplates=true` (CLI) / `includeSystemTemplates: true` (MCP arg).
- When set, allow additive changes to system templates: adding new fields to the fieldgroup, updating non-structural settings.
- Continue to refuse destructive changes (removing system fields, changing the template flag) unless an even stronger flag is passed.
- Make the dry-run output clearly show "skipped (system template, opt-in required)" so it's discoverable.

## Medium leverage

### 4. `pw_page_delete` — close the page lifecycle

**Today:** the page lifecycle is `init` → `new` → `publish` / `push` → `pull` → … and then a manual trip to admin to delete. For automated sync workflows (especially "rename a section" or "retire a feature branch") this is the missing primitive.

**Proposed approach:**
- `pw_page_delete` taking `idOrPath`, `recursive: bool`, `trash: bool` (default `true` — soft delete to PW trash).
- Refuse to delete `home`, `admin`, and any page with `status & Page::statusSystem`.
- Honour the same hybrid local-first routing rules as the other write tools.

### 5. `health` / `writesEnabled` — make the flag mean something

**Today:** `CommandRouter::health()` returns `writesEnabled: false` as a hardcoded value. It's a leftover from early development and doesn't actually gate any write operations — `pw_schema_push`, `pw_page_publish`, `pw_pages_push`, and `pw_file_sync` all work regardless of what `health` reports. That's confusing during incident response: an operator sees `writesEnabled: false` and reasonably assumes writes are blocked, when in fact they're only gated by API key + (optional) IP allowlist.

**Proposed approach:** pick one:
- **Remove the field** from the health response — the actual write gates (key, IP allowlist, module config) are already discoverable.
- **Or wire it to a real toggle** — e.g. a `PROMPTWIRE_READ_ONLY` constant in `config-promptwire.php` that the API endpoint and CLI both honour, returning `403` for any write command when set. Belt-and-braces for staging-clones-of-production scenarios.

**Note:** the v1.7.0 maintenance mode feature (above) partially addresses this. Maintenance mode blocks front-end visitors but does not block PromptWire API writes. A separate `PROMPTWIRE_READ_ONLY` toggle would block writes through the API itself, which is a different concern (e.g. a staging site you want to inspect but never modify).

### 6. Tool argument naming — pass a consistency lint

**Today:** several adjacent tools take subtly different argument names for the same concept:
- `pw_get_template` and `pw_get_field` take `name`.
- `pw_get_page` takes `idOrPath`.
- Some tools accept `path`, others `pagePath`, others `canonicalPath`.

It's a small papercut but it makes the agent (and humans) guess wrong on first call.

**Proposed approach:**
- Pick one canonical set: `name` for schema objects, `idOrPath` for pages, `path` only when it's a filesystem path.
- Accept the old names as aliases for one minor version; emit a deprecation hint in the response.

### 7. Hybrid MCP routing — make the destination explicit in responses

**Today:** with `PW_PATH` *and* `PW_REMOTE_URL` both set, reads and `pw_schema_push` go local; file sync, page publish, and explicit remote tools go remote. The rules are documented but the response payload doesn't say which target was hit. During the v1.4.0 deployment work this caused at least one false-positive dry run (push reported "no changes" because it was inspecting local, not remote).

**Proposed approach:**
- Add `target: "local" | "remote"` to every tool response, sourced from the same routing logic that picked the executor.
- Surface it in the MCP tool result so the agent can sanity-check before applying.

## Hardening

### 8. Configurable endpoint filename — stop broadcasting a known URL

**Today:** the README and example `mcp.json` snippets all use `promptwire-api.php` at the PW site root. That's a fixed, well-known filename — anyone scanning the web for `/promptwire-api.php` can enumerate every site running PromptWire. They still need a valid API key to do anything (and the optional IP allowlist closes the door further), but a published URL pattern is one too many breadcrumbs for a security tool.

**Proposed approach:**
- The endpoint file already has no path-dependent logic — it works under any filename. The change is mostly documentation and tooling:
  - **README:** lead with "rename this file to a non-obvious name of your choice" rather than reusing the default. Keep `promptwire-api.php` as the example, not the recommendation.
  - **Installer / CLI helper:** offer to scaffold the endpoint with a random suffix, e.g. `promptwire-api-7f3k9.php`, and write the matching `PW_REMOTE_URL` to a clipboard-ready snippet for the user's `mcp.json`.
  - **Treat the filename as a low-entropy secret** — document that, like the API key, it should not appear in any public repo or chat log. Update `.gitignore` examples accordingly (current entries already cover the common defaults; add a wildcard suggestion like `/promptwire-api-*.php`).
  - **Operational guidance:** to rotate, drop in the new file, update `mcp.json`, delete the old file. No data migration.

### 9. Configurable config-file path — let users move the API key out of webroot

**Today:** the endpoint hardcodes `__DIR__ . '/site/config-promptwire.php'` for the API key location. That's protected by PW's root `.htaccess` from direct HTTP access, but:
- A misconfigured server (nginx without equivalent rules; Apache with `AllowOverride None`) silently exposes the file. Even though PHP would *execute* rather than serve the source, the failure mode is invisible until something else surfaces it.
- The filename is predictable, which means any path-traversal or arbitrary-file-read CVE in another module could deterministically reach the API key.
- Some hosts (managed PHP, container platforms) prefer secrets to live entirely outside the document root and be injected via env vars or process-manager config.

**Proposed approach:**
- Honour, in this order:
  1. `PROMPTWIRE_CONFIG_PATH` environment variable (absolute path, can point anywhere readable by the PHP process — including outside webroot).
  2. `PROMPTWIRE_API_KEY` environment variable directly (already partially supported via the `getenv()` fallback — promote it from "fallback" to "first-class").
  3. The current `site/config-promptwire.php` default, for back-compat.
- README: add a "Hosting the API key safely" subsection covering:
  - **Best:** env var injected by the process manager (php-fpm pool, systemd `EnvironmentFile=`, container secret).
  - **Good:** config file in a path *above* the webroot (`/var/www/secrets/promptwire.php`) referenced via `PROMPTWIRE_CONFIG_PATH`.
  - **Default:** `site/config-promptwire.php` — fine if `.htaccess` is enforced and the file is gitignored, which is the case today.
- Drop a clearer error message when the key isn't configured, listing all three locations checked.

### 10. `.htaccess` hardening — ship a defensive default for the endpoint

**Today:** the endpoint relies on PW's root `.htaccess` plus its own POST-only / API-key-only logic. There's no ship-with-the-module web-server config telling Apache or nginx anything about it.

**Proposed approach:**
- Ship an optional `api/.htaccess.example` snippet covering:
  - `Order deny,allow` style IP allowlist (mirrors `PROMPTWIRE_ALLOWED_IPS` at the web-server layer, so requests don't even reach PHP).
  - Deny `GET`/`HEAD`/`OPTIONS` at the server level (defence-in-depth on top of the PHP-level `405`).
  - Force HTTPS (`RewriteCond %{HTTPS} off`).
- Ship an equivalent `nginx.example.conf` snippet for nginx-fronted hosts, since the `.htaccess` protections don't apply there.
- README: add a one-paragraph "Web-server hardening" section linking to both.

## Lower leverage / polish

- **Schema dump hygiene:** automatically prune `.bak` files older than N days, or write them to a `schema/.bak/` subdirectory to keep the top of `.pw-sync/schema/` tidy.
- **`pw_schema_compare` exit semantics:** in CLI mode, exit non-zero when drift is found, so it can be wired into CI as a "schema drift" check.
- **Per-environment named site configs:** `pw_*` tools could accept `--site=<name>` resolving to `.pw-sync/sites/<name>.json` for any remote-targeted command, not just `schema_compare`. Removes the need to swap MCP entries for one-off prod queries.
- **`pw_page_publish` batch mode:** accept an array of paths and publish in dependency order (parents before children) in one MCP call. Reduces the chatter when scaffolding a whole section.
- **Structured error codes:** today errors are free-form strings. A small enum (`E_REMOTE_DIR_MISSING`, `E_SYSTEM_TEMPLATE_SKIPPED`, `E_FIELD_SETTINGS_DRIFT`, …) would let the agent recover automatically instead of re-asking the user.
- **`examples/`** — add a worked end-to-end example covering "ship new schema + new section to production without overwriting live data", since that's the workflow most production users will reach for.

## Out of scope (for now)

- A first-class migration tool. Schema-as-code with `pw_schema_compare` + `pw_schema_push` already covers most of what we'd want; a heavier migration framework would duplicate that surface area without much gain. Revisit if Fieldtype-nested-settings (#2) prove intractable to round-trip cleanly.
- Multi-site-per-instance support. PW multi-instance is rare enough that adding it as a first-class concept across every tool would balloon the API. Keep one-site-per-MCP-server as the model.
