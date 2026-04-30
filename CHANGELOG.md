# Changelog

## 1.10.0 (30 April 2026)

- **New:** `pw_page_assets` — sync the on-disk asset directory for a page (`site/assets/files/{pageId}/`) between local and remote. Catches both standard `FieldtypeFile` / `FieldtypeImage` uploads AND module-managed files (notably MediaHub, plus any other custom module that stores files keyed by page id). The previous `pw_file_sync` only iterated a page's fieldgroup, so files placed in the page-asset directory by modules outside the normal field flow were silently missed. Supports both directions (`push` and `pull`); dry-run by default. PW image variations (`name.WxH[-suffix].ext`) are filtered by default because they're regenerated on demand.
- **New:** `page-assets:inventory`, `page-assets:download`, `page-assets:upload`, `page-assets:delete` PHP commands (CLI + remote API). Inventory walks `site/assets/files/{pageId}/` directly rather than going through field iteration; download/upload/delete operate on raw filenames within the page directory (with realpath sandboxing). Inventory has a site-wide `--all-pages` mode used by `pw_site_compare` to fetch the page-assets diff for every page in one round-trip.
- **Changed:** `pw_site_compare` now reports a `pageAssets` section alongside pages/schema/files. Per-page summary of `changed` / `localOnly` / `remoteOnly` files. When the remote PromptWire predates v1.10.0 (so `page-assets:inventory` is missing), the section carries a `warning` instead of failing the whole compare.
- **Changed:** `pw_site_sync` now syncs page assets for every page that has on-disk drift, not just pages that happen to have a local sync directory under `site/assets/pw-mcp/`. Replaces the old `page-files` step (which iterated fieldgroups and required a prior `pw_page_pull`) with a directory-walking variant that catches MediaHub-style files. Also closes the remote-to-local gap — that direction was previously a no-op with a "use SFTP" warning; now it pulls each missing/changed file via `page-assets:download`. Orphan deletion is intentionally OFF for site-sync (use `pw_page_assets` directly with `deleteOrphans:true` for that).
- **Module + MCP server version bumped to 1.10.0.**

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
