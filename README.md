# PW-MCP

ProcessWire ↔ Cursor MCP Bridge for AI-assisted development.

PW-MCP connects your ProcessWire CMS to Cursor IDE via the [Model Context Protocol](https://modelcontextprotocol.io/), giving AI agents direct read/write access to your site's structure, content, and files.

## Architecture

```
Cursor Chat → MCP Server (Node.js) → PHP CLI → ProcessWire
                                   → HTTP API → Remote ProcessWire
```

Three components work together:

- **PwMcp module** (`PwMcp/`) — ProcessWire module with CLI entrypoint and command router
- **MCP server** (`mcp-server/`) — Node.js server that speaks the MCP protocol to Cursor
- **Remote API** (`PwMcp/api/pw-mcp-api.php`) — Optional HTTP endpoint for remote site access

## Setup

### 1. Install the ProcessWire module

Copy or symlink the `PwMcp/` directory into your ProcessWire `site/modules/` folder. Install via **Modules → Refresh → Install**.

### 2. Build the MCP server

```bash
cd mcp-server
npm install
npm run build
```

### 3. Configure Cursor

Add to `.cursor/mcp.json` in your project root:

```json
{
  "mcpServers": {
    "PW-MCP: My Site (Local)": {
      "command": "node",
      "args": ["/path/to/pw-mcp/mcp-server/dist/index.js"],
      "env": {
        "PW_PATH": "/path/to/your/processwire/site",
        "PHP_PATH": "/path/to/php"
      }
    }
  }
}
```

### 4. (Optional) Remote site access

Deploy `PwMcp/api/pw-mcp-api.php` to your remote site root. Create `site/config-pw-mcp.php` with your API key:

```php
<?php
define('PW_MCP_API_KEY', 'your-strong-random-key-here');
// Optional: restrict to your IP
// define('PW_MCP_ALLOWED_IPS', '1.2.3.4');
```

Add a second MCP server entry for the remote site:

```json
{
  "PW-MCP: My Site (Prod)": {
    "command": "node",
    "args": ["/path/to/pw-mcp/mcp-server/dist/index.js"],
    "env": {
      "PW_REMOTE_URL": "https://example.com/pw-mcp-api.php",
      "PW_REMOTE_KEY": "your-strong-random-key-here"
    }
  }
}
```

## Environment variables

| Variable | Required | Description |
|---|---|---|
| `PW_PATH` | For local | Absolute path to ProcessWire installation root |
| `PHP_PATH` | No | Path to PHP binary (defaults to `php`) |
| `PW_MCP_CLI_PATH` | No | Override path to `pw-mcp.php` CLI script |
| `PW_REMOTE_URL` | For remote | Full URL to `pw-mcp-api.php` on the remote site |
| `PW_REMOTE_KEY` | For remote | API key matching the key configured on the remote site |
| `PW_SYNC_DIR` | No | Override path for `.pw-sync` schema directory |

## Tools

### Site inspection

| Tool | Description |
|---|---|
| `pw_health` | Check ProcessWire connection, version, and counts |
| `pw_list_templates` | List all templates |
| `pw_get_template` | Get template details (fields, settings) |
| `pw_list_fields` | List all fields |
| `pw_get_field` | Get field details (type, settings) |
| `pw_get_page` | Get a page by ID or path with full field content |
| `pw_query_pages` | Query pages with ProcessWire selectors |
| `pw_search` | Search page content by keyword |
| `pw_search_files` | Search PHP/template files in the site directory |
| `pw_export_schema` | Export the full site schema (templates + fields) as JSON |

### Content sync

| Tool | Description |
|---|---|
| `pw_page_pull` | Pull a page into a local sync directory as editable YAML |
| `pw_page_push` | Push local YAML changes back to ProcessWire (local, remote, or both) |
| `pw_pages_pull` | Bulk pull pages by selector, parent, or template |
| `pw_pages_push` | Bulk push all changes in a sync directory tree |
| `pw_sync_status` | Check sync status of all pulled pages (clean, dirty, conflict) |
| `pw_sync_reconcile` | Fix path drift, detect orphans, reconcile sync directories |
| `pw_validate_refs` | Validate page references across synced content |

### Page management

| Tool | Description |
|---|---|
| `pw_page_new` | Scaffold a new page locally (creates `page.yaml` + `page.meta.json`). Idempotent — if the directory exists but `page.meta.json` is missing, creates only the missing scaffold files without overwriting existing content. |
| `pw_page_init` | Initialise or repair `page.meta.json` for a sync directory. If the page exists in ProcessWire, links to it (for `pw_page_push`). If not, creates a new-page scaffold (for `pw_page_publish`). |
| `pw_page_publish` | Publish a scaffolded page to ProcessWire (local, remote, or both). Auto-generates `page.meta.json` from `page.yaml` if missing (when the parent allows only one child template). |
| `pw_pages_publish` | Bulk publish all new page scaffolds in a directory |

### File sync

| Tool | Description |
|---|---|
| `pw_file_sync` | Sync file/image field content from local to remote. Compares inventories by MD5 hash and transfers only new or changed files. Dry-run by default. |

### Schema sync

| Tool | Description |
|---|---|
| `pw_schema_pull` | Pull field and template schema from a PW site into local files |
| `pw_schema_push` | Push local schema files to a PW site (creates/updates fields and templates) |
| `pw_schema_diff` | Diff local schema files against the live site |
| `pw_schema_compare` | Compare schemas between two sites (e.g. local vs production) |
| `pw_list_sites` | List configured remote sites from `.pw-sync/sites/` |

### Repeater Matrix

| Tool | Description |
|---|---|
| `pw_matrix_info` | Get matrix field structure (types, fields, labels) |
| `pw_matrix_add` | Add a new item to a repeater matrix field |

## Content sync workflow

PW-MCP uses a Git-like pull/push model for content synchronisation:

```bash
# 1. Pull a page to a local sync directory
pw_page_pull "/about/"
# Creates: site/assets/pw-mcp/about/page.yaml + page.meta.json

# 2. Edit the YAML or field files locally
# (AI agent edits body.html, title, etc.)

# 3. Preview changes (dry run — default)
pw_page_push localPath="site/assets/pw-mcp/about"

# 4. Apply changes
pw_page_push localPath="site/assets/pw-mcp/about" dryRun=false

# 5. Push to remote production site
pw_page_push localPath="site/assets/pw-mcp/about" targets="remote" dryRun=false
```

### File sync workflow

```bash
# 1. Preview what files would be transferred
pw_file_sync localPath="site/assets/pw-mcp/about"

# 2. Transfer files to remote
pw_file_sync localPath="site/assets/pw-mcp/about" dryRun=false

# 3. Also remove remote files that no longer exist locally
pw_file_sync localPath="site/assets/pw-mcp/about" dryRun=false deleteRemoteOrphans=true
```

File sync compares MD5 hashes between local and remote, transferring only new or changed files. ProcessWire image variations (resized versions) are automatically excluded since PW regenerates them on the target site.

## Cross-environment page ID resolution

Page references in YAML files store both the page ID and path:

```yaml
_pageRef: true
id: 1816
path: "/services/web-design/"
_comment: "Web Design @ /services/web-design/"
```

When pushing to a different environment (local → remote), path is resolved first. This means pages can have different IDs between local and production databases and references still resolve correctly, as long as the page paths match.

## Schema sync

PW-MCP can synchronise your field and template definitions between sites:

```bash
# Pull schema from local site
pw_schema_pull

# Compare local vs production
pw_schema_compare source="local" target="production"

# Push schema changes to production (dry run first)
pw_schema_push dryRun=true
pw_schema_push dryRun=false
```

## Security

The remote API endpoint (`pw-mcp-api.php`) provides:

- API key authentication via `X-PW-MCP-Key` header
- Optional IP allowlist for additional protection
- HTTPS strongly recommended (key is sent in header)
- Error details suppressed in production
- Read/write operations mirror ProcessWire's native permission model

## Requirements

- ProcessWire 3.0+
- PHP 8.0+
- Node.js 18+
- Cursor IDE with MCP support

## Changelog

### 1.3.1 (27 March 2026)

- **Fixed:** When both `PW_PATH` and `PW_REMOTE_URL` are set (hybrid local+remote config), `runPwCommand` now prefers the local PHP CLI. Previously `PW_REMOTE_URL` silently hijacked all commands, causing page queries to return stale remote data instead of live local data. Tools that need the remote endpoint (file-sync, pusher, schema-sync) call `runRemoteCommand()` directly and are unaffected.
- **Fixed:** Remote API endpoint now sends `Cache-Control: no-store` and `Pragma: no-cache` headers to prevent proxy or browser caching of API responses.

### 1.3.0 (27 March 2026)

- **New:** `pw_page_init` tool — initialise or repair `page.meta.json` for sync directories where content files were created manually. Links to existing PW pages or scaffolds new ones.
- **Improved:** `pw_page_new` is now idempotent. If the directory exists but `page.meta.json` is missing, it creates only the scaffold files without overwriting existing `page.yaml` or field files.
- **Improved:** `pw_page_publish` auto-generates `page.meta.json` from `page.yaml` + directory structure when the meta file is missing (requires parent template to allow only one child template).
- **Improved:** Error messages in `pw_page_push` and `pw_page_publish` now show relative paths instead of absolute server paths, and include actionable hints (e.g. "use pw_page_init to generate it").

### 1.2.0

- File sync, schema sync, cross-environment page ID resolution, remote API.

### 1.1.0

- Content sync (pull/push), page creation and publishing.

### 1.0.0

- Initial release. Site inspection, page queries, template/field introspection.

## License

MIT
