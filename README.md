# ProcessWire MCP

ProcessWire ↔ Cursor MCP Bridge — with remote site support and schema-as-code sync.

Query, edit, and scaffold content on any ProcessWire site from Cursor IDE using natural language. Sync fields, templates, and pages between local and remote environments with full diff, collision detection, and dry-run support.

## Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| ProcessWire | 3.0.210+ | 3.0.244+ |
| PHP | 8.0+ | 8.2+ |
| Node.js | 18+ | 20+ |
| Cursor IDE | With MCP support | Latest |

---

## Architecture

```
Cursor Chat
    │
    ▼
Node.js MCP Server
    │                    │
    ▼ (local)            ▼ (remote)
PHP CLI          HTTPS POST → pw-mcp-api.php
    │                         │
    ▼                         ▼
Local PW DB            Remote PW DB
```

---

## Installation

### 1. Copy modules to ProcessWire

Copy **both** module folders to `site/modules/`:

```
site/modules/PwMcp/        ← Core module (required)
site/modules/PwMcpAdmin/   ← Admin UI (optional)
```

In ProcessWire admin: **Modules → Refresh → Install PwMcp**

### 2. Build the MCP server

```bash
cd /path/to/ProcessWire-MCP/mcp-server
npm install
npm run build
```

### 3. Configure Cursor

Create `.cursor/mcp.json` in your site root:

```json
{
  "mcpServers": {
    "ProcessWire MCP: MySite.com (local)": {
      "command": "node",
      "args": ["/path/to/ProcessWire-MCP/mcp-server/dist/index.js"],
      "env": {
        "PW_PATH": "/path/to/your-site",
        "PW_MCP_CLI_PATH": "/path/to/your-site/site/modules/PwMcp/bin/pw-mcp.php",
        "PHP_PATH": "/usr/bin/php"
      }
    }
  }
}
```

**MAMP users:** set `PHP_PATH` to your MAMP PHP binary, e.g.:
```
/Applications/MAMP/bin/php/php8.3.30/bin/php
```

### 4. Reload Cursor

`Cmd+Shift+P` → Reload Window

---

## Remote Site Setup

Connect Cursor to any remote ProcessWire site over HTTPS.

### Step 1 — Deploy the API endpoint

Upload `PwMcp/api/pw-mcp-api.php` to your **remote site root** (same level as `index.php`).

### Step 2 — Create an API key config

On the remote server, create `site/config-pw-mcp.php`:

```php
<?php
define('PW_MCP_API_KEY', 'your-secret-key-here');

// Optional: restrict to your Mac's IP (find it with: curl ifconfig.me)
// define('PW_MCP_ALLOWED_IPS', '1.2.3.4');
```

Generate a strong key: `openssl rand -hex 32`

### Step 3 — Upload the PwMcp module

Upload the `PwMcp/` module folder to `site/modules/PwMcp/` on the remote server. Install it in the ProcessWire admin (Modules → Refresh → Install PwMcp).

### Step 4 — Add remote server to mcp.json

```json
{
  "mcpServers": {
    "ProcessWire MCP: MySite.com (local)": {
      "command": "node",
      "args": ["/path/to/mcp-server/dist/index.js"],
      "env": {
        "PW_PATH": "/path/to/local-site",
        "PW_MCP_CLI_PATH": "/path/to/local-site/site/modules/PwMcp/bin/pw-mcp.php",
        "PHP_PATH": "/usr/bin/php"
      }
    },
    "ProcessWire MCP: MySite.com (production)": {
      "command": "node",
      "args": ["/path/to/mcp-server/dist/index.js"],
      "env": {
        "PW_REMOTE_URL": "https://mysite.com/pw-mcp-api.php",
        "PW_REMOTE_KEY": "your-secret-key-here",
        "PW_SYNC_DIR": "/path/to/local-site/.pw-sync"
      }
    }
  }
}
```

