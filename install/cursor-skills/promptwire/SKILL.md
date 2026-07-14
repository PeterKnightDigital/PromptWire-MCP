---
name: promptwire
description: >-
  ProcessWire CMS content workflow via PromptWire MCP. Use when working on any
  ProcessWire site (wire/, site/templates/) where PromptWire is installed or
  pw_* MCP tools are available — especially for page content, blog posts, field
  values, schema sync, or push/pull between local and remote environments.
---
# PromptWire (ProcessWire content)

ProcessWire sites with PromptWire use MCP (`pw_*` tools) to push and pull CMS content between local dev and remote. Full docs: [PromptWire v1](https://www.peterknight.digital/docs/promptwire/v1/).

## Default assumption

When asked to change **page content** (copy, posts, field values, SEO meta, CMS-managed pages), use PromptWire — not direct DB queries, not hand-authoring sync YAML without pull/new, and not inferring live content from template files alone.

## Workflow

1. **Existing page:** `pw_page_pull` → edit `site/assets/pw-mcp/` → `pw_page_push` with `dryRun: true` → apply with `dryRun: false`
2. **New page:** `pw_page_new` → edit → `pw_page_publish`
3. **Bulk:** `pw_pages_pull` / `pw_pages_push` or `pw_sync_status` to see dirty pages
4. **Production:** `targets: remote` or `both` — confirm before pushing to live

## Source files vs CMS content

| CMS content (PromptWire) | Code (edit files) |
|--------------------------|-------------------|
| Field values in DB | `site/templates/*.php` |
| Blog/post body copy | Modules, hooks |
| SEO meta, summaries | CSS, JS |
| Navigation page titles when stored as pages | Config outside page content |

## Sync file rules

- Root: `site/assets/pw-mcp/`
- Never edit `page.meta.json`
- Rich text in `fields/*.html` and `matrix/` (referenced from `page.yaml`)
- `_pageRef`: only `id` is editable; `_comment` is display-only

## MCP server choice

Pick the PromptWire MCP entry that matches the target environment (names vary per project). Default pushes go to local dev unless production is explicitly requested.

## More detail

See [reference.md](reference.md) for tool list and YAML patterns.
