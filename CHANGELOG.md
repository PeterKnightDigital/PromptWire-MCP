# Changelog

## 1.5.1 (21 April 2026)

- **Fixed:** `pw_page_push` now auto-creates pages on the remote target when they don't exist yet, falling back from `page:update` to `page:create` transparently. Previously, pushing a locally-created page to remote failed with "Page not found".
- **Fixed:** `pw_page_publish` no longer blocks publishing to a second target after a page has already been created on the first. Each target now handles its own duplicate check independently.

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
