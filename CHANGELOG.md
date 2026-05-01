# Changelog

## 1.11.0 (1 May 2026)

- **New:** `pw_template_fields_push` MCP tool (+ `template:fields-push` CLI command). First tool in the fieldgroup-edit family. Adds, removes, and reorders fields on a template's fieldgroup, and sets per-fieldgroup context overrides (label override, description, required, columnWidth, showIf, requiredIf, collapsed; FieldtypePage-only: template_id, parent_id, findPagesSelector, inputfield). Explicitly does NOT modify field definitions — fieldtype changes, inputfield class, parent pickers, etc. are pw_field_push (v1.12+) territory. `add` accepts mixed strings and `{name, context}` objects in the same array; `remove` and `reorder` are string-only. Default `dryRun: true`.
- **New:** Conflict classifier with three severity tiers — `safe`, `warning`, `danger`. Dangers block writes unless `force: true`. Classifier rules:
  - Core-flag protection: `flagSystem` (8) / `flagPermanent` (512) removals → danger (scope: `core-flag`), regardless of template. Catches `title`, `pass`, `roles`, `email`, `language`, etc.
  - Template-level module ownership: editing a template whose name matches `form-*` / `form_*` (FormBuilder), `repeater_*` (PW Repeater/RepeaterMatrix storage), `admin`/`user`/`role`/`permission`/`language` (PW core), `mediahub_*` / `media_hub_*` (MediaHub), or `protable_*`/`profields_*` (ProFields) fires a single template-ownership danger (scope: `module-ownership`) for any write. Addresses the silent-breakage risk of accidentally modifying FormBuilder submissions, PW auth, repeater storage, etc.
  - Field-name prefix warnings: removing `form_`, `formrpro_`, `seoneo_`, `mediahub_` / `media_hub_`, `padloper_`, `login_`, `register_`, `comment*`, `process_` prefixed fields on a non-module-owned template fires a warning (not blocker). Operator can proceed with a legit cleanup workflow (e.g. swapping SEO modules) but gets told which module the field convention matches.
  - Required-field removal: context-aware (per-fieldgroup) required detection via `Fieldgroup::getFieldContextArray`. Removing a field required on this template → danger.
  - Fieldset pair integrity: `FieldsetOpen` / `FieldsetTabOpen` / `FieldsetGroup` without its matching `{name}_END` close, or close before opener in reorder, or opener without close → danger. Runs over the projected post-push fieldgroup.
  - Definition-level completeness warnings for added fields: Page reference with no selectable-pages constraint (template_id/parent_id/findPagesSelector all empty); Page reference with no `inputfield`; Textarea with no `inputfieldClass`; File/Image/CroppableImage3 with no `extensions`; Repeater/RepeaterMatrix with no `template_id`/`parent_id`. Warnings, not blockers — the add can proceed; operator gets advance notice of what editors will see.
  - Context validation: unknown context keys → warning; FieldtypePage-only keys on non-Page fields → danger.
- **New:** Cross-site fieldtype-drift detection when `site: 'both'`. MCP-layer post-pass compares the two plans' projected fieldgroups name-by-name; any field that exists on both sides with a different `FieldtypeX` class is emitted as a `schema-drift` danger in a new top-level `crossSite` block alongside the per-side results. Covers the canonical `blog_post.images` case (local: `FieldtypeCroppableImage3` vs remote: `FieldtypeImage`) that's been open since Session 2. Graceful on asymmetric rollouts — when remote runs an older PromptWire without `template:fields-push`, the remote plan is simply absent and drift returns an empty list (no false positives).
- **Write-path reorder uses `WireArray::insertBefore` in place** rather than the naive remove-all-and-readd approach. Required because PW refuses to remove `flagGlobal` (4) fields from any fieldgroup — `title` on most content templates is the trip-wire. Iterating `reverse(reorder)` and inserting each entry before the current first field matches the caller's spec without ever calling `remove()` on a global field.
- **Audit trail on writes:** every response includes `{ removed, added, contextSet, reordered, saves }`. Populated even when a later step throws, so partial-applied writes are visible. Saves are batched per phase (remove+add → save; context → save; reorder → save) to minimise race windows.
- **Response shape:** dry-run returns `{ currentFieldgroup, plannedFieldgroup, conflicts, conflictsSummary, dryRun: true, applied: false }`; write returns the same plus `beforeFieldgroup`, `afterFieldgroup` (re-snapshotted from the live fieldgroup), `audit`, `applied: true`. `site: 'both'` wraps as `{ local, remote, crossSite }`.
- **Migration impact: zero.** Strictly additive across CLI, MCP, and response shapes. No existing tool's signature or output changes. `templateFieldsPushInferTemplateModule` / `templateFieldsPushInferFieldModule` helpers are private to `CommandRouter`; their pattern lists are a starting set that can be extended without touching the classifier contract.
- **Module + MCP server version bumped to 1.11.0.**