`PW_SYNC_DIR` points both servers at the same local `.pw-sync/` folder — this is how schema files and site configs are shared between your local and remote connections.

### Step 5 — Test the connection

After reloading Cursor, say: **"Check health on production"**

You should see your remote site's PW version, template count, and field count.

---

## Named Site Configs

For cross-site comparison, create named site configs in `.pw-sync/sites/`:

**.pw-sync/sites/production.json**
```json
{
  "name": "production",
  "label": "MySite.com (production)",
  "url": "https://mysite.com/pw-mcp-api.php",
  "key": "your-secret-key-here"
}
```

Add as many as you need (`staging.json`, `client-a.json`, etc.). These names are used with `pw_schema_compare`.

---

## Schema Sync Workflow

Sync fields and templates between environments — like Prisma for ProcessWire.

```
.pw-sync/
  schema/
    fields.json     ← all field definitions
    templates.json  ← all template definitions
  sites/
    production.json ← named site configs
    staging.json
```

### Pull schema from any site

```
"Pull the schema from production"
"Pull my local schema into files"
```

Exports all fields and templates to `.pw-sync/schema/fields.json` and `templates.json`. Works for both local and remote connections.

### See what's different

```
"Show me the schema diff"
"What's different between my local schema files and the connected site?"
```

### Compare two sites directly

```
"Compare local vs production"
"What would change if I pushed my schema to production?"
```

Every difference is classified by severity:

| Severity | Meaning | Example |
|----------|---------|---------|
| `safe` | Additive — new field/template | Field exists locally, not on production |
| `warning` | Config change — low risk | Label or description changed |
| `danger` | Risky — review required | Field type changed, maxlength reduced |
| `info` | Exists on target only | Won't be affected by push |

### Push schema to a site

```
"Push my schema to production — dry run first"
"Apply my local schema to production"
```

Dry-run is always the default. Explicitly confirm to apply:

```
"Apply to production, skip dry run"
```

**Safety rules:** schema push never deletes fields or templates — only creates or updates. Type changes are blocked and must be done manually in the PW admin.

---

## Page Content Sync Workflow

Pull pages to local YAML files, edit them, and push changes back.

### Pull pages

```
"Pull the about page"
"Pull all pages under /services/"
"Pull the last 20 blog posts"
```

Pages are saved to `site/assets/pw-mcp/[path]/`:
- `page.meta.json` — ID, template, revision hash
- `page.yaml` — editable field content
- `fields/*.html` — rich text fields
- `matrix/*.html` — matrix item rich text

### Check sync status

```
"Check sync status"
"Which pages have local changes?"
```

Status values: `clean`, `localDirty`, `remoteChanged`, `conflict`, `notPulled`

### Push changes

```
"Preview my changes to /about/"
"Push my changes to /about/ — apply"
"Push all local changes — dry run"
```

### Create new pages

```
"Create a new blog post called 'Our New Service'"
"Scaffold a new page under /services/ using the service template"
```

Then publish when ready:

```
"Publish my new blog post — dry run first"
"Publish it"
```

---

## Cursor Chat Examples

### Read operations (always safe)

```
"What templates does this site have?"
"Show me all fields on the blog-post template"
"Get the homepage"
"Get page 1042"
"Get the 10 most recent blog posts"
"Search for pages containing 'sustainability'"
"Show me all PDF files on the site"
"Export the full site schema"
```

### Schema operations

```
"Pull the production schema"
"Compare local vs production — what would change?"
"List my configured sites"
"Push my schema to staging — dry run"
"Apply my schema changes to production"
```

### Content sync

```
"Pull the /about/ page for editing"
"Pull all pages under /services/"
"Check sync status"
"Show me my local changes to /about/"
"Push my changes to /about/"
"Force push /about/ even though it changed remotely"
"Reconcile my sync directory — fix any path drift"
```

### Creating content

```
"Create a new FAQ page under /support/"
"Add an FAQ to the about page: Q: What do you do? A: We build websites."
"Scaffold three new blog posts about AI trends"
"Publish all my new pages as unpublished drafts"
```

