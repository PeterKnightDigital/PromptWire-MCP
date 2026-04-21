/**
 * PromptWire Remote HTTP Client
 *
 * Makes authenticated HTTP requests to a remote PromptWire API endpoint.
 * Used when PW_REMOTE_URL is set in the environment instead of PW_PATH.
 *
 * Configure in mcp.json:
 *   "env": {
 *     "PW_REMOTE_URL": "https://example.com/your-endpoint.php",
 *     "PW_REMOTE_KEY": "your-secret-api-key"
 *   }
 *
 * @package     PromptWire
 * @subpackage  MCP Server
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

import type { PwCommandResult } from '../cli/runner.js';

// ============================================================================
// TYPES
// ============================================================================

interface RemoteRequest {
  command: string;
  args: string[];
  schemaData?: Record<string, unknown>;
  pageData?: Record<string, unknown>;
  fileData?: Record<string, unknown>;
}

interface RemoteResponse {
  error?: string;
  [key: string]: unknown;
}

// ============================================================================
// REMOTE COMMAND EXECUTION
// ============================================================================

/**
 * Run a PromptWire command on a remote site via HTTP
 *
 * POSTs to the remote API endpoint with the command and args.
 * The remote endpoint bootstraps ProcessWire and runs the same
 * CommandRouter that the local CLI uses — zero logic duplication.
 *
 * Required env vars:
 *   PW_REMOTE_URL - Full URL to your API endpoint file on the remote site
 *   PW_REMOTE_KEY - API key (must match key configured on remote site)
 *
 * @param command - CLI command to run (e.g., 'health', 'get-page')
 * @param args    - Additional arguments and flags
 * @returns Promise resolving to command result
 */
export async function runRemoteCommand(
  command: string,
  args: string[] = [],
  schemaData?: Record<string, unknown>,
  overrideUrl?: string,
  overrideKey?: string,
  pageData?: Record<string, unknown>,
  fileData?: Record<string, unknown>
): Promise<PwCommandResult> {
  const remoteUrl = overrideUrl ?? process.env.PW_REMOTE_URL!;
  const remoteKey = overrideKey ?? process.env.PW_REMOTE_KEY ?? '';

  if (!remoteUrl) {
    return { success: false, error: 'No remote URL configured' };
  }

  if (!remoteKey) {
    console.error('Warning: PW_REMOTE_KEY not set — remote site may reject requests');
  }

  const payload: RemoteRequest = { command, args };
  if (schemaData) payload.schemaData = schemaData;
  if (pageData)   payload.pageData   = pageData;
  if (fileData)   payload.fileData   = fileData;

  try {
    const response = await fetch(remoteUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-PromptWire-Key': remoteKey,
        'X-PromptWire-Client': 'cursor-mcp/1.0',
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(command.startsWith('file:') ? 120_000 : 60_000),
    });

    // Handle HTTP error codes with helpful messages
    if (!response.ok) {
      switch (response.status) {
        case 401:
          return {
            success: false,
            error: 'Remote authentication failed — check PW_REMOTE_KEY matches the key configured on the remote site',
          };
        case 403:
          return {
            success: false,
            error: 'Remote access denied — your IP may not be in the allowed list on the remote site',
          };
        case 404:
          return {
            success: false,
            error: `Remote API not found at ${remoteUrl} — ensure your endpoint file is deployed and the URL is correct`,
          };
        case 500:
          return {
            success: false,
            error: 'Remote server error — check that PromptWire module is installed on the remote site',
          };
        default:
          return {
            success: false,
            error: `Remote API returned HTTP ${response.status}`,
          };
      }
    }

    // Parse JSON response
    let data: RemoteResponse;
    try {
      data = await response.json() as RemoteResponse;
    } catch {
      return {
        success: false,
        error: 'Remote API returned invalid JSON — check server error logs',
      };
    }

    // Check for application-level errors in the JSON
    if (data.error) {
      return {
        success: false,
        error: data.error as string,
      };
    }

    return {
      success: true,
      data,
    };

  } catch (error) {
    if (error instanceof Error) {
      // AbortSignal timeout
      if (error.name === 'TimeoutError' || error.name === 'AbortError') {
        return {
          success: false,
          error: `Remote request timed out after 60s — is ${remoteUrl} reachable?`,
        };
      }
      // Network errors (DNS, connection refused, etc.)
      if (error.message.includes('fetch failed') || error.message.includes('ECONNREFUSED')) {
        return {
          success: false,
          error: `Cannot connect to remote site at ${remoteUrl} — check the URL and that the site is online`,
        };
      }
      return {
        success: false,
        error: `Remote connection error: ${error.message}`,
      };
    }
    return {
      success: false,
      error: 'Unknown error connecting to remote site',
    };
  }
}
