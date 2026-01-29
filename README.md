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

## Architecture

```
Cursor (Chat) → MCP Server (Node.js) → CLI (PHP) → ProcessWire API
```

## Components

- **PwMcp/** — ProcessWire module with CLI interface
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
| `help` | Show available commands |

## CLI Flags

| Flag | Description |
|------|-------------|
| `--format=json\|yaml` | Output format (default: json) |
| `--pretty` | Pretty-print JSON output |
| `--include=usage` | Include which templates use each field |
| `--include=files` | Include full file/image metadata (URL, size, dimensions) |
| `--include=labels` | Include field labels and descriptions |

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

## Phase 2 (Coming Soon)

- Create and update pages
- Manage files and images
- Template and field modifications
- Safe operation with confirmation prompts

## License

MIT
