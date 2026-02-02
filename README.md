# ProcessWire MCP

ProcessWire ↔ Cursor MCP Bridge

A ProcessWire module that exposes site structure and content to Cursor IDE via the Model Context Protocol (MCP). Query your ProcessWire site using natural language in Cursor.

## Using in Cursor Chat

Just ask naturally — the AI will use the MCP tools automatically:

**Structure & Schema:**
- "What templates does this site have?"
- "Show me the fields on the blog-post template"
- "Export the full site schema"
- "What field types are available?"

**Pages & Content:**
- "Get the homepage"
- "Get the page at /about/"
- "Show me page ID 1764"
- "Query the 5 most recent blog posts"

**Fields & Images:**
- "What fields does the basic-page template use?"
- "Show me all image fields"
- "Get the page at /team/ with file details"
- "What pages use the sidebar field?"

**Search:**
- "Search for pages containing 'John Doe'"
- "Find all PDF files on the site"
- "Search for images with 'team' in the filename"

**Content Sync (Export/Edit/Import):**
- "Export the about page for editing" (or "Pull the about page")
- "Export all pages under /services/" (or "Pull all pages under /services/")
- "Check sync status"
- "Import my changes to ProcessWire" (or "Push my changes")

**With Options:**
- "Get page /about/ with field labels"
- "Export schema in YAML format"

## Features

- **List templates and fields** — Understand your site structure
- **Query pages** — Get page data by ID, path, or selector
- **Export schema** — Full site schema in JSON or YAML
- **RepeaterMatrix support** — Full content extraction with type labels
- **File/image metadata** — Filenames, dimensions, URLs
- **Field labels** — Optional human-readable field descriptions
- **Content sync** — Pull pages to local YAML, edit, and push back
- **Bulk operations** — Pull/push entire sections at once
- **Conflict detection** — Warns if remote page changed since pull

## Architecture

```
Cursor (Chat) → MCP Server (Node.js) → CLI (PHP) → ProcessWire API
```

## Components

