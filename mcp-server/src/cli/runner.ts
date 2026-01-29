import { execFile } from 'child_process';
import { promisify } from 'util';

const execFileAsync = promisify(execFile);

export interface PwCommandResult {
  success: boolean;
  data?: unknown;
  error?: string;
}

/**
 * Run a PW-MCP CLI command
 */
export async function runPwCommand(
  command: string,
  args: string[] = []
): Promise<PwCommandResult> {
  const phpPath = process.env.PHP_PATH || 'php';
  const cliPath = process.env.PW_MCP_CLI_PATH;
  const pwPath = process.env.PW_PATH;

  if (!cliPath) {
    return {
      success: false,
      error: 'PW_MCP_CLI_PATH environment variable not set',
    };
  }

  if (!pwPath) {
    return {
      success: false,
      error: 'PW_PATH environment variable not set',
    };
  }

  try {
    const { stdout, stderr } = await execFileAsync(
      phpPath,
      [cliPath, command, ...args],
      {
        env: { ...process.env, PW_PATH: pwPath },
        timeout: 30000, // 30 second timeout
        maxBuffer: 10 * 1024 * 1024, // 10MB buffer for large schemas
      }
    );

    if (stderr && stderr.trim()) {
      console.error('CLI stderr:', stderr);
    }

    const data = JSON.parse(stdout);

    // Check if the CLI returned an error
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
    if (error instanceof Error) {
      // Handle timeout
      if (error.message.includes('TIMEOUT')) {
        return {
          success: false,
          error: 'Command timed out after 30 seconds',
        };
      }

      // Handle JSON parse errors
      if (error instanceof SyntaxError) {
        return {
          success: false,
          error: 'Failed to parse CLI output as JSON',
        };
      }

      return {
        success: false,
        error: error.message,
      };
    }

    return {
      success: false,
      error: 'Unknown error occurred',
    };
  }
}

/**
 * Format command result for MCP tool response
 */
export function formatToolResponse(result: PwCommandResult): {
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
} {
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

  return {
    content: [
      {
        type: 'text',
        text: JSON.stringify(result.data, null, 2),
      },
    ],
  };
}