## 1.10.2 (1 May 2026)

- **New:** `idDriftPages` array in `pw_page_assets compare` and `pw_site_compare`'s `pageAssets` section. Enumerates every page that has divergent local/remote ids alongside the existing `pagesWithIdDrift` counter. Previously, drifted pages with identical assets never appeared in `diffs` (early-continue path), so the counter was the only signal they existed. Now the operator can see which specific pages are drifting and decide whether the divergence is benign (organic id-sequence drift between independent fresh installs) or a correctness issue (same path resolving to two unrelated pages).
- **New:** `scripts/push-module-to-remote.mjs` — generalised companion to `push-self-to-remote.mjs` for pushing any third-party module to a PromptWire-equipped remote. Takes `--source=<path>` and `--prefix=<site/modules/NAME/>` args, plus an `--exclude=<dir1,dir2>` flag for skipping internal docs/PRDs/test fixtures. First real-world use was aligning MediaHub 1.15.3 → 1.15.4 on peterknight.digital (97 files, clean two-batch push).
- **Migration impact: zero.** `idDriftPages` is strictly additive on `SiteAssetCompareResult`. The new script is additive in `scripts/`, excluded from production pushes by the existing `SKIP_DIRS` rule in both deploy scripts.
- **Module + MCP server version bumped to 1.10.2.** No PHP runtime changes.

## 1.10.1 (30 April 2026)

- **New `ids` block in `page.meta.json`.** Per-environment `pageId` record alongside the existing top-level `pageId` (kept as a back-compat mirror): `ids: { local: { id, lastSeenAt }, remote: { id, lastSeenAt } }`. Each pull populates only its own slot; the other side's slot is preserved verbatim. Closes the v1.10.0 gap where re-pulling from the other environment silently overwrote `meta.pageId` and made `pushPage` address the wrong page.
- **`SyncManager::pushPage` now resolves pages by canonical path with id verification.** `$wire->pages->get($meta['canonicalPath'])` is the primary lookup; the legacy `pageId` is the fallback for older metas without `canonicalPath`. The last-seen `ids.local.id` in the meta is then used as a sanity check — if the path now resolves to a different id (page deleted and recreated, slug rebound, meta from a different site), the push is refused with a structured error (`expectedId`, `currentId`, `canonicalPath`) and a hint to use `force:true` if the operator really means to push to the new id. Same path-first cross-environment safety the page-assets work in v1.10.0 already has, applied uniformly to page content.
- **`getPageSyncStatus` and `sync:reconcile` updated to the same path-first / id-fallback rule** so `pw_sync_status` and `pw_sync_reconcile` give identical results regardless of which side last wrote the meta. `pw_sync_status` results now also include the per-environment `ids` block when present.
- **MCP-side `pullPageFromRemote` merges the remote payload with the existing local meta** instead of overwriting it. Preserves `ids.local`, strips any stray `ids.local` an older payload might include, and promotes legacy top-level `pageId` to `ids.local` for pre-v1.10.1 metas.
- **MCP-side `pushPage` and `publishPage` now record `ids.remote` after a successful live remote operation.** Reads the `pageId` from the API response and writes it into the local meta, best-effort and dry-run-aware. The local meta now learns the remote id from a remote push without needing a follow-up pull.
- **Module + MCP server version bumped to 1.10.1.**
- **Migration impact: zero for callers.** Older metas keep working; the first write on v1.10.1+ adds the `ids` block. Any caller that was passing a misaddressed `meta.pageId` and getting "Page not found" will instead get a successful path-based resolution or a clear id-mismatch diagnostic.

