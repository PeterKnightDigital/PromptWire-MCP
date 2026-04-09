/**
 * PromptWire CLI Runner
 * 
 * Executes PHP CLI commands to interact with ProcessWire.
 * Uses async execution with timeouts and buffer limits for safety.
 * 
 * This module is the bridge between the Node.js MCP server and
 * the PHP CLI interface of the ProcessWire module.
 * 
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import { execFile } from 'child_process';
import { promisify } from 'util';
import { runRemoteCommand } from '../remote/client.js';

// Promisify execFile for async/await usage
const execFileAsync = promisify(execFile);

// ============================================================================
// TYPES
// ============================================================================

/**
 * Result from running a PromptWire CLI command
 * 
 * @property success - Whether the command executed successfully
 * @property data    - Parsed JSON data from the command (if successful)
 * @property error   - Error message (if failed)
 */
export interface PwCommandResult {
  success: boolean;
  data?: unknown;
  error?: string;
}

// ============================================================================
// CLI EXECUTION
// ============================================================================

/**
 * Run a PromptWire CLI command
 * 
 * Spawns a PHP process to execute the promptwire.php CLI script with the
 * given command and arguments. The output is parsed as JSON.
 * 
 * Safety features:
 * - Uses execFile (not exec) for safe argument handling
 * - 30 second timeout to prevent hangs
 * - 10MB buffer to handle large schema exports
 * - Proper error handling for timeouts, parse errors, etc.
 * 
 * Required Environment Variables:
 * - PW_PATH: Path to ProcessWire installation
 * 
 * Optional Environment Variables:
 * - PROMPTWIRE_CLI_PATH: Path to promptwire.php script (auto-detected if module
 *   is installed in standard location: /site/modules/PromptWire/)
 * - PHP_PATH: Path to PHP binary (defaults to 'php')
 * 
 * @param command - CLI command to run (e.g., 'health', 'list-templates')
 * @param args    - Additional arguments (e.g., template name, flags)
 * @returns Promise resolving to command result
 * 
 * @example
 * // Simple command
 * const result = await runPwCommand('health');
 * 
 * @example
 * // Command with arguments
 * const result = await runPwCommand('get-template', ['basic-page']);
 * 
 * @example
 * // Command with flags
 * const result = await runPwCommand('list-fields', ['--include=usage']);
 */
export async function runPwCommand(
  command: string,
  args: string[] = []
): Promise<PwCommandResult> {
  // Get configuration from environment
  const phpPath = process.env.PHP_PATH || 'php';
  const pwPath = process.env.PW_PATH;

  // Routing logic:
  // - If PW_PATH is set, always use local PHP CLI (even if PW_REMOTE_URL is also set).
  //   This prevents the remote URL from silently hijacking local page queries.
  //   Tools that need the remote endpoint (e.g. file-sync) call runRemoteCommand() directly.
  // - If only PW_REMOTE_URL is set (no PW_PATH), route everything to the remote API.
  if (!pwPath && process.env.PW_REMOTE_URL) {
    return runRemoteCommand(command, args);
  }

  // Validate required environment variables
  if (!pwPath) {
    return {
      success: false,
      error: 'PW_PATH environment variable not set',
    };
  }

  // Auto-detect CLI path if not explicitly set
  // Standard location: /site/modules/PromptWire/bin/promptwire.php
  const cliPath = process.env.PROMPTWIRE_CLI_PATH || 
    `${pwPath}/site/modules/PromptWire/bin/promptwire.php`;

  try {
    // Execute the PHP CLI script
    // Using execFile instead of exec for:
    // - Safe argument handling (no shell injection)
    // - Better performance (no shell overhead)
    const { stdout, stderr } = await execFileAsync(
      phpPath,
      [cliPath, command, ...args],
      {
        // Pass PW_PATH to the PHP script
        env: { ...process.env, PW_PATH: pwPath },
        // 30 second timeout - prevents hung processes
        timeout: 30000,
        // 10MB buffer - handles large schema exports
        maxBuffer: 10 * 1024 * 1024,
      }
    );

    // Log any stderr output for debugging (doesn't fail the command)
    if (stderr && stderr.trim()) {
      console.error('CLI stderr:', stderr);
    }

    // Parse the JSON output
    const data = JSON.parse(stdout);

    // Check if the CLI returned an error in the JSON
    if (data.error) {
      return {
        success: false,
        error: data.error,
      };
    }

    return {
      success: true,
      data,
    };
  } catch (error) {
    // Handle different error types
    if (error instanceof Error) {
      // Handle timeout errors
      if (error.message.includes('TIMEOUT') || error.message.includes('ETIMEDOUT')) {
        return {
          success: false,
          error: 'Command timed out after 30 seconds',
        };
      }

      // Handle JSON parse errors (CLI output wasn't valid JSON)
      if (error instanceof SyntaxError) {
        return {
          success: false,
          error: 'Failed to parse CLI output as JSON',
        };
      }

      // Return the error message for other errors
      return {
        success: false,
        error: error.message,
      };
    }

    // Unknown error type
    return {
      success: false,
      error: 'Unknown error occurred',
    };
  }
}

// ============================================================================
// RESPONSE FORMATTING
// ============================================================================

/**
 * Format command result for MCP tool response
 * 
 * Converts our internal PwCommandResult format to the MCP tool response
 * format expected by the MCP SDK.
 * 
 * Success responses include the data as pretty-printed JSON.
 * Error responses include the error message and set isError flag.
 * 
 * @param result - Result from runPwCommand
 * @returns MCP tool response object
 * 
 * @example
 * const result = await runPwCommand('health');
 * return formatToolResponse(result);
 */
export function formatToolResponse(result: PwCommandResult): {
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
} {
  // Handle error responses
  if (!result.success) {
    return {
      content: [
        {
          type: 'text',
          text: `Error: ${result.error}`,
        },
      ],
      isError: true,
    };
  }

  // Handle success responses - format data as pretty JSON
  return {
    content: [
      {
        type: 'text',
        text: JSON.stringify(result.data, null, 2),
      },
    ],
  };
}
