---
name: promptwire-page-rename
description: >-
  Rename ProcessWire page slugs via PromptWire MCP (pw_page_rename). Use when
  changing a page URL/name, SEO slug updates, or after deciding a pw-mcp folder
  should move to a new path. Works on local and remote via targets.
---
# PromptWire Page Rename

Rename ProcessWire page slugs without SSH or admin clicks. Uses `pw_page_rename`, which triggers PagePathHistory 301 redirects when installed and reconciles the local `site/assets/pw-mcp/` folder after local renames.

Full PromptWire workflow docs: [PromptWire v1](https://www.peterknight.digital/docs/promptwire/v1/)

## When to use

- SEO slug change (e.g. `old-feature-name` → `ssl-reminders`)
- Page moved under a different parent (rename + reconcile)
- Production slug change (`targets: remote` or `both`)

Do **not** use for new pages — use `pw_page_new` + `pw_page_publish` instead.

## Workflow

1. **Dry-run first** (always):
   ```
   pw_page_rename
     idOrPath: /path/to/old-slug/
     newName: new-slug
     dryRun: true
     targets: local | remote | both
   ```

2. **Apply rename**:
   ```
   pw_page_rename
     idOrPath: ...
     newName: ...
     dryRun: false
     targets: both
   ```

3. **Push content** if page.yaml was edited:
   ```
   pw_page_push → site/assets/pw-mcp/.../new-slug/
   targets: both
   ```

4. **Deploy template link changes** if nav/footer/templates reference the old path:
   ```
   pw_site_sync → scope: files
   ```

5. **Clear cache** on production:
   ```
   pw_clear_cache
   ```

## Parameters

| Param | Default | Notes |
|-------|---------|-------|
| `idOrPath` | required | Page ID or full PW path with trailing slash |
| `newName` | required | URL-safe slug only (no slashes) |
| `dryRun` | `true` | Preview old/new paths before applying |
| `targets` | `local` | `remote` or `both` for production |
| `reconcileLocal` | `true` | Moves pw-mcp folder after local rename |
| `syncDirectory` | `site/assets/pw-mcp` | Override sync root if non-standard |

## What happens automatically

- **PagePathHistory**: old URL 301s to new URL (if module installed)
- **Local reconcile**: `site/assets/pw-mcp/.../old-slug/` → `.../new-slug/` + updates `page.meta.json` canonicalPath
- **Collision check**: refuses rename if sibling page already uses the name

## CLI equivalent (local only)

```bash
php site/modules/PromptWire/bin/promptwire.php page:rename 1103 new-slug --dry-run=0
```

Remote rename must go through MCP (`pw_page_rename` with `targets: remote`).

## Related tools

- `pw_sync_reconcile` — fix path drift without renaming (page already renamed in admin)
- `pw_page_push` — push field/SEO changes after rename
- `pw_page_pull` — refresh local copy from production