## 1.10.0 (30 April 2026)

- **New:** `pw_page_assets` — sync the on-disk asset directory for a page (`site/assets/files/{pageId}/`) between local and remote. Catches both standard `FieldtypeFile` / `FieldtypeImage` uploads AND module-managed files (notably MediaHub, plus any other custom module that stores files keyed by page id). The previous `pw_file_sync` only iterated a page's fieldgroup, so files placed in the page-asset directory by modules outside the normal field flow were silently missed. Supports both directions (`push` and `pull`); dry-run by default. PW image variations (`name.WxH[-suffix].ext`) are filtered by default because they're regenerated on demand.
- **Page id drift between environments is handled and surfaced.** Pages are matched by canonical PW path; each side resolves its own `pageId` from that path before walking its own `site/assets/files/{pageId}/` directory. Local 1234 and remote 5678 may both legitimately resolve to `/about/` — this is normal for two sites that started from independent fresh installs rather than a database clone. Every `pw_page_assets` and `pw_site_compare` result reports `localPageId`, `remotePageId`, and an `idDrift` boolean so operators can see at a glance which physical disk directory was read or written on each environment, and whether the two sides' page-id sequences have diverged. `pw_site_compare`'s `pageAssets` section also includes a top-level `pagesWithIdDrift` counter — useful as an early warning that the two sites are diverging in ways that affect more than just assets (cross-site Page references, hardcoded page ids in template code, etc.). Numeric `pageRef` arguments are resolved on the LOCAL site first and translated to the remote via path, so a numeric id never accidentally addresses the wrong remote page.
- **`page.meta.json` now embeds a `pageAssets` snapshot at pull time.** Captures the source side's view of `site/assets/files/{pageId}/` — relative path, size, md5 — plus a `directoryHash`, `assetCount`, `totalBytes`, and `capturedAt`. Both `page:pull` (local) and `page:export-yaml` (remote pull payload) include it. Snapshot uses the directory-walking technique introduced for `page-assets:inventory`, so MediaHub-style files are captured the same as standard `FieldtypeFile` / `FieldtypeImage` uploads — a remote pull no longer "forgets" about module-managed assets that were never attached as `Pagefiles`. Subsequent `pw_page_assets push`/`pull` results include a `driftSinceLastPull` block (with `snapshotSide: "local"|"remote"|"unknown"`) that compares the snapshot baseline against the live inventory on the side it represents. Answers "what has changed in production since I last pulled?" without a fresh remote round-trip. Older `page.meta.json` files without the snapshot still work — the drift block is only emitted when a snapshot is present.
- **`page.yaml` gains a human-readable header comment** rendered above the `fields:` block: page title, path, template, status, the pageId on this site, and a one-line asset summary (`N files · M.M MB · site/assets/files/{pageId}/`). Pure ergonomics for operators editing pulled YAML by hand or feeding it to AI agents — comments are stripped by every YAML parser in use (Symfony YAML, the simple in-tree parser, and js-yaml on the MCP side, all verified). `page.json` gets the same identity + asset summary as a top-level `_meta` object; `pushPage` continues to read `content.fields` only, so the new key is strictly additive. The push-back code path also refreshes both the header and the snapshot after a successful push so subsequent drift checks see the current state, not the pre-push baseline.
- **New:** `page-assets:inventory`, `page-assets:download`, `page-assets:upload`, `page-assets:delete` PHP commands (CLI + remote API). Inventory walks `site/assets/files/{pageId}/` directly rather than going through field iteration; download/upload/delete operate on raw filenames within the page directory (with realpath sandboxing). Inventory has a site-wide `--all-pages` mode used by `pw_site_compare` to fetch the page-assets diff for every page in one round-trip.
- **Changed:** `pw_site_compare` now reports a `pageAssets` section alongside pages/schema/files. Per-page summary of `changed` / `localOnly` / `remoteOnly` files. When the remote PromptWire predates v1.10.0 (so `page-assets:inventory` is missing), the section carries a `warning` instead of failing the whole compare.
- **Changed:** `pw_site_sync` now syncs page assets for every page that has on-disk drift, not just pages that happen to have a local sync directory under `site/assets/pw-mcp/`. Replaces the old `page-files` step (which iterated fieldgroups and required a prior `pw_page_pull`) with a directory-walking variant that catches MediaHub-style files. Also closes the remote-to-local gap — that direction was previously a no-op with a "use SFTP" warning; now it pulls each missing/changed file via `page-assets:download`. Orphan deletion is intentionally OFF for site-sync (use `pw_page_assets` directly with `deleteOrphans:true` for that).
- **Module + MCP server version bumped to 1.10.0.**

