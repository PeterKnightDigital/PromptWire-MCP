# PromptWire reference

## Common tools

| Tool | Use |
|------|-----|
| `pw_health` | Connection check |
| `pw_search` | Find pages by content keyword |
| `pw_get_page` | Read live page (use `truncate` or `summary` for large pages) |
| `pw_page_pull` | Pull to sync tree (`source: remote` to mirror production) |
| `pw_page_push` | Push sync tree (`dryRun`, `targets`, `publish`) |
| `pw_page_rename` | Rename page slug (`idOrPath`, `newName`, `targets`, `reconcileLocal`) |
| `pw_page_new` / `pw_page_publish` | Scaffold and publish new pages |
| `pw_pages_pull` / `pw_pages_push` | Bulk by selector, parent, or template |
| `pw_sync_status` | Dirty / conflict status |
| `pw_file_sync` | Binary file fields (ZIPs, images) |
| `pw_schema_pull` / `pw_schema_push` / `pw_schema_diff` | Field and template schema |

Full list: [MCP Tools Reference](https://www.peterknight.digital/docs/promptwire/v1/mcp-tools-reference/).

## Project setup

Each ProcessWire project should have `.cursor/mcp.json` with PromptWire MCP servers. Copy `install/cursor-rules/promptwire.mdc` into `.cursor/rules/`.

## Rename / page references

**Slug rename:** use `pw_page_rename` (install skill `promptwire-page-rename`). Replaces manual admin renames and one-off CLI scripts. Auto-reconciles `site/assets/pw-mcp/` folders on local rename.

To rename a referenced page title only: edit the **source page** title, not `_comment` fields in referencing pages.

## HannaCode and forms

Preserve `[[...]]` HannaCode unless explicitly asked to change. Do not edit FormBuilder embed tags in content.
