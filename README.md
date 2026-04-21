# PromptWire

ProcessWire ↔ Cursor MCP Bridge for AI-assisted development.

> **Disclaimer:** This is a development tool. It gives an AI agent direct access to create, edit, download, and delete pages, fields, templates, and files via the ProcessWire API. Always have backups in place. Do not use this on client-facing sites or any environment where you cannot accept the risk of data loss or corruption. This module is provided as-is, with no warranty. By using it you accept all associated risk.

## Introduction

PromptWire connects your ProcessWire CMS to Cursor IDE via the [Model Context Protocol](https://modelcontextprotocol.io/), giving AI agents direct read/write access to your site's structure, content, and files. It ships with 30+ specialised tools for site inspection, content sync, schema management, page creation, and cross-environment deployment.

**Just describe what you want in plain language:**

> *"Build a blog section at /blog/ with an index page and three sample posts. Posts should have a title, body, summary, and publish date. Dry-run everything first."*

> *"Pull our About page, rewrite the body to be more concise, and push the changes to both local and production."*

> *"Compare our local schema against production and flag anything that could cause problems if we pushed."*

The agent reads your site's schema, selects the right tools, sequences the operations, and previews everything before applying. No tool names to memorise — see the [Prompt recipes](https://www.peterknight.digital/docs/promptwire/v1/prompt-recipes/) for more examples.

The module has two parts:

- **Cursor MCP integration** — describe what you want and the AI agent builds it: creates templates, fields, pages, and content, pushes changes between environments, and compares schemas. All from Cursor chat.
- **[Admin dashboard](#admin-dashboard)** — a visual sync status UI inside the PW admin. See which pages are synced, view diffs, pull and push individual or bulk pages — all without leaving the browser.

## Installation

### 1. Install the ProcessWire module

Clone or download this repo directly into your ProcessWire `site/modules/` folder as `PromptWire`:

```bash
git clone https://github.com/PeterKnightDigital/PromptWire-MCP.git site/modules/PromptWire
```

Or download the zip, extract, and rename the folder to `PromptWire` inside `site/modules/`.

Then in ProcessWire admin: **Modules → Refresh → Install PromptWire**.

The admin dashboard (`ProcessPromptWireAdmin`) is installed automatically — you'll find it under **Setup → PromptWire Admin**.

### 2. Build the MCP server

```bash
cd site/modules/PromptWire/mcp-server
npm install
npm run build
```

### 3. Configure Cursor

Create or edit `.cursor/mcp.json` in your project root:

```json
{
  "mcpServers": {
    "PromptWire: My Site (Local)": {
      "command": "node",
      "args": ["/path/to/pw-site/site/modules/PromptWire/mcp-server/dist/index.js"],
      "env": {
        "PW_PATH": "/path/to/your/processwire/site",
        "PHP_PATH": "/path/to/php"
      }
    }
  }
}
```

| Setting | What it is |
| --- | --- |
| `"PromptWire: My Site (Local)"` | A label of your choice — this name appears in Cursor's MCP panel |
| `command` | The runtime that starts the MCP server. Always `"node"` |
| `args` | Absolute path to the compiled MCP server entry point (`mcp-server/dist/index.js`) inside your PromptWire module directory |
| `PW_PATH` | Absolute path to your ProcessWire install root (the directory containing `site/` and `wire/`) |
| `PHP_PATH` | Absolute path to your PHP binary. Optional if `php` is already on your system PATH |

**Finding your PHP path:** Run `which php` in your terminal. Common locations:

- **MAMP:** `/Applications/MAMP/bin/php/php8.x.x/bin/php`
- **Homebrew:** `/opt/homebrew/bin/php` or `/usr/local/bin/php`
- **XAMPP:** `/Applications/XAMPP/bin/php`
- **System (macOS):** `/usr/bin/php`

If `which php` returns the correct version (8.0+), you can omit `PHP_PATH` entirely.

### 4. (Optional) Remote site access

To push content to a production site, deploy the API endpoint file (`api/promptwire-api.php`) to your remote site root. **Rename it** to something non-obvious (e.g. `pw-xyz8k3m.php`) so the URL isn't guessable from the public documentation, then set `PW_REMOTE_URL` to match.

See the [Remote setup guide](https://www.peterknight.digital/docs/promptwire/v1/remote-setup/) for full instructions.

## Available tools

### Site inspection

| Tool                | Description                                              |
| ------------------- | -------------------------------------------------------- |
| `pw_health`         | Check ProcessWire connection, version, and counts        |
| `pw_list_templates` | List all templates                                       |
| `pw_get_template`   | Get template details (fields, settings)                  |
| `pw_list_fields`    | List all fields                                          |
| `pw_get_field`      | Get field details (type, settings)                       |
| `pw_get_page`       | Get a page by ID or path with full field content         |
| `pw_query_pages`    | Query pages with ProcessWire selectors                   |
| `pw_search`         | Search page content by keyword                           |
| `pw_search_files`   | Search PHP/template files in the site directory          |
| `pw_export_schema`  | Export the full site schema (templates + fields) as JSON |

### Content sync

Content is synced to `site/assets/pw-mcp/` — editable YAML files that you can open in any editor or hand to an AI agent.

| Tool                | Description                                                          |
| ------------------- | -------------------------------------------------------------------- |
| `pw_page_pull`      | Pull a page into a local sync directory as editable YAML             |
| `pw_page_push`      | Push local YAML changes back to ProcessWire (local, remote, or both) |
| `pw_pages_pull`     | Bulk pull pages by selector, parent, or template                     |
| `pw_pages_push`     | Bulk push all changes in a sync directory tree                       |
| `pw_sync_status`    | Check sync status of all pulled pages (clean, dirty, conflict)       |
| `pw_sync_reconcile` | Fix path drift, detect orphans, reconcile sync directories           |
| `pw_validate_refs`  | Validate page references across synced content                       |

### Page management

| Tool               | Description                                                              |
| ------------------ | ------------------------------------------------------------------------ |
| `pw_page_new`      | Scaffold a new page locally (creates `page.yaml` + `page.meta.json`)    |
| `pw_page_init`     | Initialise or repair `page.meta.json` for a sync directory              |
| `pw_page_publish`  | Publish a scaffolded page to ProcessWire (local, remote, or both)        |
| `pw_pages_publish` | Bulk publish all new page scaffolds in a directory                        |

### Schema sync

| Tool                | Description                                                                 |
| ------------------- | --------------------------------------------------------------------------- |
| `pw_schema_pull`    | Pull field and template schema from a PW site into local files              |
| `pw_schema_push`    | Push local schema files to a PW site (creates/updates fields and templates) |
| `pw_schema_diff`    | Diff local schema files against the live site                               |
| `pw_schema_compare` | Compare schemas between two sites (e.g. local vs production)                |
| `pw_list_sites`     | List configured remote sites from `.pw-sync/sites/`                         |

### File sync

| Tool           | Description                                                      |
| -------------- | ---------------------------------------------------------------- |
| `pw_file_sync` | Sync file/image field content between local and remote           |

### Repeater Matrix

| Tool             | Description                                        |
| ---------------- | -------------------------------------------------- |
| `pw_matrix_info` | Get matrix field structure (types, fields, labels) |
| `pw_matrix_add`  | Add a new item to a repeater matrix field          |

For detailed parameters and examples, see the [Tools reference](https://www.peterknight.digital/docs/promptwire/v1/tools-reference/).

## Admin dashboard

PromptWire includes a visual dashboard in the ProcessWire admin under **Setup → PromptWire Admin**. It installs automatically alongside the main module.

The dashboard shows your full page tree with sync status badges (Clean, File Newer, Wire Newer, Conflict, Untracked), per-row actions (Wire to File, File to Wire, View YAML), template and status filters, and bulk operations for multi-page sync.

For a full walkthrough, see the [Admin dashboard guide](https://www.peterknight.digital/docs/promptwire/v1/admin-dashboard/).

## Documentation

- [**Getting started**](https://www.peterknight.digital/docs/promptwire/) — Installation, setup, and first sync
- [**Remote setup**](https://www.peterknight.digital/docs/promptwire/v1/remote-setup/) — Connecting to production sites via the HTTP API
- [**Content sync**](https://www.peterknight.digital/docs/promptwire/v1/content-sync/) — Pull/push workflow, file sync, cross-environment page ID resolution
- [**Schema sync**](https://www.peterknight.digital/docs/promptwire/v1/schema-sync/) — Synchronising fields and templates between sites
- [**Admin dashboard**](https://www.peterknight.digital/docs/promptwire/v1/admin-dashboard/) — Visual sync UI walkthrough
- [**Prompt recipes**](https://www.peterknight.digital/docs/promptwire/v1/prompt-recipes/) — Natural language prompts for common workflows
- [**Tools reference**](https://www.peterknight.digital/docs/promptwire/v1/tools-reference/) — All tools with parameters and examples
- [**Environment variables**](https://www.peterknight.digital/docs/promptwire/v1/environment-variables/) — Configuration reference
- [**Security**](https://www.peterknight.digital/docs/promptwire/v1/security/) — API authentication, IP allowlists, and best practices

## Requirements

- ProcessWire 3.0+
- PHP 8.0+
- Node.js 18+
- Cursor IDE with MCP support

## Credits

Created and maintained by [Peter Knight](https://www.peterknight.digital).

## License

MIT — see [CHANGELOG.md](CHANGELOG.md) for version history.