## 1.9.3 (30 April 2026)

- **Fixed:** `filesInventory` now prunes `.git`, `.svn`, `.hg`, `.cursor`, `.vscode`, `.idea`, `node_modules`, `__pycache__`, `.next`, and `.cache` directories at the iterator level — before descent — so version-control internals and IDE state never appear in `pw_site_sync`, `pw_site_compare`, or `pw_search_files` output, regardless of caller-supplied `excludePatterns`. Discovered when a `pw_site_sync scope:"files"` plan included a Cursor IDE artifact stored inside a checked-out module's `.git/` directory. `vendor/` is intentionally NOT in the prune list — some sites rely on file sync to ship Composer dependencies.
- **Performance:** inventory generation no longer walks every byte of `.git/objects/pack/*` and `node_modules/` trees on its way to filtering them out.
- **Migration impact: zero.** Strictly subtractive on inventory output.

## 1.9.2 (30 April 2026)

- **Fixed:** `scripts/push-self-to-remote.mjs` no longer ships `ROADMAP.md`, `README.md`, `CHANGELOG.md`, or `SESSION-NEXT.md` to production. Most importantly, `SESSION-NEXT.md` is `.gitignore`d precisely because it holds internal infra notes (file paths, MCP server names, next-session implementation plans), but the deploy script ignored `.gitignore` and uploaded it to a public URL. Push payload shrinks from ~556 KB to ~470 KB per release as a side benefit.
- **Operators with existing prod deploys should manually delete `site/modules/PromptWire/SESSION-NEXT.md`** on the remote (via FTP, hosting panel, or SSH) — this patch only prevents future re-uploads, it does not delete files already pushed. The other doc files are intentionally public elsewhere (GitHub) so leaving them on prod is harmless.
- **No code change** to the PHP module or MCP server.

## 1.9.1 (30 April 2026)

- **Fixed:** `pw_modules_list` (without an explicit `classes` filter) returned every entry with `class: null` and `installError: "Unable to locate module"`. Calls *with* an explicit `classes: [...]` filter were unaffected. Root cause was iterating the values of `Modules::getInstalled()` instead of the keys (the keys are the class names; the values are module objects that JSON-encode as `null`). One-line fix.
- **Migration impact: zero.** Read-only tool; no data was affected.

## 1.9.0 (30 April 2026)

- **New:** `pw_modules_list` — list ProcessWire modules with install state, file path, and version. Defaults to every installed module; pass `classes: ["FormBuilder", "SeoNeo"]` to inspect specific module classes (installed or not). Use `site: "both"` to compare local vs remote install state side-by-side.
- **New:** `pw_users_list` — list users with id, name, email, roles, and `member_*` fields. Pass `includeAll: true` to widen to every non-system field. `pass` is always skipped.
- **New:** `pw_resolve` — bulk-resolve names to ProcessWire ids on the chosen site. Types: `field|template|page|role|permission|user|module`. Returns `{ type, mapping: { name: id|null }, count, missing[] }`. Used before a push to translate local field/template names into the equivalent remote ids without one HTTP round-trip per name.
- **New:** `pw_inspect_template` — companion to `pw_get_template` that returns each field as `{ name, type, label }` rather than just a name. Sized for fieldgroup-diff workflows: `site: "both"` shows exactly which fields differ between local and prod before planning a fieldgroup push.
- **Changed:** MCP server version bumped from stale 1.6.0 → 1.9.0 so the version Cursor reports matches `mcp-server/package.json`.
- **Migration impact: zero.** All four tools are new and additive; no existing signatures changed.

