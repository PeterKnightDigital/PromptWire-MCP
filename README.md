# PW-MCP

ProcessWire ↔ Cursor MCP Bridge

A ProcessWire module that exposes site structure and content to Cursor IDE via the Model Context Protocol (MCP).

## Overview

PW-MCP allows developers to query, understand, and (in Phase 2) safely operate a live ProcessWire site using natural language inside Cursor.

## Architecture

```
Cursor (Chat) → MCP Server (Node.js) → CLI (PHP) → ProcessWire API
```

## Components

- **PwMcp/** — ProcessWire module with CLI interface
- **mcp-server/** — Node.js/TypeScript MCP server for Cursor integration
- **examples/** — Example schema outputs

## Phase 1: Read & Understand

- List templates and fields
- Query pages by selector or path
- Export site schema (JSON/YAML)
- Health check for debugging

## Installation

### 1. Symlink the module

```bash
ln -s ~/Sites/pw-mcp/PwMcp ~/Sites/your-pw-site/site/modules/PwMcp
```

### 2. Install in ProcessWire

Go to Modules → Refresh → Install PwMcp

### 3. Configure MCP Server

```bash
cd mcp-server
npm install
npm run build
```

### 4. Add to Cursor MCP settings

Configure with environment variables:
- `PW_PATH` — Path to ProcessWire installation
- `PW_MCP_CLI_PATH` — Path to `PwMcp/bin/pw-mcp.php`
- `PHP_PATH` — Path to PHP binary (optional, defaults to `php`)

## CLI Usage

```bash
export PW_PATH=~/Sites/your-pw-site
php ~/Sites/pw-mcp/PwMcp/bin/pw-mcp.php health --pretty
php ~/Sites/pw-mcp/PwMcp/bin/pw-mcp.php list-templates --pretty
php ~/Sites/pw-mcp/PwMcp/bin/pw-mcp.php get-page /some/page/ --pretty
php ~/Sites/pw-mcp/PwMcp/bin/pw-mcp.php export-schema --format=yaml
```

## CLI Flags

- `--format=json|yaml` — Output format (default: json)
- `--pretty` — Pretty-print JSON
- `--include=usage` — Include field usage info
- `--include=files` — Include file/image metadata

## License

MIT
