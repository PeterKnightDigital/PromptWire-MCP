#!/usr/bin/env node
/**
 * PW-MCP Server
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
 *   - PW_MCP_CLI_PATH: Path to the pw-mcp.php CLI script
 *   - PHP_PATH: Path to PHP binary (optional, defaults to 'php')
 * 
 * @package     PwMcp
 * @author      Peter Knight
 * @license     MIT
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { runPwCommand, formatToolResponse } from './cli/runner.js';

// ============================================================================
// TOOL DEFINITIONS
// ============================================================================
// These define the MCP tools that Cursor can invoke. Each tool maps to a
// CLI command in the ProcessWire module.

const tools = [
  {
    name: 'pw_health',
    description: 'Check ProcessWire connection and get site info (version, counts, module status)',
    inputSchema: {
      type: 'object' as const,
      properties: {},
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
          description: 'Page ID (number) or path (e.g., "/about/" or "/guides/web-design/")',
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
    description: 'Pull a ProcessWire page into local sync directory (site/syncs/) as editable YAML file. Creates page.meta.json (identity) and page.yaml (editable content).',
    inputSchema: {
      type: 'object' as const,
      properties: {
        pageIdOrPath: {
          type: 'string',
          description: 'Page ID (number) or path (e.g., "/about/" or "/services/web-design/")',
        },
      },
      required: ['pageIdOrPath'],
    },
  },
  {
    name: 'pw_page_push',
    description: 'Push local changes from sync directory back to ProcessWire. Shows preview by default (dry-run). Set dryRun=false to apply changes.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        localPath: {
          type: 'string',
          description: 'Path to local sync directory or page.yaml file (e.g., "site/syncs/about/why-choose-us")',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), show what would change without applying. Set to false to apply changes.',
          default: true,
        },
        force: {
          type: 'boolean',
          description: 'Force push even if remote page has changed since last pull (dangerous)',
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
    description: 'Bulk push all local changes in a sync directory tree. Shows preview by default (dry-run). Set dryRun=false to apply.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        directory: {
          type: 'string',
          description: 'Sync directory to push (e.g., "site/syncs/services" or "site/syncs" for all)',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview changes without applying. Set to false to apply.',
          default: true,
        },
        force: {
          type: 'boolean',
          description: 'Force push even if remote pages have changed (dangerous)',
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
    name: 'pw_page_publish',
    description: 'Publish a new page to ProcessWire. Only works for pages created with page:new (marked new: true).',
    inputSchema: {
      type: 'object' as const,
      properties: {
        localPath: {
          type: 'string',
          description: 'Path to local page directory (e.g., "site/syncs/services/new-service")',
        },
        dryRun: {
          type: 'boolean',
          description: 'If true (default), preview what would be created. Set to false to create.',
          default: true,
        },
        published: {
          type: 'boolean',
          description: 'Create as published (default: false, creates as unpublished)',
          default: false,
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
    name: 'pw-mcp',
    version: '1.0.0',
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
      const result = await runPwCommand('health');
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

    // Pull page to local sync directory
    case 'pw_page_pull': {
      const pageIdOrPath = (args as { pageIdOrPath: string }).pageIdOrPath;
      const result = await runPwCommand('page:pull', [pageIdOrPath]);
      return formatToolResponse(result);
    }

    // Push local changes to ProcessWire
    case 'pw_page_push': {
      const { localPath, dryRun, force } = args as {
        localPath: string;
        dryRun?: boolean;
        force?: boolean;
      };
      const cmdArgs = [localPath];
      // dry-run is ON by default, only add flag when explicitly false
      if (dryRun === false) {
        cmdArgs.push('--dry-run=0');
      }
      if (force) {
        cmdArgs.push('--force');
      }
      const result = await runPwCommand('page:push', cmdArgs);
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

    // Bulk push all changes in a directory tree
    case 'pw_pages_push': {
      const { directory, dryRun, force } = args as {
        directory: string;
        dryRun?: boolean;
        force?: boolean;
      };
      const cmdArgs = [directory];
      if (dryRun === false) {
        cmdArgs.push('--dry-run=0');
      }
      if (force) {
        cmdArgs.push('--force');
      }
      const result = await runPwCommand('pages:push', cmdArgs);
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

    // Publish a new page to ProcessWire
    case 'pw_page_publish': {
      const { localPath, dryRun, published } = args as {
        localPath: string;
        dryRun?: boolean;
        published?: boolean;
      };
      const cmdArgs = [localPath];
      if (dryRun === false) {
        cmdArgs.push('--dry-run=0');
      }
      if (published) {
        cmdArgs.push('--published');
      }
      const result = await runPwCommand('page:publish', cmdArgs);
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
  console.error('PW-MCP server running on stdio');
}

// Start the server
main().catch((error) => {
  console.error('Failed to start server:', error);
  process.exit(1);
});