## 1.8.4 (30 April 2026)

- **Fixed:** `siteInventory` `contentHash` is now deterministic across environments. After pushing 88 pages from local to production, `pw_site_compare` was still flagging 18 of them as "modified" — but the differences were all in how dates and PageArrays were rendered, not in the actual content. Five normalisations applied before hashing:
  - **Datetime fields** always emitted as ISO 8601 UTC (was: integer epoch OR formatted string depending on each field's `outputFormat` — responsible for ~half the phantom diffs).
  - **PageArray** sorted by path and pipe-joined (was: storage order, which two sites can legitimately differ on).
  - **Pagefiles / Pageimages** sorted by basename (same reasoning).
  - **Field key order** normalised with `ksort` (so two sites whose field positions differ in admin still hash the same content the same).
  - **`modified` and `created` timestamps** emitted as UTC ISO 8601 (so a local box on BST and a production server on UTC stop phantom-flagging every page as modified).
- **Changed:** pages sorted by path in inventory output for deterministic ordering.
- **Migration impact: all sites must upgrade together for hashes to match.** v1.8.3 and earlier produce different `contentHash` values for the same content; v1.8.4 will surface a one-time wave of "modified" pages on the first compare after upgrade. After both ends are on v1.8.4 the phantom diffs disappear and any remaining diffs are real.

## 1.8.3 (30 April 2026)

- **New:** `page:export-yaml` CLI command — returns a self-contained JSON payload (`yaml` + `meta` + page identity) with **no filesystem writes on the exporting side**. Production stays clean, no stray sync directories left behind.
- **Changed:** `pw_page_pull` accepts `source: "local" | "remote"` (default `"local"` — fully backward compatible). `source: "remote"` calls the new endpoint over HTTP and writes the payload into the *local* sync tree at `site/assets/pw-mcp/<canonical-path>/`, mirroring the layout that local `page:pull` produces. Closes the "production-edit drift" gap that previously required a temporary `_init.php` endpoint and a six-step `curl` workaround.
- **Round-trip integrity check:** the MCP server re-hashes the YAML it wrote to disk and compares against the remote `contentHash`. Mismatches are surfaced as a non-fatal warning.
- **Path-based routing**, not ID-based — the local directory is derived from `canonicalPath` so cross-environment pulls land in the right place even when DB IDs differ.
- **Migration impact: zero.** `source` defaults to `"local"`; existing tool calls behave exactly as before.

## 1.8.2 (30 April 2026)

- **Changed:** `pw_pages_push` accepts `targets` and `publish` arguments (defaults preserve v1.7.x behaviour). `targets: "local"` continues to call the PHP CLI's `pages:push` so existing local workflows are byte-identical. `targets: "remote"` and `targets: "both"` walk the tree in TypeScript and call `pushPage()` per page — so every page benefits from path-based lookup and `_pageRef` resolution.
- Pages pushed in **parent-first order** (sorted by canonical PW path) so newly-created parents exist before their children try to attach. Serial, not parallel, to avoid racing on the remote page tree mutex.
- **Aggregated result payload** lists every page with success/failure plus the per-page push result, so when one page fails the rest of the batch is still visible.
- **Pre-flight env check** for `PW_REMOTE_URL` + `PW_REMOTE_KEY` so a missing remote configuration fails before walking the directory rather than per page.
- **Migration impact: zero.** `targets` defaults to `"local"`; existing tool calls go through the unchanged PHP CLI path.

## 1.8.1 (30 April 2026)

- **Fixed:** `pw_db_query --site=remote` no longer silently runs against the local database. Previously the `--site` flag was passed to the local PHP CLI, which ignored it. **This was the single highest-cost bug** of the v1.7.x release line — caused multiple hours of misdiagnosis where production was assumed to be in sync (or modules assumed installed) when neither was true.
- **Changed:** Eight read tools gain a `site` argument (`local | remote | both`, default `local`, fully backward compatible): `pw_health`, `pw_db_schema`, `pw_db_query`, `pw_db_explain`, `pw_db_counts`, `pw_logs`, `pw_last_error`, `pw_clear_cache`. New `runOnSite()` helper in `mcp-server/src/cli/runner.ts` is the single source of truth for site routing.
- **`site: "both"`** returns `{ local, remote }` in parallel for drift inspection (e.g. compare row counts, last error, or whether a query returns the same result on both sides). Each side carries its own success/error so partial results are still rendered when one environment is unreachable.
- **`pw_clear_cache` now works against production** — useful immediately after a file push when ProcessWire's module registry needs a kick.
- **Migration impact: zero.** `site` defaults to `local` everywhere; new behaviour is opt-in.

## 1.8.0 (30 April 2026)

- **Fixed:** `.module` files are now included in `files:inventory` by default. FormBuilder and LoginRegisterPro both ship core files with the bare `.module` extension; previously `pw_site_sync scope: "files"` silently omitted them, requiring the operator to create temporary `.module.php` sibling files before each push. Default `--extensions` is now `php,js,css,json,latte,twig,module`.
- **Fixed:** Symlinked module directories are now followed by default. Symlinked modules (e.g. StemplatesPro / SeoNeo when developed in sibling repos) are walked into, with a `realpath()`-based loop guard so the same physical file is never reported twice. New `--no-follow-symlinks` flag preserves the v1.7.x behaviour for callers that explicitly want it.
- **Changed:** MCP server version bumped from stale 1.6.0 → 1.8.0 to match the ProcessWire module version (the 1.7.0 release missed `mcp-server/package.json`).
- **Migration impact: zero.** No tool signatures changed; defaults expanded what's included in inventories. A re-run of `pw_site_compare` may surface previously hidden file deltas — these are real and were silently absent before.

## 1.7.0 (21 April 2026)

- **New:** `pw_site_compare` — compare local and remote sites across pages, schema, and template/module files. Pages are matched by path, not ID, so comparison works across environments with different auto-increment sequences.
- **New:** `pw_site_sync` — orchestrated deployment. Runs a comparison, optionally backs up the target, enables maintenance mode, pushes schema, pages (with file/image assets), and template/module files, then disables maintenance. Dry-run by default.
- **New:** `pw_maintenance` — toggle maintenance mode on local, remote, or both sites. Front-end visitors see a styled 503 page; superusers and the PromptWire API are unaffected.
- **New:** `pw_backup` — create, list, restore, and delete site backups. Database dumps use ProcessWire's built-in `WireDatabaseBackup`; file backups zip `site/templates` and `site/modules`.
- **Security:** HTTPS is now enforced on the API endpoint. Requests over plain HTTP receive a 403 before the API key is checked. Local development environments can bypass this with `PROMPTWIRE_ALLOW_HTTP` in `config-promptwire.php`.
- **Security:** Backup directories are automatically protected with a `.htaccess` that denies all web access.
- **Changed:** Module is now `autoload` so it can intercept front-end requests during maintenance mode. The overhead is a single `file_exists()` check per page load.

## 1.6.0 (21 April 2026)

- **New:** `pw_db_schema` — inspect database tables. Without arguments, lists all tables with engines, row counts, and sizes. Pass a table name for detailed columns, types, keys, and indexes.
- **New:** `pw_db_query` — execute read-only SELECT queries. Only SELECT, SHOW, and DESCRIBE are allowed; mutations are blocked. A LIMIT is auto-injected if omitted.
- **New:** `pw_db_explain` — run EXPLAIN on a SELECT query for performance analysis.
- **New:** `pw_db_counts` — row counts for core ProcessWire tables and the 20 largest field-data tables.
- **New:** `pw_logs` — list available log files, or read and filter entries from a specific log by level and text pattern.
- **New:** `pw_last_error` — retrieve the most recent error from the error and exception logs.
- **New:** `pw_clear_cache` — clear ProcessWire caches by target (all, modules, templates, compiled, wire-cache).

## 1.5.1 (21 April 2026)

- **Fixed:** `pw_page_push` now auto-creates pages on the remote target when they don't exist yet, falling back from `page:update` to `page:create` transparently. Previously, pushing a locally-created page to remote failed with "Page not found".
- **Fixed:** `pw_page_publish` no longer blocks publishing to a second target after a page has already been created on the first. Each target now handles its own duplicate check independently.
- **Security:** API endpoint file, docs, and examples no longer hardcode the filename `promptwire-api.php`. Users are now encouraged to rename the file to a non-obvious name so the URL isn't guessable from public documentation.

## 1.5.0 (8 April 2026)

- **Changed:** Module renamed from PW-MCP to **PromptWire**. Class names, file names, CLI scripts, API endpoints, and environment variables have all been updated.
- **Changed:** Install directory is now `site/modules/PromptWire/` (was `PwMcp/`).
- **Changed:** On install or upgrade, the module automatically detects and removes old `PwMcp/` and `PwMcpAdmin/` directories.
- **Changed:** Remote API file renamed to `promptwire-api.php`; config file to `config-promptwire.php`.
- **Changed:** Environment variable `PW_MCP_CLI_PATH` renamed to `PROMPTWIRE_CLI_PATH`.
- **Changed:** API key constant renamed from `PW_MCP_API_KEY` to `PROMPTWIRE_API_KEY`.
- **Kept:** Data directories unchanged — `.pw-sync/` for schema and `site/assets/pw-mcp/` for content sync. No migration needed for existing data.

## 1.4.0 (8 April 2026)

- **Changed:** Module restructured so the repo root is the module directory. Clone or download directly into `site/modules/PwMcp/` — everything is in place.
- **Changed:** On install or upgrade, the module automatically detects and removes the old `site/modules/PwMcpAdmin/` directory from pre-1.4.0 installs.
- **Fixed:** `schemaPush()` routed to the remote API when both `PW_PATH` and `PW_REMOTE_URL` were set, so schema pushes silently went to production while reads used the local site. Now applies the same `PW_PATH`-first guard used elsewhere.
- **Fixed:** `validateRefs()` defaulted to validating against the remote site when both env vars were set, inconsistent with the "local wins" rule. Now defaults to local when `PW_PATH` is present.
- **Fixed:** `pushPage()` and `publishPage()` always returned `success: true` even when local or remote sub-operations failed. Failures were only visible in nested results. Top-level `success` now reflects actual outcome.
- **Fixed:** `publishPage()` silently swallowed YAML parse errors and created remote pages with empty fields. Now reports the parse failure instead.
- **Improved:** `schemaPull()` backs up existing `fields.json` and `templates.json` as `.bak` files before overwriting, so a mistaken pull is recoverable.
- **Improved:** Remote API endpoint now sends `X-Robots-Tag: noindex, nofollow` header.

## 1.3.1 (27 March 2026)

- **Fixed:** When both `PW_PATH` and `PW_REMOTE_URL` are set (hybrid local+remote config), `runPwCommand` now prefers the local PHP CLI. Previously `PW_REMOTE_URL` silently hijacked all commands, causing page queries to return stale remote data instead of live local data. Tools that need the remote endpoint (file-sync, pusher, schema-sync) call `runRemoteCommand()` directly and are unaffected.
- **Fixed:** Remote API endpoint now sends `Cache-Control: no-store` and `Pragma: no-cache` headers to prevent proxy or browser caching of API responses.

## 1.3.0 (27 March 2026)

- **New:** `pw_page_init` tool — initialise or repair `page.meta.json` for sync directories where content files were created manually. Links to existing PW pages or scaffolds new ones.
- **Improved:** `pw_page_new` is now idempotent. If the directory exists but `page.meta.json` is missing, it creates only the scaffold files without overwriting existing `page.yaml` or field files.
- **Improved:** `pw_page_publish` auto-generates `page.meta.json` from `page.yaml` + directory structure when the meta file is missing (requires parent template to allow only one child template).
- **Improved:** Error messages in `pw_page_push` and `pw_page_publish` now show relative paths instead of absolute server paths, and include actionable hints (e.g. "use pw_page_init to generate it").

## 1.2.0

- File sync, schema sync, cross-environment page ID resolution, remote API.

## 1.1.0

- Content sync (pull/push), page creation and publishing.

## 1.0.0

- Initial release. Site inspection, page queries, template/field introspection.