---

## MCP Tools Reference

### Read tools

| Tool | Description |
|------|-------------|
| `pw_health` | Check connection and get site info |
| `pw_list_templates` | List all templates |
| `pw_get_template` | Get template details and fields |
| `pw_list_fields` | List all fields |
| `pw_get_field` | Get field details and settings |
| `pw_get_page` | Get page by ID or path |
| `pw_query_pages` | Query pages by PW selector |
| `pw_search` | Search page content |
| `pw_search_files` | Search files by name or extension |
| `pw_export_schema` | Export full site schema as JSON |

### Schema sync tools

| Tool | Description |
|------|-------------|
| `pw_schema_pull` | Pull schema from connected site to local files |
| `pw_schema_push` | Push local schema files to connected site |
| `pw_schema_diff` | Diff local schema files vs connected site |
| `pw_schema_compare` | Compare two sites directly with collision classification |
| `pw_list_sites` | List configured named sites |

### Page sync tools

| Tool | Description |
|------|-------------|
| `pw_page_pull` | Pull a single page to local YAML |
| `pw_page_push` | Push local changes — supports `targets` (local/remote/both) and `publish` flag |
| `pw_pages_pull` | Pull multiple pages by selector or parent |
| `pw_pages_push` | Push all local changes in a directory |
| `pw_sync_status` | Check sync status of pulled pages |
| `pw_sync_reconcile` | Fix path drift and detect orphans |

### Create & publish tools

| Tool | Description |
|------|-------------|
| `pw_page_new` | Scaffold a new page locally |
| `pw_page_publish` | Publish a scaffold — supports `targets` (local/remote/both) and `published` flag |
| `pw_pages_publish` | Bulk publish new pages |

### Matrix / repeater tools

| Tool | Description |
|------|-------------|
| `pw_matrix_info` | Get matrix field structure and types |
| `pw_matrix_add` | Add a matrix item directly to a page |

### Page reference tools *(Phase 2)*

| Tool | Description |
|------|-------------|
| `pw_validate_refs` | Validate all `_pageRef` fields in synced pages against a target environment |

---

## Phase 2 Roadmap

### Page reference validation (`pw_validate_refs`) — *Priority*

When a page has `FieldtypePage` fields (e.g. `featured_services`, `blog_categories`), the referenced pages must exist on the **target** environment. Because page IDs differ between local and production, the sync layer now stores paths alongside IDs — but it can't guarantee those paths exist on the target before you push.

`pw_validate_refs` solves this by scanning every synced page in `site/assets/pw-mcp/` before a push and checking each `_pageRef` path against the target.

**Planned workflow:**

```
"Validate my page refs against production"
"Are there any broken page references before I push?"
"Check refs for /services/ — dry run push"
```

**What it reports:**

| Status | Meaning |
|--------|---------|
| `ok` | Path resolves on target |
| `missing` | Path not found on target — push would leave field blank |
| `unpublished` | Page exists but is unpublished — ref is valid but page won't be visible |
| `type_mismatch` | Field type changed since last pull — ref may be invalid |

**Implementation notes:**
- Reads all `page.yaml` files under `site/assets/pw-mcp/`
- Collects every `_pageRef` object (single and array)
- Calls `page:exists` on the target environment for each unique path
- Returns a structured report grouped by page and field
- Integrates with `pw_pages_push` as an optional pre-push gate (set `validateRefs: true`)

---

### Staging environment

Add a third named site config and MCP server entry for a staging environment. Enables a `local → staging → production` promotion workflow with schema and content diffs at each step.

**Planned `.pw-sync/sites/staging.json`:**

```json
{
  "name": "staging",
  "label": "MySite.com (staging)",
  "url": "https://staging.mysite.com/pw-mcp-api.php",
  "key": "your-staging-key"
}
```

**Planned prompts:**

