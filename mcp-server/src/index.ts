#!/usr/bin/env node
/**
 * PromptWire Server
 * 
 * Model Context Protocol (MCP) server that bridges Cursor IDE to ProcessWire CMS.
 * This server exposes ProcessWire structure and content as MCP tools that can
 * be invoked through Cursor's AI chat interface.
 * 
 * Architecture:
 *   Cursor Chat → MCP Server (this file) → CLI Runner → PHP CLI → ProcessWire
 * 
 * The server uses stdio transport for communication with Cursor.
 * All ProcessWire operations are performed by spawning the PHP CLI script.
 * 
 * Environment Variables Required:
 *   - PW_PATH: Path to ProcessWire installation root
 *   - PROMPTWIRE_CLI_PATH: Path to the promptwire.php CLI script
 *   - PHP_PATH: Path to PHP binary (optional, defaults to 'php')
 * 
 * @package     PromptWire
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { runPwCommand, runOnSite, formatToolResponse, type Site } from './cli/runner.js';
import { schemaPull, schemaPush, schemaDiff } from './schema/sync.js';
import { compareSites as compareSchemas, listSiteConfigs } from './schema/compare.js';
import { pushPage, publishPage, pushPagesBulk } from './pages/pusher.js';
import { pullPageFromRemote } from './pages/puller.js';
import { syncFiles } from './pages/file-sync.js';
import { validateRefs } from './pages/validator.js';
import { compareSites as compareSiteFull } from './sync/site-compare.js';
import { syncSites } from './sync/site-sync.js';
import { syncPageAssets, compareSiteAssets } from './sync/page-assets.js';

// ============================================================================
// TOOL DEFINITIONS
// ============================================================================
// These define the MCP tools that Cursor can invoke. Each tool maps to a
// CLI command in the ProcessWire module.

const tools = [
  {
    name: 'pw_health',
    description: 'Check ProcessWire connection and get site info (version, counts, module status). Pass site="remote" to inspect production, or site="both" to compare local and remote side-by-side.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local". "remote" requires PW_REMOTE_URL + PW_REMOTE_KEY in env.',
          default: 'local',
        },
      },
    },
  },
  {
    name: 'pw_list_templates',
    description: 'List all ProcessWire templates with field counts and page counts',
    inputSchema: {
      type: 'object' as const,
      properties: {},
    },
  },
  {
    name: 'pw_get_template',
    description: 'Get detailed information about a specific template including fields, family settings, and access rules',
    inputSchema: {
      type: 'object' as const,
      properties: {
        name: {
          type: 'string',
          description: 'Template name',
        },
      },
      required: ['name'],
    },
  },
  {
    name: 'pw_list_fields',
    description: 'List all ProcessWire fields with their types. Use includeUsage=true to see which templates use each field.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        includeUsage: {
          type: 'boolean',
          description: 'Include list of templates that use each field (slower on large sites)',
          default: false,
        },
      },
    },
  },
  {
    name: 'pw_get_field',
    description: 'Get detailed information about a specific field including type, settings, and which templates use it',
    inputSchema: {
      type: 'object' as const,
      properties: {
        name: {
          type: 'string',
          description: 'Field name',
        },
      },
      required: ['name'],
    },
  },
  {
    name: 'pw_get_page',
    description: 'Get a ProcessWire page by ID or path, including all field values. Use truncate to limit text size, or summary for structure only.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        idOrPath: {
          type: 'string',
          description: 'Page ID (number) or path (e.g., "/about/" or "/blog/my-post/")',
        },
        includeFiles: {
          type: 'boolean',
          description: 'Include file/image metadata (filename, URL, dimensions)',
          default: false,
        },
        truncate: {
          type: 'number',
          description: 'Truncate text fields to N characters (0 = no truncation)',
          default: 0,
        },
        summary: {
          type: 'boolean',
          description: 'Return field structure only (types and labels), no content values',
          default: false,
        },
      },
      required: ['idOrPath'],
    },
  },
  {
    name: 'pw_query_pages',
    description: 'Query ProcessWire pages using a selector string (e.g., "template=blog-post, limit=10")',
    inputSchema: {
      type: 'object' as const,
      properties: {
        selector: {
          type: 'string',
          description: 'ProcessWire selector string (e.g., "template=blog-post, parent=/blog/, sort=-created")',
        },
      },
      required: ['selector'],
    },
  },
  {
    name: 'pw_export_schema',
    description: 'Export the complete ProcessWire site schema (all fields and templates) as JSON',
    inputSchema: {
      type: 'object' as const,
      properties: {
        format: {
          type: 'string',
          enum: ['json', 'yaml'],
          description: 'Output format (default: json)',
          default: 'json',
        },
      },
    },
  },
  {
    name: 'pw_search',
    description: 'Search page content across all text fields (title, body, summary, etc.). Returns matching pages with snippets.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        query: {
          type: 'string',
          description: 'Search term to find in page content',
        },
        limit: {
          type: 'number',
          description: 'Maximum results to return (default: 20)',
          default: 20,
        },
      },
      required: ['query'],
    },
  },
  {
    name: 'pw_search_files',
    description: 'Search for files and images by filename, extension (e.g., ".pdf"), or description',
    inputSchema: {
      type: 'object' as const,
      properties: {
        query: {
          type: 'string',
          description: 'Filename pattern, extension (e.g., ".pdf", ".jpg"), or description text',
        },
        limit: {
          type: 'number',
          description: 'Maximum results to return (default: 20)',
          default: 20,
        },
      },
      required: ['query'],
    },
  },
  // ========================================================================
  // SYNC TOOLS (Phase 2)
  // ========================================================================
  {
    name: 'pw_page_pull',
    description: 'Pull a ProcessWire page into the local sync directory (site/assets/pw-mcp/) as editable YAML. Creates page.meta.json (identity) and page.yaml (editable content). Pass source="remote" to fetch a page from production over HTTP and mirror it into the local sync tree (useful when an edit was made directly in the production admin and needs to come back to local).',
    inputSchema: {
      type: 'object' as const,
      properties: {
        pageIdOrPath: {
          type: 'string',
          description: 'Page ID (number) or path (e.g., "/about/" or "/services/web-design/")',
        },
        source: {
          type: 'string',
          enum: ['local', 'remote'],
          description: 'Where to pull the page from. "local" (default) reads from the local PW database. "remote" calls page:export-yaml on the remote API and writes the result into the local sync tree. Requires PW_REMOTE_URL + PW_REMOTE_KEY in env.',
          default: 'local',
        },
      },
      required: ['pageIdOrPath'],
    },
  },
  {
    name: 'pw_page_push',
    description: 'Push local changes from sync directory back to ProcessWire. Shows preview by default (dry-run). Set dryRun=false to apply. Use targets to control where changes are pushed: "local" (MAMP/dev), "remote" (production), or "both".',
    inputSchema: {
      type: 'object' as const,
      properties: {
        localPath: {
          type: 'string',
          description: 'Path to local sync directory or page.yaml file (e.g., "site/assets/pw-mcp/services/mobile-website-design")',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), show what would change without applying. Set to false to apply changes.',
          default: true,
        },
        force: {
          type: 'boolean',
          description: 'Force push even if remote page has changed since last pull',
          default: false,
        },
        targets: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Where to push: "local" (MAMP dev site), "remote" (production via HTTP API), or "both". Defaults to "local".',
          default: 'local',
        },
        publish: {
          type: 'boolean',
          description: 'Also publish the page (remove unpublished status) when pushing. Useful for making a draft live.',
          default: false,
        },
      },
      required: ['localPath'],
    },
  },
  {
    name: 'pw_page_assets',
    description: 'Synchronise the on-disk asset directory for a page (site/assets/files/{pageId}/) between local and remote. Catches both standard FieldtypeFile/FieldtypeImage uploads AND module-managed files (e.g. MediaHub) that live in the same per-page directory but are not exposed via fieldgroup iteration. Supports both directions (local-to-remote and remote-to-local). Dry-run by default. PW image variations (name.WxH[-suffix].ext) are filtered by default. PAGE ID DRIFT IS HANDLED: pages are matched by canonical PW path, and each side resolves its own pageId before walking its own site/assets/files/{pageId}/ directory. Local 1234 and remote 5678 may both legitimately resolve to /about/ — this is normal for two sites that started from independent fresh installs rather than a DB clone. The result payload reports localPageId, remotePageId, and an idDrift boolean so operators can see at a glance which physical disk directory was read or written on each environment.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        action: {
          type: 'string',
          enum: ['inventory', 'compare', 'push', 'pull'],
          description: '"inventory" lists assets for one page on the chosen side. "compare" diffs both sides for one page. "push" syncs local→remote. "pull" syncs remote→local.',
        },
        pageRef: {
          type: 'string',
          description: 'Page id or PW path (e.g. "/about/"). Required for all actions except site-wide compare. Note: numeric ids are resolved on the LOCAL site first, then translated to the corresponding remote page via canonical path — so a numeric id never accidentally addresses the wrong remote page when local/remote auto-increment sequences have diverged. If you only know the remote id, look up its path with pw_get_page first.',
        },
        site: {
          type: 'string',
          enum: ['local', 'remote'],
          description: 'For action="inventory" only — which side to read. Defaults to "local".',
          default: 'local',
        },
        dryRun: {
          type: 'boolean',
          description: 'For push/pull: if true (default), preview transfers without writing. Set to false to apply.',
          default: true,
        },
        deleteOrphans: {
          type: 'boolean',
          description: 'For push/pull: also delete files on the destination that have no counterpart on the source. Default: false (safe).',
          default: false,
        },
        includeVariations: {
          type: 'boolean',
          description: 'Include PW image variations (name.WxH[-suffix].ext) in inventory/sync. Default: false (variations are regenerated on demand and produce noisy diffs).',
          default: false,
        },
      },
      required: ['action'],
    },
  },
  {
    name: 'pw_file_sync',
    description: 'Sync file/image field content from local to remote. Compares file inventories by MD5 hash and transfers only new or changed files. Dry-run by default.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        localPath: {
          type: 'string',
          description: 'Path to local sync directory or page.yaml file',
        },
        targets: {
          type: 'string',
          enum: ['remote'],
          description: 'Where to sync files to. Currently only "remote" is supported.',
          default: 'remote',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), show what would be transferred without uploading. Set to false to apply.',
          default: true,
        },
        deleteRemoteOrphans: {
          type: 'boolean',
          description: 'If true, delete files on remote that no longer exist locally. Default: false (safe).',
          default: false,
        },
      },
      required: ['localPath'],
    },
  },
  {
    name: 'pw_pages_pull',
    description: 'Bulk pull multiple pages by selector, parent path, or template. Creates sync files for each matched page.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        selector: {
          type: 'string',
          description: 'ProcessWire selector (e.g., "template=blog-post"), parent path (e.g., "/services/"), or template name',
        },
        limit: {
          type: 'number',
          description: 'Maximum pages to pull (default: 50)',
          default: 50,
        },
        noParent: {
          type: 'boolean',
          description: 'Exclude parent page when pulling by parent path',
          default: false,
        },
      },
      required: ['selector'],
    },
  },
  {
    name: 'pw_pages_push',
    description: 'Bulk push every page.yaml under a sync directory tree to local PW, remote PW, or both. Pages are pushed serially in parent-first order so newly-created parents exist before their children. Dry-run by default. Set dryRun=false to apply.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        directory: {
          type: 'string',
          description: 'Sync directory to push (e.g., "site/assets/pw-mcp/services" or "site/assets/pw-mcp" for everything).',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview changes without applying. Set to false to apply.',
          default: true,
        },
        force: {
          type: 'boolean',
          description: 'Force push even if the target page has changed since last pull (dangerous).',
          default: false,
        },
        targets: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Where to push. Defaults to "local" (preserves v1.7.x behaviour, uses the PHP CLI). "remote" and "both" walk the tree in TS and call pushPage per page so each push gets full path-based page lookup + _pageRef resolution.',
          default: 'local',
        },
        publish: {
          type: 'boolean',
          description: 'Also publish each page (remove unpublished status). Only applies to targets "remote"/"both". Useful for taking a batch of drafts live.',
          default: false,
        },
      },
      required: ['directory'],
    },
  },
  {
    name: 'pw_sync_status',
    description: 'Check sync status of pulled pages. Shows which have local changes, remote changes, or conflicts.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        directory: {
          type: 'string',
          description: 'Sync directory to check (default: site/syncs)',
        },
      },
    },
  },
  {
    name: 'pw_sync_reconcile',
    description: 'Reconcile local sync directories with ProcessWire. Detects path drift (page moved/renamed) and orphans (page deleted). Fixes issues when dryRun=false.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        directory: {
          type: 'string',
          description: 'Sync directory to reconcile (default: site/syncs)',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview changes without applying. Set to false to fix issues.',
          default: true,
        },
      },
    },
  },
  // ========================================================================
  // PAGE REFERENCE VALIDATION
  // ========================================================================
  {
    name: 'pw_validate_refs',
    description: 'Scan all locally synced pages for page reference fields (_pageRef) and verify the referenced pages exist on the target environment. Catches broken links before a push. Returns ok/unpublished/missing status per reference.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        target: {
          type: 'string',
          enum: ['local', 'remote'],
          description: 'Which environment to validate against: "local" (MAMP/dev) or "remote" (production). Defaults to "local" when PW_PATH is set, otherwise "remote" if PW_REMOTE_URL is set.',
        },
        syncRoot: {
          type: 'string',
          description: 'Path to the sync directory to scan. Defaults to site/assets/pw-mcp under PW_PATH.',
        },
      },
    },
  },
  // ========================================================================
  // PHASE 3: PAGE CREATION & PUBLISHING
  // ========================================================================
  {
    name: 'pw_page_new',
    description: 'Create a new page scaffold locally. Generates page.yaml and page.meta.json that can be edited and then published.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        template: {
          type: 'string',
          description: 'ProcessWire template name for the new page',
        },
        parentPath: {
          type: 'string',
          description: 'Parent page path (e.g., "/services/")',
        },
        pageName: {
          type: 'string',
          description: 'URL-safe page name/slug (e.g., "new-service")',
        },
        title: {
          type: 'string',
          description: 'Optional page title (defaults to titlecase of pageName)',
        },
      },
      required: ['template', 'parentPath', 'pageName'],
    },
  },
  {
    name: 'pw_page_init',
    description: 'Initialise or repair page.meta.json for a sync directory. If the page exists in ProcessWire, links to it (for pw_page_push). If not, creates a new-page scaffold (for pw_page_publish). Useful when content files were created manually without using pw_page_new.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        localPath: {
          type: 'string',
          description: 'Path to local sync directory (e.g., "site/assets/pw-mcp/downloads/mediahub/v1-8-1")',
        },
        template: {
          type: 'string',
          description: 'Template name (required only for new pages when the parent allows multiple child templates)',
        },
      },
      required: ['localPath'],
    },
  },
  {
    name: 'pw_page_publish',
    description: 'Publish a new page scaffold (created with pw_page_new) to ProcessWire. Automatically generates page.meta.json from page.yaml if missing (when the parent allows only one child template). Use targets to control where: "local" (MAMP), "remote" (production), or "both".',
    inputSchema: {
      type: 'object' as const,
      properties: {
        localPath: {
          type: 'string',
          description: 'Path to local page directory or page.yaml file',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview what would be created without actually creating. Set to false to create.',
          default: true,
        },
        published: {
          type: 'boolean',
          description: 'Create as published (default: false, creates as unpublished draft)',
          default: false,
        },
        targets: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Where to create the page: "local" (MAMP dev), "remote" (production), or "both". Defaults to "local".',
          default: 'local',
        },
      },
      required: ['localPath'],
    },
  },
  {
    name: 'pw_pages_publish',
    description: 'Bulk publish all new pages in a directory. Only publishes pages marked new: true.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        directory: {
          type: 'string',
          description: 'Directory to scan for new pages (default: site/syncs)',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview what would be created. Set to false to create.',
          default: true,
        },
        published: {
          type: 'boolean',
          description: 'Create pages as published (default: false, creates as unpublished)',
          default: false,
        },
      },
    },
  },
  // ========================================================================
  // SCHEMA SYNC TOOLS (Phase 2)
  // ========================================================================
  {
    name: 'pw_schema_pull',
    description: 'Pull the full field and template schema from a ProcessWire site into local .pw-sync/schema/ files. Works for both local and remote sites. Run this first before schema:diff or schema:push.',
    inputSchema: {
      type: 'object' as const,
      properties: {},
    },
  },
  {
    name: 'pw_schema_push',
    description: 'Push local .pw-sync/schema/ files to a ProcessWire site — creating or updating fields and templates. Dry-run by default. Set dryRun=false to apply. Never deletes existing fields or templates.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview what would change without applying. Set to false to apply.',
          default: true,
        },
      },
    },
  },
  {
    name: 'pw_schema_diff',
    description: 'Compare local .pw-sync/schema/ files against the live ProcessWire site. Shows which fields/templates are local-only (would be added), live-only, changed, or in sync.',
    inputSchema: {
      type: 'object' as const,
      properties: {},
    },
  },
  {
    name: 'pw_schema_compare',
    description: 'Directly compare schemas between two sites with full collision classification. Each difference is marked safe/warning/danger. Use this to understand exactly what would change before pushing. Source defaults to current site, target defaults to "production".',
    inputSchema: {
      type: 'object' as const,
      properties: {
        source: {
          type: 'string',
          description: 'Source site: "current" (this connection), "local", or a named site from .pw-sync/sites/ (e.g. "production", "staging")',
          default: 'current',
        },
        target: {
          type: 'string',
          description: 'Target site to compare against: "current", "local", or a named site (e.g. "production", "staging")',
          default: 'production',
        },
      },
    },
  },
  {
    name: 'pw_list_sites',
    description: 'List all configured remote sites from .pw-sync/sites/. These are the site names you can use with pw_schema_compare.',
    inputSchema: {
      type: 'object' as const,
      properties: {},
    },
  },
  // ========================================================================
  // PHASE 4: DIRECT WRITE TOOLS
  // ========================================================================
  // ========================================================================
  // PHASE 5: DATABASE, LOGS & CACHE
  // ========================================================================
  {
    name: 'pw_db_schema',
    description: 'Inspect the database schema. Without arguments lists all tables with engine, row counts, and size. Pass a table name for detailed columns, types, keys, and indexes. Pass site="remote" to inspect production.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        table: {
          type: 'string',
          description: 'Optional table name for detailed column/index info. Omit to list all tables.',
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local".',
          default: 'local',
        },
      },
    },
  },
  {
    name: 'pw_db_query',
    description: 'Execute a read-only SELECT query against the database. Only SELECT, SHOW, and DESCRIBE statements are allowed — mutations are blocked. A LIMIT is auto-injected if not present. Pass site="remote" to query production.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        sql: {
          type: 'string',
          description: 'SQL query to execute (SELECT only)',
        },
        limit: {
          type: 'number',
          description: 'Maximum rows to return (default: 100)',
          default: 100,
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local". Prior to v1.8.1 the --site flag was silently ignored — use this `site` arg.',
          default: 'local',
        },
      },
      required: ['sql'],
    },
  },
  {
    name: 'pw_db_explain',
    description: 'Run EXPLAIN on a SELECT query to show the execution plan. Useful for diagnosing slow queries and missing indexes. Pass site="remote" to explain against production.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        sql: {
          type: 'string',
          description: 'SELECT query to explain',
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local".',
          default: 'local',
        },
      },
      required: ['sql'],
    },
  },
  {
    name: 'pw_db_counts',
    description: 'Get row counts for core ProcessWire tables (pages, fields, templates, etc.) and the 20 largest field data tables. Quick overview of data volume. Pass site="both" to compare local vs remote.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local".',
          default: 'local',
        },
      },
    },
  },
  {
    name: 'pw_logs',
    description: 'Read ProcessWire log entries. Without a log name, lists available log files with sizes. With a name (e.g. "errors", "messages", "exceptions"), returns entries filtered by level and text pattern. Pass site="remote" to read production logs.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        logName: {
          type: 'string',
          description: 'Log file name (e.g. "errors", "messages", "exceptions"). Omit to list available logs.',
        },
        level: {
          type: 'string',
          description: 'Filter by level: error, warning, or info',
        },
        text: {
          type: 'string',
          description: 'Filter entries containing this text pattern',
        },
        limit: {
          type: 'number',
          description: 'Maximum entries to return (default: 50)',
          default: 50,
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local".',
          default: 'local',
        },
      },
    },
  },
  {
    name: 'pw_last_error',
    description: 'Get the most recent error from ProcessWire error and exception logs. Quick shortcut to see what went wrong without digging through log files. Pass site="remote" for the latest production error.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local".',
          default: 'local',
        },
      },
    },
  },
  {
    name: 'pw_clear_cache',
    description: 'Clear ProcessWire caches. Targets: "all" (everything), "modules" (module registry), "templates" (compiled template files), "compiled" (all compiled caches), "wire-cache" (database-backed WireCache). Pass site="remote" to clear caches on production after a file push.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        target: {
          type: 'string',
          enum: ['all', 'modules', 'templates', 'compiled', 'wire-cache'],
          description: 'What to clear (default: "all")',
          default: 'all',
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to clear caches on. Defaults to "local". Use "remote" after pushing module files to production.',
          default: 'local',
        },
      },
    },
  },
  // ========================================================================
  // PHASE 4: DIRECT WRITE TOOLS
  // ========================================================================
  {
    name: 'pw_matrix_info',
    description: 'Get detailed structure of a matrix/repeater field. Shows all matrix types, their fields, and any nested repeaters. Use this before adding content to understand the field structure.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        pageIdOrPath: {
          type: 'string',
          description: 'Page ID (number) or path (e.g., "/about/")',
        },
        fieldName: {
          type: 'string',
          description: 'Name of the matrix/repeater field to inspect',
        },
      },
      required: ['pageIdOrPath', 'fieldName'],
    },
  },
  {
    name: 'pw_matrix_add',
    description: 'Add a new matrix/repeater item to a page. Supports adding FAQs, body blocks, CTAs, and other matrix types directly without the YAML sync workflow.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        pageIdOrPath: {
          type: 'string',
          description: 'Page ID (number) or path (e.g., "/about/" or "/services/web-design/")',
        },
        fieldName: {
          type: 'string',
          description: 'Name of the matrix/repeater field (e.g., "matrix")',
        },
        matrixType: {
          type: 'string',
          description: 'Matrix type name (e.g., "faq", "body", "cta", "breakout")',
        },
        content: {
          type: 'object',
          description: 'Field values for the new item as key-value pairs (e.g., {"question": "What is...?", "answer": "It is..."})',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview what would be created. Set to false to create the item.',
          default: true,
        },
      },
      required: ['pageIdOrPath', 'fieldName', 'matrixType', 'content'],
    },
  },
  // ========================================================================
  // PHASE 5: SITE SYNC
  // ========================================================================
  {
    name: 'pw_maintenance',
    description: 'Toggle maintenance mode on a ProcessWire site. When enabled, front-end visitors see a maintenance page (503). Superusers and the PromptWire API are unaffected. Use before deploying changes to production.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        action: {
          type: 'string',
          enum: ['on', 'off', 'status'],
          description: 'Action: "on" to enable, "off" to disable, "status" to check current state.',
        },
        message: {
          type: 'string',
          description: 'Optional custom message when enabling maintenance mode.',
        },
        targets: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Where to toggle maintenance mode. Defaults to "local".',
          default: 'local',
        },
      },
      required: ['action'],
    },
  },
  {
    name: 'pw_backup',
    description: 'Create, list, restore, or delete site backups. Uses ProcessWire\'s built-in database backup engine for SQL dumps and creates a zip of site/templates and site/modules for file backups. Always create a backup before running destructive sync operations.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        action: {
          type: 'string',
          enum: ['create', 'list', 'restore', 'delete'],
          description: 'Action to perform.',
        },
        description: {
          type: 'string',
          description: 'Label for the backup (used with "create").',
        },
        filename: {
          type: 'string',
          description: 'Backup filename or timestamp (used with "restore" and "delete").',
        },
        excludeTables: {
          type: 'string',
          description: 'Comma-separated table names to exclude from DB backup (used with "create").',
        },
        includeFiles: {
          type: 'boolean',
          description: 'Whether to include a file backup zip alongside the DB dump. Defaults to true.',
          default: true,
        },
        targets: {
          type: 'string',
          enum: ['local', 'remote'],
          description: 'Where to run the backup. Defaults to "local".',
          default: 'local',
        },
      },
      required: ['action'],
    },
  },
  // ========================================================================
  // v1.9.0 — READ-ONLY DIAGNOSTIC TOOLS
  // ========================================================================
  // All four are site-aware via runOnSite() and additive (no existing tool
  // signatures change). They feed v1.10+ writeable tools (template fieldgroup
  // pushes, user sync) by giving the operator one round-trip to inspect
  // module install state, resolve names → ids on either side, or compare a
  // single template across local/remote before committing to a push.
  {
    name: 'pw_modules_list',
    description: 'List ProcessWire modules with install state, file path, and version. Defaults to every installed module; pass classes=[...] to inspect specific module classes (installed or not — useful for "is FormBuilder present on prod?" checks). Use site="both" to compare local vs remote install state side-by-side.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        classes: {
          type: 'array',
          items: { type: 'string' },
          description: 'Optional list of module class names to inspect (e.g. ["FormBuilder", "SeoNeo"]). Omit to list every installed module.',
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local".',
          default: 'local',
        },
      },
    },
  },
  {
    name: 'pw_users_list',
    description: 'List ProcessWire users with id, name, email, roles, and member_* fields. Pass includeAll=true to widen the projection to every non-system field on the user template. Pass site="remote" to inspect production users (e.g. verify the manually-pushed users from a migration are present).',
    inputSchema: {
      type: 'object' as const,
      properties: {
        includeAll: {
          type: 'boolean',
          description: 'Include every non-system field on the user template, not just member_* fields. Defaults to false.',
          default: false,
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to query. Defaults to "local".',
          default: 'local',
        },
      },
    },
  },
  {
    name: 'pw_resolve',
    description: 'Bulk-resolve names to ProcessWire ids on the chosen site. Returns a {name → id|null} mapping plus a list of names that were not found. Used before a push to translate local field/template names into the equivalent ids on the remote, without one HTTP round-trip per name.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        type: {
          type: 'string',
          enum: ['field', 'template', 'page', 'role', 'permission', 'user', 'module'],
          description: 'Type of object to resolve. "page" looks up by path (e.g. "/about/"); the others by name.',
        },
        names: {
          type: 'array',
          items: { type: 'string' },
          description: 'Names (or paths, for type="page") to resolve.',
          minItems: 1,
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to resolve against. Defaults to "local". Use "remote" before a push to learn the production ids.',
          default: 'local',
        },
      },
      required: ['type', 'names'],
    },
  },
  {
    name: 'pw_inspect_template',
    description: 'Inspect a single template with rich field info — each field returned as {name, type, label} rather than just a name. Companion to pw_get_template, sized for fieldgroup-diff workflows. Use site="both" to see exactly which fields differ between local and remote before planning a v1.10 fieldgroup push.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        name: {
          type: 'string',
          description: 'Template name (e.g. "blog_post").',
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description: 'Which site to inspect. Defaults to "local".',
          default: 'local',
        },
      },
      required: ['name'],
    },
  },
  // ========================================================================
  // v1.11.0 (WIP) — FIELDGROUP EDITS
  // ========================================================================
  // Additive edits to a template's fieldgroup (add/remove/reorder existing
  // fields, plus per-fieldgroup context overrides). Explicitly NOT a
  // field-definition editor — changing a field's fieldtype, parent picker,
  // inputfield class, etc. is pw_field_push territory (v1.12+). This tool
  // plans the change, classifies conflicts into {safe, warning, danger},
  // and surfaces definition-level completeness warnings for the added
  // fields so AI callers get told about the classic "Page reference with
  // no parent picker" / "Textarea with no editor class" gotchas at plan
  // time rather than in the admin UI after the push.
  //
  // Phase 2b: dry-run only. Write path ships in Phase 3.
  {
    name: 'pw_template_fields_push',
    description:
      'Plan (and eventually apply) additive edits to a template\'s fieldgroup: add existing fields, remove fields, reorder, and set per-fieldgroup context overrides (required, columnWidth, showIf, label override, etc.). Explicitly does NOT create fields or change field definitions — for that, wait for pw_field_push in v1.12+. ' +
      '\n\n' +
      'WHAT THIS TOOL CHECKS:\n' +
      '- Add/remove/reorder ops against the current fieldgroup state on the target site.\n' +
      '- Per-field context keys against an allow-list (unknown keys → warning; FieldtypePage-only keys on non-Page fields → danger).\n' +
      '- Fieldset pair integrity — FieldsetOpen/TabOpen/Group without matching {name}_END close → danger; close without opener → danger; wrong order → danger.\n' +
      '- Field-definition completeness warnings for added fields: Page refs with no template_id/parent_id/findPagesSelector; Textareas with no inputfieldClass; File/Image fields with no extensions; Repeaters with no repeater-pages config. Warnings, not blockers — the add can still proceed.\n' +
      '\n' +
      'The `add` array accepts either bare strings (field names) or {name, context} objects for per-fieldgroup overrides. Mixed in the same array is fine. Write path is not yet implemented — set dryRun=true (default) to get the plan; dryRun=false currently returns a clear "not yet implemented" error.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        template: {
          type: 'string',
          description: 'Template name whose fieldgroup to edit (e.g. "blog_post").',
        },
        add: {
          type: 'array',
          description:
            'Fields to add to the fieldgroup. Each entry is either a string (field name) or {name: string, context?: {label?, description?, notes?, required?, columnWidth?, showIf?, requiredIf?, collapsed?, template_id?, parent_id?, findPagesSelector?, inputfield?}}. Context keys in the second group are FieldtypePage-only and surface as danger on non-Page fields. Fields must already exist on the target site — use pw_resolve to confirm before adding.',
          items: {},
        },
        remove: {
          type: 'array',
          items: { type: 'string' },
          description:
            'Field names to remove from the fieldgroup. Removing a field flagged required on the template is a danger unless force=true. Removing a fieldset opener without its matching close (or vice versa) is a danger regardless of force.',
        },
        reorder: {
          type: 'array',
          items: { type: 'string' },
          description:
            'Desired full fieldgroup order after add/remove is applied. Fields not listed are appended in their current relative order. Names not on the projected post-push fieldgroup are a danger — drop them from reorder, or also include them in add.',
        },
        site: {
          type: 'string',
          enum: ['local', 'remote', 'both'],
          description:
            'Which site to plan against. Defaults to "local". Use "both" to see the plan from each side independently — useful when fieldgroups have drifted between environments. (Cross-site fieldtype-drift detection is a Phase 2c / v1.11 refinement.)',
          default: 'local',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), returns the plan without applying. Phase 2b: dryRun=false currently returns a "not yet implemented" error — write path ships in Phase 3.',
          default: true,
        },
        force: {
          type: 'boolean',
          description: 'If true, bypasses danger-class conflicts on write. Has no effect in dry-run. Defaults to false.',
          default: false,
        },
      },
      required: ['template'],
    },
  },
  {
    name: 'pw_site_compare',
    description: 'Compare local and remote ProcessWire sites across pages, schema, template/module files, and per-page on-disk assets (site/assets/files/{pageId}/). Pages are matched by path (not ID). Returns a structured report of what differs, what exists only on one side, and what is identical. The page-assets section catches both standard file/image field uploads AND module-managed files (e.g. MediaHub) that field iteration would miss. Requires both PW_PATH (local) and PW_REMOTE_URL (remote).',
    inputSchema: {
      type: 'object' as const,
      properties: {
        excludeTemplates: {
          type: 'array',
          items: { type: 'string' },
          description: 'Template names to exclude from comparison (supports wildcards e.g. "license_*"). Defaults to ["user", "role", "permission", "admin"].',
        },
        excludePages: {
          type: 'array',
          items: { type: 'string' },
          description: 'Specific page paths to exclude from comparison (e.g. ["/trash/"]).',
        },
        includeDirs: {
          type: 'array',
          items: { type: 'string' },
          description: 'Directories to compare for file sync, relative to PW root. Defaults to ["site/templates", "site/modules"].',
        },
        excludeFilePatterns: {
          type: 'array',
          items: { type: 'string' },
          description: 'Glob patterns to skip in file comparison (e.g. ["site/modules/PromptWire/*"]). Defaults to ["site/modules/PromptWire/*"].',
        },
        includePageAssets: {
          type: 'boolean',
          description: 'Include the per-page on-disk assets diff (site/assets/files/{pageId}/) in the report. Defaults to true. Set false to skip the extra inventory call when you only need the pages/schema/files sections.',
          default: true,
        },
      },
    },
  },
  {
    name: 'pw_site_sync',
    description: 'Synchronise local and remote ProcessWire sites. Runs a comparison first, then selectively pushes schema, pages, page assets (site/assets/files/{pageId}/), and/or template/module files. Page assets cover both standard file/image field uploads and module-managed files (e.g. MediaHub) keyed by page id. Supports optional backup and maintenance mode. Dry-run by default — set dryRun=false to apply. If a step fails, maintenance mode stays ON and the backup ID is reported for rollback.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        direction: {
          type: 'string',
          enum: ['local-to-remote', 'remote-to-local'],
          description: 'Sync direction. Defaults to "local-to-remote".',
          default: 'local-to-remote',
        },
        scope: {
          type: 'string',
          enum: ['all', 'pages', 'schema', 'files'],
          description: 'What to sync. Defaults to "all".',
          default: 'all',
        },
        excludeTemplates: {
          type: 'array',
          items: { type: 'string' },
          description: 'Template names to exclude (supports wildcards). Defaults to ["user", "role", "permission", "admin"].',
        },
        excludePages: {
          type: 'array',
          items: { type: 'string' },
          description: 'Specific page paths to exclude.',
        },
        excludeFilePatterns: {
          type: 'array',
          items: { type: 'string' },
          description: 'Glob patterns to skip in file sync.',
        },
        backup: {
          type: 'boolean',
          description: 'Create a backup on the target before writing. Defaults to true.',
          default: true,
        },
        maintenance: {
          type: 'boolean',
          description: 'Enable maintenance mode on the target during sync. Defaults to false.',
          default: false,
        },
        dryRun: {
          type: 'boolean',
          description: 'Preview what would be synced without making changes. Defaults to true.',
          default: true,
        },
      },
    },
  },
];

// ============================================================================
// MCP SERVER SETUP
// ============================================================================

/**
 * Create the MCP server instance
 * 
 * The server is configured with:
 * - Name and version for identification
 * - Tools capability to expose our ProcessWire tools
 */
