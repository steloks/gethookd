import type { CallToolResult, Tool } from '@modelcontextprotocol/sdk/types.js';
import { fromV1, upstreamError } from '../errors.js';
import { UpstreamError, type V1Client, type V1Request, type V1Response } from '../v1Client.js';

export interface ToolContext {
  v1: V1Client;
  /** The Authorization header of the MCP request this call came in on. */
  authorization?: string;
  /** Derived from the MCP session and JSON-RPC request id; the same for every attempt of this call. */
  idempotencyKey: string;
  /** Today's date (UTC), YYYY-MM-DD: the day v1's date filters and days_active use. */
  today: string;
}

export interface ToolDefinition extends Tool {
  run(args: Record<string, unknown>, context: ToolContext): Promise<CallToolResult>;
}

export function textResult(value: unknown): CallToolResult {
  return { content: [{ type: 'text', text: JSON.stringify(value) }] };
}

/**
 * Every argument as a v1 query parameter, under the name the model used, so v1 rejects a
 * misspelled or unknown one (with a suggestion) instead of this server dropping it silently.
 * Lists become comma-separated values, which is how v1 takes several niches.
 */
export function toQuery(args: Record<string, unknown>, skip: string[] = []): URLSearchParams {
  const query = new URLSearchParams();
  for (const [name, value] of Object.entries(args)) {
    if (value === undefined || skip.includes(name)) {
      continue;
    }
    // null is sent as an empty value, which v1 rejects ("leave it out"), rather than dropped here.
    query.append(name, value === null ? '' : Array.isArray(value) ? value.map(scalar).join(',') : scalar(value));
  }

  return query;
}

export function scalar(value: unknown): string {
  return typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value);
}

/**
 * Sends the request; a 2xx goes to `format`, anything else becomes a tool error with v1's code.
 */
export async function callV1(
  tool: string,
  args: Record<string, unknown>,
  context: ToolContext,
  request: Omit<V1Request, 'authorization' | 'idempotencyKey'>,
  format: (response: V1Response) => CallToolResult | null,
): Promise<CallToolResult> {
  let response: V1Response;
  try {
    response = await context.v1({
      ...request,
      idempotencyKey: context.idempotencyKey,
      ...(context.authorization === undefined ? {} : { authorization: context.authorization }),
    });
  } catch (error) {
    if (error instanceof UpstreamError) {
      return upstreamError(error, tool);
    }
    throw error;
  }

  if (response.status < 200 || response.status > 299) {
    return fromV1(response.body, response.status, tool, args);
  }

  return format(response) ?? upstreamError(new UpstreamError('bad_response', 'The GetHookd API answered in an unexpected shape.'), tool);
}
