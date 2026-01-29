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
    description: 'Get a ProcessWire page by ID or path, including all field values',
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
    version: '0.1.0',
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
      const { idOrPath, includeFiles } = args as {
        idOrPath: string;
        includeFiles?: boolean;
      };
      const cmdArgs = [idOrPath];
      if (includeFiles) {
        cmdArgs.push('--include=files');
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