```
"Push /about/ to staging first, then production"
"Compare staging vs production schema"
"Promote staging content to production"
```

---

### Matrix / repeater item sync

Matrix (ProFields FieldtypeRepeaterMatrix) items are pages themselves in ProcessWire. The YAML serialiser already captures matrix data on pull, but `page:push` currently skips it.

**Planned tools:**

- `pw_matrix_add` — add a new matrix item of a given type to a page
- `pw_matrix_update` — update a specific item by index or type label
- `pw_matrix_reorder` — reorder matrix items

**Planned prompts:**

```
"Add a Text + Image matrix item to the /about/ page"
"Update the second 'CTA' block on /services/"
"Duplicate the hero block from /about/ to /contact/"
```

**Implementation notes:**
- Matrix items are stored as sub-pages in ProcessWire
- Push requires creating/updating those sub-pages in the correct order
- ID resolution applies to any `_pageRef` fields inside matrix items

---

## CLI Usage

All tools are also available directly via CLI for scripting and testing:

```bash
export PW_PATH=/path/to/your-site

# Health check
php site/modules/PwMcp/bin/pw-mcp.php health --pretty

# Schema
php site/modules/PwMcp/bin/pw-mcp.php export-schema --pretty
php site/modules/PwMcp/bin/pw-mcp.php schema:apply .pw-sync/schema/combined.json --pretty
php site/modules/PwMcp/bin/pw-mcp.php schema:apply .pw-sync/schema/combined.json --dry-run=0 --pretty

# Pages
php site/modules/PwMcp/bin/pw-mcp.php get-page /about/ --pretty
php site/modules/PwMcp/bin/pw-mcp.php page:pull /about/ --pretty
php site/modules/PwMcp/bin/pw-mcp.php page:push site/assets/pw-mcp/about --pretty
php site/modules/PwMcp/bin/pw-mcp.php page:push site/assets/pw-mcp/about --dry-run=0 --pretty

# Sync status
php site/modules/PwMcp/bin/pw-mcp.php sync:status --pretty
php site/modules/PwMcp/bin/pw-mcp.php sync:reconcile --pretty
```

---

## Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `PW_PATH` | For local | Path to ProcessWire installation root |
| `PHP_PATH` | No | Path to PHP binary (default: `php`) |
| `PW_MCP_CLI_PATH` | No | Custom path to CLI script (auto-detected) |
| `PW_REMOTE_URL` | For remote | HTTPS URL to `pw-mcp-api.php` on remote site |
| `PW_REMOTE_KEY` | For remote | API key matching `PW_MCP_API_KEY` on remote site |
| `PW_SYNC_DIR` | Recommended | Path to `.pw-sync/` directory (shared between local + remote server configs) |

---

## .pw-sync Directory Layout

```
.pw-sync/
  schema/
    fields.json      ← field definitions (written by schema:pull)
    templates.json   ← template definitions (written by schema:pull)
  sites/
    example.json     ← template — copy and rename
    production.json  ← named site config for pw_schema_compare
    staging.json
```

---

## Security

- The remote API (`pw-mcp-api.php`) uses `hash_equals()` for constant-time key comparison
- All API keys are sent via HTTPS header (`X-PW-MCP-Key`)
- `site/config-pw-mcp.php` is protected by ProcessWire's default `.htaccess` (blocks direct browser access to `site/`)
- Optional IP allowlist via `PW_MCP_ALLOWED_IPS` in the config file
- Schema push never deletes — only creates or updates
- Field type changes are blocked to prevent data loss

---

## Components

- **`PwMcp/`** — ProcessWire module with CLI interface, schema importer, and sync engine
- **`PwMcpAdmin/`** — ProcessWire admin UI with hierarchical page tree and sync interface
- **`PwMcp/api/pw-mcp-api.php`** — Remote API endpoint (deploy to remote site root)
- **`mcp-server/`** — Node.js/TypeScript MCP server for Cursor integration

---

## License

MIT
