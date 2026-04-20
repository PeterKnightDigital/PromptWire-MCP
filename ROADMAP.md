# Roadmap

Improvements gathered from real-world deployments. Items are roughly ordered by leverage — i.e. how much friction they remove from the most common "ship schema + new pages from local to production" workflow.

This is a working list, not a release plan. Issues / PRs against any of these are welcome.

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