- **PwMcp/** — ProcessWire module with CLI interface and core sync engine
- **PwMcpAdmin/** — ProcessWire admin module with hierarchical page tree interface
- **mcp-server/** — Node.js/TypeScript MCP server for Cursor integration

## Installation

### 1. Install the ProcessWire Module

Copy or clone the `PwMcp` folder into your ProcessWire site's modules directory:

```bash
# Option A: Clone the entire repo, then copy the module
git clone https://github.com/PeterKnightDigital/ProcessWire-MCP.git
cp -r ProcessWire-MCP/PwMcp /path/to/your-site/site/modules/

# Option B: Download and extract just the PwMcp folder from GitHub
```

Then in ProcessWire admin: **Modules → Refresh → Install PwMcp**

### 2. Build the MCP Server

```bash
cd /path/to/ProcessWire-MCP/mcp-server
npm install
npm run build
```

### 3. Configure Cursor

Add to `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "ProcessWire MCP": {
      "command": "node",
      "args": ["/path/to/ProcessWire-MCP/mcp-server/dist/index.js"],
      "env": {
        "PW_PATH": "/path/to/your-processwire-site"
      }
    }
  }
}
```

**Environment Variables:**

| Variable | Required | Description |
|----------|----------|-------------|
| `PW_PATH` | Yes | Path to your ProcessWire installation |
| `PHP_PATH` | No | Path to PHP binary (defaults to `php`) |
| `PW_MCP_CLI_PATH` | No | Custom path to CLI script (auto-detected if module is in standard location) |

**Note:** If you're using MAMP, XAMPP, or another local server, you may need to specify `PHP_PATH`:

```json
"env": {
  "PW_PATH": "/path/to/your-site",
  "PHP_PATH": "/Applications/MAMP/bin/php/php8.3.28/bin/php"
}
```

### 4. Reload Cursor

Press `Cmd+Shift+P` → "Reload Window"

## CLI Usage

You can also use the CLI directly for testing:

```bash
# Set the ProcessWire path
export PW_PATH=/path/to/your-site

# Health check
php site/modules/PwMcp/bin/pw-mcp.php health --pretty

# List all templates
php site/modules/PwMcp/bin/pw-mcp.php list-templates --pretty

# Get a specific page
php site/modules/PwMcp/bin/pw-mcp.php get-page /about/ --pretty

# Get page with file metadata
php site/modules/PwMcp/bin/pw-mcp.php get-page /about/ --pretty --include=files

# Get page with field labels
php site/modules/PwMcp/bin/pw-mcp.php get-page /about/ --pretty --include=labels

# Query pages by selector
php site/modules/PwMcp/bin/pw-mcp.php query-pages "template=blog-post, limit=10" --pretty

# Export full schema
php site/modules/PwMcp/bin/pw-mcp.php export-schema --pretty
```

## Available Commands

| Command | Description |
|---------|-------------|
| `health` | Check connection and get site info |
| `list-templates` | List all non-system templates |
| `get-template [name]` | Get template details and fields |
| `list-fields` | List all non-system fields |
| `get-field [name]` | Get field details and usage |
| `get-page [id\|path]` | Get page by ID or path with field values |
| `query-pages [selector]` | Query pages using ProcessWire selectors |
| `search [query]` | Search page content across text fields |
| `search-files [query]` | Search files by name, extension, or description |
| `export-schema` | Export complete site schema |
| `page:pull [id\|path]` | Pull a page into local sync directory |
| `page:push [path]` | Push local changes back to ProcessWire |
| `pages:pull [selector]` | Bulk pull pages by selector, parent, or template |
| `pages:push [directory]` | Bulk push all changes in a directory |
| `sync:status [directory]` | Check sync status of pulled pages |
| `sync:reconcile [directory]` | Fix path drift and detect orphans |
| `page:new [template] [parent] [name]` | Create new page scaffold locally |
| `page:publish [path]` | Publish new page to ProcessWire |
| `pages:publish [directory]` | Bulk publish new pages |
| `matrix:info [page] [field]` | Get matrix/repeater field structure |
| `matrix:add [page] [field] [type]` | Add a new matrix item to a page |
| `help` | Show available commands |

## CLI Flags

| Flag | Description |
|------|-------------|
| `--format=json\|yaml` | Output format (default: json) |
| `--pretty` | Pretty-print JSON output |
| `--include=usage` | Include which templates use each field |
| `--include=files` | Include full file/image metadata (URL, size, dimensions) |
| `--include=labels` | Include field labels and descriptions |
| `--truncate=N` | Truncate text fields to N characters (get-page) |
| `--summary` | Return field structure only, no content (get-page) |
| `--dry-run=0` | Apply changes instead of preview (push/publish commands) |
| `--force` | Force push even if remote has changed |
| `--no-parent` | Exclude parent page when pulling by path |
| `--limit=N` | Limit number of pages to pull |
| `--title="Title"` | Page title (page:new) |
| `--published` | Create page as published instead of unpublished |
| `--content='{"field":"value"}'` | JSON content for matrix item (matrix:add) |

## Example Output

### Health Check

```json
{
  "status": "ok",
  "pwVersion": "3.0.229",
  "siteName": "example.com",
  "moduleLoaded": true,
  "counts": {
    "templates": 45,
    "fields": 72,
    "pages": 960
  },
  "writesEnabled": false
}
```

### Page with RepeaterMatrix

```json
{
  "id": 1764,
  "path": "/guides/getting-started-guide/",
  "template": "blog-post",
  "fields": {
    "matrix": {
      "_count": 11,
      "_items": [
        {
          "_typeId": 1,
          "_typeLabel": "Body",
          "Body": "<h2>Content here...</h2>",
          "Images": null
        }
      ]
    }
  }
}
```

## Requirements

- ProcessWire 3.0.165+
- PHP 8.0+
- Node.js 18+
- Cursor IDE with MCP support

## Content Sync Workflow

Pull pages to local YAML files, edit them, and push changes back to ProcessWire.

### 1. Pull a Page

```bash
# Pull a single page
php site/modules/PwMcp/bin/pw-mcp.php page:pull /about/ --pretty

# Pull an entire section
php site/modules/PwMcp/bin/pw-mcp.php pages:pull /services/ --pretty

# Pull by template
php site/modules/PwMcp/bin/pw-mcp.php pages:pull "template=blog-post" --limit=20 --pretty
```

Pages are saved to `site/assets/pw-mcp/[page-path]/`:
- `page.meta.json` — ID, template, revision hash, content hash (don't edit)
- `page.yaml` — Editable field content

**Note:** The `page.meta.json` file now includes a `contentHash` field that stores an MD5 hash of the actual YAML file content. This enables accurate detection of local changes without false positives from serialization differences.

### 2. Edit Locally

Edit the `page.yaml` files in your editor or ask the AI to make changes.

### 3. Check Status

```bash
php site/modules/PwMcp/bin/pw-mcp.php sync:status --pretty
```

Shows which pages have:
- **clean** (In Sync) — No changes
- **localDirty** (Local Changes) — Local edits pending
- **remoteChanged** (Remote Changes) — ProcessWire page modified since export
- **conflict** (Conflict) — Both local and remote changed
- **notPulled** (Never Exported) — Page not yet synced

### 4. Push Changes

```bash
# Preview changes (dry-run, default)
php site/modules/PwMcp/bin/pw-mcp.php pages:push site/assets/pw-mcp/services --pretty

# Apply changes
php site/modules/PwMcp/bin/pw-mcp.php pages:push site/assets/pw-mcp/services --dry-run=0 --pretty
```

### Sync Notes

- **Page references** in YAML show `id` (editable) and `_comment` (read-only display info)
- **Repeater items** use `_itemId` for stable matching — don't change these
- **Files/images** are read-only (file uploads planned for Phase 4)
- Use `--force` to push even if remote changed (overwrites remote)

### 5. Reconcile (Fix Drift)

If pages are moved or deleted in ProcessWire, local folders can become stale:

```bash
# Preview what needs fixing
php site/modules/PwMcp/bin/pw-mcp.php sync:reconcile --pretty

# Apply fixes
php site/modules/PwMcp/bin/pw-mcp.php sync:reconcile --dry-run=0 --pretty
```

Detects:
- **Path drift** — Page moved/renamed in ProcessWire, local folder needs updating
- **Orphans** — Page deleted in ProcessWire, local folder is stale

## Creating New Pages

Create pages locally, edit them, then publish to ProcessWire.

### 1. Scaffold a New Page

```bash
# Create a new page scaffold
php site/modules/PwMcp/bin/pw-mcp.php page:new blog-post /news/posts/ my-new-article --title="My New Article" --pretty
```

This creates:
- `site/assets/pw-mcp/news/posts/my-new-article/page.meta.json` (with `new: true`)
- `site/assets/pw-mcp/news/posts/my-new-article/page.yaml` (with template fields)

### 2. Edit the Content

Edit the `page.yaml` file to fill in your content.

### 3. Publish to ProcessWire

```bash
# Preview what will be created (dry-run, default)
php site/modules/PwMcp/bin/pw-mcp.php page:publish site/assets/pw-mcp/news/posts/my-new-article --pretty

# Actually create the page
php site/modules/PwMcp/bin/pw-mcp.php page:publish site/assets/pw-mcp/news/posts/my-new-article --dry-run=0 --pretty

# Create as published (default is unpublished)
php site/modules/PwMcp/bin/pw-mcp.php page:publish site/assets/pw-mcp/news/posts/my-new-article --dry-run=0 --published --pretty
```

### 4. Bulk Publish

```bash
# Preview all new pages in a directory
php site/modules/PwMcp/bin/pw-mcp.php pages:publish site/assets/pw-mcp/news --pretty

# Create all new pages
php site/modules/PwMcp/bin/pw-mcp.php pages:publish site/assets/pw-mcp/news --dry-run=0 --pretty
```

## ProcessWire Admin Interface

The module includes **PwMcpAdmin** — a full-featured admin interface in ProcessWire:

- **Hierarchical page tree** with expand/collapse navigation
- **Unpublished/hidden page support** — Pages styled like ProcessWire's native tree:
  - Unpublished pages shown with strikethrough text
  - Hidden pages shown with reduced opacity
- **Status badges** color-coded by sync state:
  - **In Sync** — No changes (green)
  - **Local Changes** — Edits pending locally (yellow)
  - **Remote Changes** — Page modified in ProcessWire since export (blue)
  - **Conflict** — Both local and remote changed (red)
  - **Never Exported** — Not yet synced (grey)
- **Smart tree selection** — Checkbox-based selection with branch awareness
- **Selection toolbar** — Shows count, hidden selections, and modified pages
- **Filter dropdowns** — UIkit-styled filters with live counts per option
- **Action icons** — Quick access to Export, Import, and View YAML
- **Custom tooltips** — Helpful descriptions on hover

### Tree Selection Behavior

The checkbox system is context-aware based on expand/collapse state:

| Scenario | What Happens |
|----------|--------------|
| **Check a collapsed parent** | Selects the parent and ALL descendants at every depth |
| **Check an expanded parent** | Selects only that page (granular mode) |
| **Uncheck one child** | Parent shows indeterminate (dash) state |
| **Collapse with selections** | Chevron shows blue dot indicating hidden selections |

**Header checkbox** mirrors tree state: unchecked (none), indeterminate (some), or checked (all).

### Selection Toolbar

Always visible above the table:

- **Selection summary** — "12 pages selected (3 hidden), 5 modified"
- **Export button** — Active when pages selected, fires immediately
- **Import button** — Active only when modified pages selected, shows confirmation modal

### Import Preview

When importing changes, a preview shows exactly which fields changed with content snippets:

```
Body
Lower back injuries can vary widely in how they present and how long...

matrix→Body[1]
Lower back injuries can be painful, disruptive, and slow to resolve...

matrix→Body[2]
Common causes include poor posture, heavy lifting, and sports injuries...
```

**Features:**
- Simple fields show a truncated preview of the new content
- Matrix/repeater fields use breadcrumb notation: `matrix→fieldname[item#]`
- Each changed item within a matrix is listed individually with its content

### Import Confirmation Modal

Bulk imports show a confirmation dialog because they overwrite live CMS pages:
- States exact count of pages to import
- Notes any clean pages that will be skipped
- Confirm button shows the action: "Import 5 pages"

### UI Terminology

**For clarity, the admin interface uses different terms than the CLI:**

| CLI Command | UI Label | Description |
|-------------|----------|-------------|
| `page:pull` | **Export to File** | Download page content to local YAML |
| `page:push` | **Import from File** | Upload local YAML changes to ProcessWire |
| `pages:pull` | **Export** (bulk) | Download multiple pages |
| `pages:push` | **Import** (bulk) | Upload multiple pages |

This makes the direction of data flow clearer in the visual interface.

## Direct Write Tools (Phase 4)

Add content directly to pages from chat without the YAML sync workflow:

### Add Matrix Items

Add FAQs, body blocks, CTAs, and other matrix items directly:

```bash
# Preview what would be created (dry-run)
php site/modules/PwMcp/bin/pw-mcp.php matrix:add /about/ matrix faq \
  --content='{"question":"What is a web design project?","answer":"We offer web design, development..."}' \
  --pretty

# Create the item
php site/modules/PwMcp/bin/pw-mcp.php matrix:add /about/ matrix faq \
  --content='{"question":"What is a web design project?","answer":"We offer web design, development..."}' \
  --dry-run=0 --pretty
```

**Via Cursor Chat:**

> "Add these FAQs to the web design project page:
> - Q: What is your process? A: We follow an agile approach...
> - Q: How long does a project take? A: Project timelines vary based on..."

The AI will use `pw_matrix_add` to create each FAQ item.

### Available Matrix Tools

| Tool | Description |
|------|-------------|
| `pw_matrix_info` | Discover matrix field structure (types, fields, nested repeaters) |
| `pw_matrix_add` | Add a new matrix item with content |

### Discovering Field Structure

Before adding content, use `matrix:info` to understand a field's structure:

```bash
php site/modules/PwMcp/bin/pw-mcp.php matrix:info /about/ matrix --pretty
```

Returns:
```json
{
  "field": { "name": "matrix", "type": "RepeaterMatrix" },
  "matrixTypes": [
    {
      "id": 1,
      "name": "body_block",
      "label": "Body",
      "fields": [{ "name": "Body", "type": "FieldtypeTextarea" }]
    },
    {
      "id": 2,
      "name": "faq_block",
      "label": "FAQs",
      "fields": [{ "name": "faq", "type": "FieldtypeRepeater" }],
      "nestedRepeaters": {
        "faq": {
          "fields": [
            { "name": "question", "type": "FieldtypeText" },
            { "name": "answer", "type": "FieldtypeTextarea" }
          ]
        }
      }
    }
  ]
}
```

This tells you:
- What matrix types are available
- What fields each type has
- Whether there are nested repeaters and their field structure

More write tools coming soon: `pw_matrix_update`, `pw_matrix_delete`, `pw_matrix_reorder`

## Coming Soon

- File and image uploads
- Page deletion with safety checks
- Real-time sync notifications
- Bulk matrix operations

## License

MIT