const server = new Server(
  {
    name: 'promptwire',
    version: '1.10.2',
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

// ============================================================================
// REQUEST HANDLERS
// ============================================================================

/**
 * Handle ListTools request
 * 
 * Returns the list of available tools to Cursor. This is called when
 * Cursor needs to know what tools are available from this server.
 */
server.setRequestHandler(ListToolsRequestSchema, async () => {
  return { tools };
});

/**
 * Handle CallTool request
 * 
 * Routes tool calls to the appropriate CLI command and returns the result.
 * Each tool maps to a specific CLI command with appropriate arguments.
 */
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  switch (name) {
    // Health check - verify connection and get site info
    case 'pw_health': {
      const { site } = args as { site?: Site };
      const result = await runOnSite(site, 'health');
      return formatToolResponse(result);
    }

    // List all templates
    case 'pw_list_templates': {
      const result = await runPwCommand('list-templates');
      return formatToolResponse(result);
    }

    // Get template details
    case 'pw_get_template': {
      const templateName = (args as { name: string }).name;
      const result = await runPwCommand('get-template', [templateName]);
      return formatToolResponse(result);
    }

    // List all fields (optionally with usage info)
    case 'pw_list_fields': {
      const includeUsage = (args as { includeUsage?: boolean }).includeUsage;
      const cmdArgs = includeUsage ? ['--include=usage'] : [];
      const result = await runPwCommand('list-fields', cmdArgs);
      return formatToolResponse(result);
    }

    // Get field details
    case 'pw_get_field': {
      const fieldName = (args as { name: string }).name;
      const result = await runPwCommand('get-field', [fieldName]);
      return formatToolResponse(result);
    }

    // Get page by ID or path
    case 'pw_get_page': {
      const { idOrPath, includeFiles, truncate, summary } = args as {
        idOrPath: string;
        includeFiles?: boolean;
        truncate?: number;
        summary?: boolean;
      };
      const cmdArgs = [idOrPath];
      if (includeFiles) {
        cmdArgs.push('--include=files');
      }
      if (truncate && truncate > 0) {
        cmdArgs.push(`--truncate=${truncate}`);
      }
      if (summary) {
        cmdArgs.push('--summary');
      }
      const result = await runPwCommand('get-page', cmdArgs);
      return formatToolResponse(result);
    }

    // Query pages with selector
    case 'pw_query_pages': {
      const selector = (args as { selector: string }).selector;
      const result = await runPwCommand('query-pages', [selector]);
      return formatToolResponse(result);
    }

    // Export full schema
    case 'pw_export_schema': {
      const format = (args as { format?: string }).format || 'json';
      const cmdArgs = format === 'yaml' ? ['--format=yaml'] : [];
      const result = await runPwCommand('export-schema', cmdArgs);
      return formatToolResponse(result);
    }

    // Search page content
    case 'pw_search': {
      const { query, limit } = args as { query: string; limit?: number };
      const cmdArgs = [query];
      if (limit) {
        cmdArgs.push(`--limit=${limit}`);
      }
      const result = await runPwCommand('search', cmdArgs);
      return formatToolResponse(result);
    }

    // Search files/images
    case 'pw_search_files': {
      const { query, limit } = args as { query: string; limit?: number };
      const cmdArgs = [query];
      if (limit) {
        cmdArgs.push(`--limit=${limit}`);
      }
      const result = await runPwCommand('search-files', cmdArgs);
      return formatToolResponse(result);
    }

    // ========================================================================
    // SYNC TOOLS
    // ========================================================================

    // Pull page to local sync directory (from local PW or from remote PW)
    case 'pw_page_pull': {
      const { pageIdOrPath, source } = args as {
        pageIdOrPath: string;
        source?: 'local' | 'remote';
      };

      // Default to local for full backward compatibility with v1.7.x callers.
      if (!source || source === 'local') {
        const result = await runPwCommand('page:pull', [pageIdOrPath]);
        return formatToolResponse(result);
      }

      // Remote source: fetch via page:export-yaml on the remote API and
      // write the inline payload into the local sync tree. PW_PATH is
      // required so we know where to write.
      const result = await pullPageFromRemote({ idOrPath: pageIdOrPath });
      return formatToolResponse(result);
    }

    // Push local changes to ProcessWire (local, remote, or both)
    case 'pw_page_push': {
      const { localPath, dryRun, force, targets, publish } = args as {
        localPath: string;
        dryRun?: boolean;
        force?: boolean;
        targets?: 'local' | 'remote' | 'both';
        publish?: boolean;
      };

      const resolvedTargets = targets ?? 'local';

      const result = await pushPage({
        localPath,
        dryRun:  dryRun !== false,
        force:   force ?? false,
        targets: resolvedTargets,
        publish: publish ?? false,
      });
      return formatToolResponse(result);
    }

    // Sync on-disk page assets (site/assets/files/{pageId}/) — catches both
    // standard file/image field uploads AND module-managed files (MediaHub
    // etc.) that field iteration would miss. Supports both directions.
    case 'pw_page_assets': {
      const {
        action,
        pageRef,
        site,
        dryRun,
        deleteOrphans,
        includeVariations,
      } = args as {
        action: 'inventory' | 'compare' | 'push' | 'pull';
        pageRef?: string;
        site?: 'local' | 'remote';
        dryRun?: boolean;
        deleteOrphans?: boolean;
        includeVariations?: boolean;
      };

      const wantVariations = includeVariations === true;

      if (action === 'inventory') {
        if (!pageRef) {
          return formatToolResponse({ success: false, error: 'pw_page_assets action="inventory" requires pageRef' });
        }
        const cmdArgs = [pageRef];
        if (wantVariations) cmdArgs.push('--include-variations');
        const result = await runOnSite(site ?? 'local', 'page-assets:inventory', cmdArgs);
        return formatToolResponse(result);
      }

      if (action === 'compare') {
        // Per-page compare uses page-assets:inventory on both sides and
        // diffs locally. Site-wide compare is exposed via pw_site_compare.
        if (!pageRef) {
          // No page ref → defer to the site-wide compare helper so callers
          // can sweep the full site in one call.
          const result = await compareSiteAssets({ includeVariations: wantVariations });
          return formatToolResponse(result);
        }
        const localArgs  = [pageRef];
        const remoteArgs = [pageRef];
        if (wantVariations) {
          localArgs.push('--include-variations');
          remoteArgs.push('--include-variations');
        }
        const [localResult, remoteResult] = await Promise.all([
          runPwCommand('page-assets:inventory', localArgs),
          (async () => {
            if (!process.env.PW_REMOTE_URL || !process.env.PW_REMOTE_KEY) {
              return { success: false, error: 'PW_REMOTE_URL/PW_REMOTE_KEY required for compare' };
            }
            const { runRemoteCommand } = await import('./remote/client.js');
            return runRemoteCommand('page-assets:inventory', remoteArgs);
          })(),
        ]);
        if (!localResult.success || !remoteResult.success) {
          return formatToolResponse({
            success: false,
            error: `Compare failed — local: ${localResult.error ?? 'ok'}, remote: ${remoteResult.error ?? 'ok'}`,
          });
        }
        const { diffAssets } = await import('./sync/page-assets.js');
        const localData  = localResult.data  as { pageId?: number; pagePath?: string; assets?: Array<{ relativePath: string; md5: string; size: number }> };
        const remoteData = remoteResult.data as { pageId?: number; pagePath?: string; assets?: Array<{ relativePath: string; md5: string; size: number }> };
        const diff = diffAssets(localData.assets ?? [], remoteData.assets ?? []);

        // Each side resolves its OWN pageId from the canonical PW path,
        // so two sites started from independent fresh installs (rather
        // than a DB clone) will legitimately have different ids for the
        // same page. Surface both ids and a single idDrift flag so the
        // operator can see immediately whether the diff is "different
        // page on each side" (would mean the path-resolution went wrong
        // somewhere) or "same page, different auto-increment history".
        const localPageId  = typeof localData.pageId  === 'number' ? localData.pageId  : undefined;
        const remotePageId = typeof remoteData.pageId === 'number' ? remoteData.pageId : undefined;
        const idDrift = localPageId !== undefined
          && remotePageId !== undefined
          && localPageId !== remotePageId;

        return formatToolResponse({
          success: true,
          data: {
            pagePath: localData.pagePath ?? remoteData.pagePath ?? pageRef,
            local:  { pageId: localPageId,  count: (localData.assets  ?? []).length },
            remote: { pageId: remotePageId, count: (remoteData.assets ?? []).length },
            idDrift,
            summary: {
              unchanged:  diff.unchanged,
              changed:    diff.changed.length,
              localOnly:  diff.localOnly.length,
              remoteOnly: diff.remoteOnly.length,
            },
            changed:    diff.changed,
            localOnly:  diff.localOnly.map(f => f.relativePath),
            remoteOnly: diff.remoteOnly.map(f => f.relativePath),
          },
        });
      }

      if (action === 'push' || action === 'pull') {
        if (!pageRef) {
          return formatToolResponse({ success: false, error: `pw_page_assets action="${action}" requires pageRef` });
        }
        const result = await syncPageAssets({
          pageRef,
          direction: action === 'push' ? 'local-to-remote' : 'remote-to-local',
          dryRun: dryRun !== false,
          deleteOrphans: deleteOrphans === true,
          includeVariations: wantVariations,
        });
        return formatToolResponse(result);
      }

      return formatToolResponse({ success: false, error: `Unknown pw_page_assets action: ${action}` });
    }

    // Sync file/image fields from local to remote
    case 'pw_file_sync': {
      const { localPath, targets, dryRun, deleteRemoteOrphans } = args as {
        localPath: string;
        targets?: 'remote';
        dryRun?: boolean;
        deleteRemoteOrphans?: boolean;
      };
      const result = await syncFiles({
        localPath,
        targets: targets ?? 'remote',
        dryRun: dryRun !== false,
        deleteRemoteOrphans: deleteRemoteOrphans ?? false,
      });
      return formatToolResponse(result);
    }

    // Bulk pull pages by selector, parent, or template
    case 'pw_pages_pull': {
      const { selector, limit, noParent } = args as {
        selector: string;
        limit?: number;
        noParent?: boolean;
      };
      const cmdArgs = [selector];
      if (limit) {
        cmdArgs.push(`--limit=${limit}`);
      }
      if (noParent) {
        cmdArgs.push('--no-parent');
      }
      const result = await runPwCommand('pages:pull', cmdArgs);
      return formatToolResponse(result);
    }

    // Bulk push all changes in a directory tree (local, remote, or both)
    case 'pw_pages_push': {
      const { directory, dryRun, force, targets, publish } = args as {
        directory: string;
        dryRun?: boolean;
        force?: boolean;
        targets?: 'local' | 'remote' | 'both';
        publish?: boolean;
      };

      const resolvedTargets = targets ?? 'local';
      const resolvedDryRun  = dryRun !== false;

      // Local-only path keeps the v1.7.x behaviour: hand off to the PHP CLI's
      // pages:push, which already walks the tree and handles dry-run/force.
      // Switching this to the TS walker would change report format and risk
      // regressions for existing local workflows.
      if (resolvedTargets === 'local') {
        const cmdArgs = [directory];
        if (!resolvedDryRun) cmdArgs.push('--dry-run=0');
        if (force) cmdArgs.push('--force');
        const result = await runPwCommand('pages:push', cmdArgs);
        return formatToolResponse(result);
      }

      // Remote and both go through the TS bulk pusher so each page benefits
      // from pushPage's path-based page lookup, _pageRef resolution, and
      // file-ref handling — no separate code path to drift.
      const result = await pushPagesBulk({
        directory,
        dryRun:  resolvedDryRun,
        force:   force ?? false,
        targets: resolvedTargets,
        publish: publish ?? false,
      });
      return formatToolResponse(result);
    }

    // Check sync status
    case 'pw_sync_status': {
      const { directory } = args as { directory?: string };
      const cmdArgs = directory ? [directory] : [];
      const result = await runPwCommand('sync:status', cmdArgs);
      return formatToolResponse(result);
    }

    // Reconcile sync directories
    case 'pw_sync_reconcile': {
      const { directory, dryRun } = args as { directory?: string; dryRun?: boolean };
      const cmdArgs = directory ? [directory] : [];
      if (dryRun === false) {
        cmdArgs.push('--dry-run=0');
      }
      const result = await runPwCommand('sync:reconcile', cmdArgs);
      return formatToolResponse(result);
    }

    // ========================================================================
    // PAGE REFERENCE VALIDATION
    // ========================================================================

    case 'pw_validate_refs': {
      const { target, syncRoot } = args as { target?: 'local' | 'remote'; syncRoot?: string };
      const result = await validateRefs({ target, syncRoot });
      if (!result.success) return formatToolResponse(result);

      const report = result.data as {
        target: string;
        scannedPages: number;
        totalRefs: number;
        ok: number;
        unpublished: number;
        missing: number;
        issues: Array<{ sourcePage: string; fieldName: string; refPath: string; status: string; title?: string }>;
        message?: string;
      };

      const lines: string[] = [
        `## Page Reference Validation — ${report.target}`,
        ``,
        `Scanned **${report.scannedPages}** pages · **${report.totalRefs}** total refs`,
        `✅ OK: ${report.ok}  ⚠️ Unpublished: ${report.unpublished}  ❌ Missing: ${report.missing}`,
      ];

      if (report.message) {
        lines.push(``, report.message);
      }

      if (report.missing > 0) {
        lines.push(``, `### ❌ Missing (push would blank these fields)`);
        for (const i of report.issues.filter(x => x.status === 'missing')) {
          lines.push(`- **${i.sourcePage}** → \`${i.fieldName}\` → \`${i.refPath}\``);
        }
      }

      if (report.unpublished > 0) {
        lines.push(``, `### ⚠️ Unpublished (ref valid but page is hidden)`);
        for (const i of report.issues.filter(x => x.status === 'unpublished')) {
          lines.push(`- **${i.sourcePage}** → \`${i.fieldName}\` → \`${i.refPath}\` (${i.title ?? ''})`);
        }
      }

      if (report.missing === 0 && report.unpublished === 0) {
        lines.push(``, `All page references are valid on **${report.target}**. Safe to push.`);
      }

      return {
        content: [{ type: 'text' as const, text: lines.join('\n') }],
        isError: false,
      };
    }

    // ========================================================================
    // PHASE 3: PAGE CREATION & PUBLISHING
    // ========================================================================

    // Create new page scaffold locally
    case 'pw_page_new': {
      const { template, parentPath, pageName, title } = args as {
        template: string;
        parentPath: string;
        pageName: string;
        title?: string;
      };
      const cmdArgs = [template, parentPath, pageName];
      if (title) {
        cmdArgs.push(`--title=${title}`);
      }
      const result = await runPwCommand('page:new', cmdArgs);
      return formatToolResponse(result);
    }

    // Initialise or repair page.meta.json
    case 'pw_page_init': {
      const { localPath, template } = args as { localPath: string; template?: string };
      const cmdArgs = [localPath];
      if (template) cmdArgs.push(`--template=${template}`);
      const result = await runPwCommand('page:init', cmdArgs);
      return formatToolResponse(result);
    }

    // Publish a new page scaffold to local, remote, or both
    case 'pw_page_publish': {
      const { localPath, dryRun, published, targets } = args as {
        localPath: string;
        dryRun?: boolean;
        published?: boolean;
        targets?: 'local' | 'remote' | 'both';
      };
      const resolvedTargets = targets ?? (process.env.PW_REMOTE_URL && !process.env.PW_PATH ? 'remote' : 'local');
      const result = await publishPage({
        localPath,
        dryRun:    dryRun !== false,
        published: published ?? false,
        targets:   resolvedTargets,
      });
      return formatToolResponse(result);
    }

    // Bulk publish new pages
    case 'pw_pages_publish': {
      const { directory, dryRun, published } = args as {
        directory?: string;
        dryRun?: boolean;
        published?: boolean;
      };
      const cmdArgs = directory ? [directory] : ['site/syncs'];
      if (dryRun === false) {
        cmdArgs.push('--dry-run=0');
      }
      if (published) {
        cmdArgs.push('--published');
      }
      const result = await runPwCommand('pages:publish', cmdArgs);
      return formatToolResponse(result);
    }

    // ========================================================================
    // SCHEMA SYNC TOOLS (Phase 2)
    // ========================================================================

    case 'pw_schema_pull': {
      const result = await schemaPull();
      return formatToolResponse(result);
    }

    case 'pw_schema_push': {
      const { dryRun } = args as { dryRun?: boolean };
      const result = await schemaPush(dryRun !== false);
      return formatToolResponse(result);
    }

    case 'pw_schema_diff': {
      const result = await schemaDiff();
      return formatToolResponse(result);
    }

    case 'pw_schema_compare': {
      const { source, target } = args as { source?: string; target?: string };
      const result = await compareSchemas(source ?? 'current', target ?? 'production');
      return formatToolResponse(result);
    }

    case 'pw_list_sites': {
      const sites = await listSiteConfigs();
      return formatToolResponse({
        success: true,
        data: {
          sites,
          configDir: process.env.PW_SYNC_DIR
            ? `${process.env.PW_SYNC_DIR}/sites`
            : `${process.env.PW_PATH}/.pw-sync/sites`,
          hint: sites.length === 0
            ? 'No sites configured yet. Create .pw-sync/sites/production.json from the example file.'
            : `Use these names with pw_schema_compare: ${sites.join(', ')}`,
        },
      });
    }

    // ========================================================================
    // PHASE 5: DATABASE, LOGS & CACHE
    // ========================================================================

    case 'pw_db_schema': {
      const { table, site } = args as { table?: string; site?: Site };
      const cmdArgs = table ? [table] : [];
      const result = await runOnSite(site, 'db-schema', cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_db_query': {
      const { sql, limit, site } = args as { sql: string; limit?: number; site?: Site };
      const cmdArgs = [sql];
      if (limit) {
        cmdArgs.push(`--limit=${limit}`);
      }
      const result = await runOnSite(site, 'db-query', cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_db_explain': {
      const { sql, site } = args as { sql: string; site?: Site };
      const result = await runOnSite(site, 'db-explain', [sql]);
      return formatToolResponse(result);
    }

    case 'pw_db_counts': {
      const { site } = args as { site?: Site };
      const result = await runOnSite(site, 'db-counts');
      return formatToolResponse(result);
    }

    case 'pw_logs': {
      const { logName, level, text, limit, site } = args as {
        logName?: string;
        level?: string;
        text?: string;
        limit?: number;
        site?: Site;
      };
      const cmdArgs = logName ? [logName] : [];
      if (level) cmdArgs.push(`--level=${level}`);
      if (text) cmdArgs.push(`--text=${text}`);
      if (limit) cmdArgs.push(`--limit=${limit}`);
      const result = await runOnSite(site, 'logs', cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_last_error': {
      const { site } = args as { site?: Site };
      const result = await runOnSite(site, 'last-error');
      return formatToolResponse(result);
    }

    case 'pw_clear_cache': {
      const { target, site } = args as { target?: string; site?: Site };
      const result = await runOnSite(site, 'clear-cache', [target || 'all']);
      return formatToolResponse(result);
    }

    // ========================================================================
    // PHASE 4: DIRECT WRITE TOOLS
    // ========================================================================

    // Get matrix/repeater field structure
    case 'pw_matrix_info': {
      const { pageIdOrPath, fieldName } = args as {
        pageIdOrPath: string;
        fieldName: string;
      };
      const result = await runPwCommand('matrix:info', [pageIdOrPath, fieldName]);
      return formatToolResponse(result);
    }

    // Add a new matrix item to a page
    case 'pw_matrix_add': {
      const { pageIdOrPath, fieldName, matrixType, content, dryRun } = args as {
        pageIdOrPath: string;
        fieldName: string;
        matrixType: string;
        content: Record<string, unknown>;
        dryRun?: boolean;
      };
      const cmdArgs = [pageIdOrPath, fieldName, matrixType];
      // Pass content as JSON string
      cmdArgs.push(`--content=${JSON.stringify(content)}`);
      if (dryRun === false) {
        cmdArgs.push('--dry-run=0');
      }
      const result = await runPwCommand('matrix:add', cmdArgs);
      return formatToolResponse(result);
    }

    // ========================================================================
    // PHASE 5: SITE SYNC
    // ========================================================================

    case 'pw_maintenance': {
      const { action, message, targets } = args as {
        action: 'on' | 'off' | 'status';
        message?: string;
        targets?: 'local' | 'remote' | 'both';
      };
      const target = targets ?? 'local';
      const command = `maintenance:${action}`;
      const cmdArgs = action === 'on' && message ? [message] : [];

      if (target === 'both') {
        const [localResult, remoteResult] = await Promise.all([
          runPwCommand(command, cmdArgs),
          (async () => {
            const url = process.env.PW_REMOTE_URL;
            const key = process.env.PW_REMOTE_KEY;
            if (!url || !key) return { success: false, error: 'PW_REMOTE_URL and PW_REMOTE_KEY required for remote target.' };
            const { runRemoteCommand } = await import('./remote/client.js');
            return runRemoteCommand(command, cmdArgs, undefined, url, key);
          })(),
        ]);
        return formatToolResponse({
          success: localResult.success && remoteResult.success,
          data: { local: localResult.data ?? localResult.error, remote: remoteResult.data ?? remoteResult.error },
          error: (!localResult.success || !remoteResult.success)
            ? `Local: ${localResult.error ?? 'ok'}, Remote: ${remoteResult.error ?? 'ok'}`
            : undefined,
        });
      }

      if (target === 'remote') {
        const url = process.env.PW_REMOTE_URL;
        const key = process.env.PW_REMOTE_KEY;
        if (!url || !key) {
          return formatToolResponse({ success: false, error: 'PW_REMOTE_URL and PW_REMOTE_KEY required for remote target.' });
        }
        const { runRemoteCommand } = await import('./remote/client.js');
        const result = await runRemoteCommand(command, cmdArgs, undefined, url, key);
        return formatToolResponse(result);
      }

      const result = await runPwCommand(command, cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_backup': {
      const { action, description, filename, excludeTables, includeFiles, targets } = args as {
        action: 'create' | 'list' | 'restore' | 'delete';
        description?: string;
        filename?: string;
        excludeTables?: string;
        includeFiles?: boolean;
        targets?: 'local' | 'remote';
      };
      const target = targets ?? 'local';
      const command = `backup:${action}`;

      const positionalArgs: string[] = [];
      if (action === 'create' && description) positionalArgs.push(description);
      if ((action === 'restore' || action === 'delete') && filename) positionalArgs.push(filename);

      if (action === 'create' && excludeTables) positionalArgs.push(`--exclude-tables=${excludeTables}`);
      if (action === 'create' && includeFiles === false) positionalArgs.push('--no-files');

      if (target === 'remote') {
        const url = process.env.PW_REMOTE_URL;
        const key = process.env.PW_REMOTE_KEY;
        if (!url || !key) {
          return formatToolResponse({ success: false, error: 'PW_REMOTE_URL and PW_REMOTE_KEY required for remote target.' });
        }
        const { runRemoteCommand } = await import('./remote/client.js');
        const result = await runRemoteCommand(command, positionalArgs, undefined, url, key);
        return formatToolResponse(result);
      }

      const result = await runPwCommand(command, positionalArgs);
      return formatToolResponse(result);
    }

    // ========================================================================
    // v1.9.0 — READ-ONLY DIAGNOSTIC TOOLS
    // ========================================================================

    case 'pw_modules_list': {
      const { classes, site } = args as { classes?: string[]; site?: Site };
      const cmdArgs: string[] = [];
      if (classes && classes.length > 0) {
        cmdArgs.push(`--classes=${classes.join(',')}`);
      }
      const result = await runOnSite(site, 'modules:list', cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_users_list': {
      const { includeAll, site } = args as { includeAll?: boolean; site?: Site };
      const cmdArgs = includeAll ? ['--include=all'] : [];
      const result = await runOnSite(site, 'users:list', cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_resolve': {
      const { type, names, site } = args as {
        type: string;
        names: string[];
        site?: Site;
      };
      // Pack the request as a single JSON --input arg. This sidesteps OS argv
      // length limits for very long name lists and keeps the CLI surface
      // identical regardless of name count.
      const cmdArgs = [`--input=${JSON.stringify({ type, names })}`];
      const result = await runOnSite(site, 'resolve', cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_inspect_template': {
      const { name: templateName, site } = args as { name: string; site?: Site };
      const result = await runOnSite(site, 'template:inspect', [templateName]);
      return formatToolResponse(result);
    }

    // v1.11.0 (WIP) — fieldgroup-only template edits.
    //
    // The PHP handler already speaks the rich JSON input shape (template
    // + add<string|{name,context}>[] + remove + reorder + dryRun + force),
    // so MCP wiring is a shell-through: pack the args as --input JSON and
    // let CommandRouter do the classification. Dry-run is the only path
    // Phase 2b exercises; dryRun=false returns a structured "not yet
    // implemented" error from PHP, which surfaces unchanged here.
    //
    // site="both" returns `{ local, remote }` via runOnSite's built-in
    // parallel fan-out — each side plans against its own catalog and
    // fieldgroup. Cross-site fieldtype-drift detection (the merge post-
    // pass described in SESSION-NEXT.md Phase 2c) is deliberately NOT
    // done here; callers that need it can diff the two plans client-side
    // until the TS classifier lands.
    case 'pw_template_fields_push': {
      const {
        template,
        add,
        remove,
        reorder,
        site,
        dryRun,
        force,
      } = args as {
        template: string;
        add?: Array<string | { name: string; context?: Record<string, unknown> }>;
        remove?: string[];
        reorder?: string[];
        site?: Site;
        dryRun?: boolean;
        force?: boolean;
      };
      const payload = {
        template,
        add:     Array.isArray(add)     ? add     : [],
        remove:  Array.isArray(remove)  ? remove  : [],
        reorder: Array.isArray(reorder) ? reorder : [],
        dryRun:  dryRun  ?? true,
        force:   force   ?? false,
      };
      const result = await runOnSite(
        site,
        'template:fields-push',
        [`--input=${JSON.stringify(payload)}`],
      );
      return formatToolResponse(result);
    }

    case 'pw_site_compare': {
      const {
        excludeTemplates,
        excludePages,
        includeDirs,
        excludeFilePatterns,
        includePageAssets,
      } = args as {
        excludeTemplates?: string[];
        excludePages?: string[];
        includeDirs?: string[];
        excludeFilePatterns?: string[];
        includePageAssets?: boolean;
      };
      const result = await compareSiteFull({
        excludeTemplates,
        excludePages,
        includeDirs,
        excludeFilePatterns,
        includePageAssets,
      });
      return formatToolResponse(result);
    }

    case 'pw_site_sync': {
      const {
        direction,
        scope,
        excludeTemplates,
        excludePages,
        excludeFilePatterns,
        backup,
        maintenance,
        dryRun,
      } = args as {
        direction?: 'local-to-remote' | 'remote-to-local';
        scope?: 'all' | 'pages' | 'schema' | 'files';
        excludeTemplates?: string[];
        excludePages?: string[];
        excludeFilePatterns?: string[];
        backup?: boolean;
        maintenance?: boolean;
        dryRun?: boolean;
      };
      const result = await syncSites({
        direction:          direction ?? 'local-to-remote',
        scope:              scope ?? 'all',
        excludeTemplates,
        excludePages,
        excludeFilePatterns,
        backup:             backup !== false,
        maintenance:        maintenance ?? false,
        dryRun:             dryRun !== false,
      });
      return formatToolResponse(result);
    }

    // Unknown tool
    default:
      return {
        content: [
          {
            type: 'text' as const,
            text: `Unknown tool: ${name}`,
          },
        ],
        isError: true,
      };
  }
});

// ============================================================================
// SERVER STARTUP
// ============================================================================

/**
 * Main entry point
 * 
 * Creates a stdio transport and connects the server.
 * The server will then listen for MCP requests from Cursor.
 */
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  // Log to stderr so it doesn't interfere with MCP communication on stdout
  console.error('PromptWire server running on stdio');
}

// Start the server
main().catch((error) => {
  console.error('Failed to start server:', error);
  process.exit(1);
});
