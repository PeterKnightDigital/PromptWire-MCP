#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { runPwCommand, formatToolResponse } from './cli/runner.js';

// Tool definitions
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

// Create MCP server
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

// Handle list tools request
server.setRequestHandler(ListToolsRequestSchema, async () => {
  return { tools };
});

// Handle tool calls
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  switch (name) {
    case 'pw_health': {
      const result = await runPwCommand('health');
      return formatToolResponse(result);
    }

    case 'pw_list_templates': {
      const result = await runPwCommand('list-templates');
      return formatToolResponse(result);
    }

    case 'pw_get_template': {
      const templateName = (args as { name: string }).name;
      const result = await runPwCommand('get-template', [templateName]);
      return formatToolResponse(result);
    }

    case 'pw_list_fields': {
      const includeUsage = (args as { includeUsage?: boolean }).includeUsage;
      const cmdArgs = includeUsage ? ['--include=usage'] : [];
      const result = await runPwCommand('list-fields', cmdArgs);
      return formatToolResponse(result);
    }

    case 'pw_get_field': {
      const fieldName = (args as { name: string }).name;
      const result = await runPwCommand('get-field', [fieldName]);
      return formatToolResponse(result);
    }

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

    case 'pw_query_pages': {
      const selector = (args as { selector: string }).selector;
      const result = await runPwCommand('query-pages', [selector]);
      return formatToolResponse(result);
    }

    case 'pw_export_schema': {
      const format = (args as { format?: string }).format || 'json';
      const cmdArgs = format === 'yaml' ? ['--format=yaml'] : [];
      const result = await runPwCommand('export-schema', cmdArgs);
      return formatToolResponse(result);
    }

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

// Start server
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('PW-MCP server running on stdio');
}

main().catch((error) => {
  console.error('Failed to start server:', error);
  process.exit(1);
});
