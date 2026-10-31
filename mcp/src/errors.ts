import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { UpstreamError } from './v1Client.js';

/**
 * Tool errors in v1's error shape, written for a model that reads them literally.
 *
 * v1 error codes are passed through unchanged, with v1's message, retryable, retry_after,
 * resets_at and details. This file only rewrites next_step in terms of tool calls and, when v1
 * says how to fix every problem, adds corrected_arguments: the whole call to make instead.
 *
 * Codes this server adds, for failures that never reach v1:
 * - upstream_unavailable: v1 could not be reached or did not answer in time (after one retry).
 * - upstream_invalid_response: v1 answered with something that is not a v1 response.
 */

export interface ToolErrorBody {
  code: string;
  message: string;
  next_step: string;
  retryable: boolean;
  retry_after?: number;
  resets_at?: string;
  details?: Detail[];
  corrected_arguments?: Record<string, unknown>;
}

interface Detail {
  param?: string;
  issue?: string;
  message?: string;
  allowed?: unknown;
  did_you_mean?: string;
}

interface V1ErrorBody {
  code: string;
  message: string;
  next_step: string;
  retryable: boolean;
  retry_after?: number;
  resets_at?: string;
  details?: Detail[];
}

/** v1 sends at most this many details; with that many, some may be missing, so no correction is offered. */
const V1_MAX_DETAILS = 10;

export function errorResult(error: ToolErrorBody): CallToolResult {
  return { content: [{ type: 'text', text: JSON.stringify({ error }) }], isError: true };
}

export function upstreamError(error: UpstreamError, tool: string): CallToolResult {
  if (error.reason === 'bad_response') {
    return errorResult({
      code: 'upstream_invalid_response',
      message: error.message,
      next_step: `Call ${tool} again in a few seconds. If it keeps failing, tell the user the GetHookd API is not working.`,
      retryable: true,
    });
  }

  return errorResult({
    code: 'upstream_unavailable',
    message: `${error.message} This server already retried once.`,
    next_step: `Call ${tool} again in a few seconds with the same arguments. If it keeps failing, tell the user the GetHookd API is unavailable.`,
    retryable: true,
  });
}

/**
 * The tool error for a v1 error response.
 *
 * @param args the arguments the tool was called with, to build corrected_arguments from
 */
export function fromV1(body: unknown, status: number, tool: string, args: Record<string, unknown>): CallToolResult {
  const v1 = asV1Error(body);
  if (v1 === null) {
    return upstreamError(new UpstreamError('bad_response', `The GetHookd API answered HTTP ${status} without an error in its usual shape.`), tool);
  }

  const corrected = v1.code === 'invalid_parameters' ? correctedArguments(v1.details ?? [], args) : null;

  return errorResult({
    code: v1.code,
    message: v1.message,
    next_step: nextStep(v1, tool, args, corrected !== null),
    retryable: v1.retryable,
    ...(v1.retry_after === undefined ? {} : { retry_after: v1.retry_after }),
    ...(v1.resets_at === undefined ? {} : { resets_at: v1.resets_at }),
    ...(v1.details === undefined ? {} : { details: v1.details }),
    ...(corrected === null ? {} : { corrected_arguments: corrected }),
  });
}

function nextStep(v1: V1ErrorBody, tool: string, args: Record<string, unknown>, corrected: boolean): string {
  switch (v1.code) {
    case 'invalid_parameters': {
      if (corrected) {
        return `Call ${tool} again with corrected_arguments (the whole argument object). Do not retry the same arguments.`;
      }
      if ((v1.details ?? []).some((d) => d.issue === 'cursor_mismatch')) {
        return 'A cursor continues the search it came from. To get the next page of that search, call search_ads with only {"cursor": <the cursor>}. To run a different search, call search_ads without cursor.';
      }

      return `Fix each argument listed in details (allowed values are given there), then call ${tool} again. Do not retry the same arguments.`;
    }
    case 'rate_limited':
      return `Wait ${v1.retry_after ?? 60} seconds, then call ${tool} again with the same arguments. This API key may make 60 calls per minute.`;
    case 'quota_exceeded':
      return `Do not retry: this account has used its monthly quota of billable calls. It resets at ${v1.resets_at ?? 'the start of next month (UTC)'}. Tell the user. list_niches still works.`;
    case 'unauthenticated':
      return 'Do not retry. The API key in this MCP connection is missing, invalid or revoked. Ask the user to create a key on the GetHookd developer page and put it in this MCP server\'s "Authorization: Bearer <key>" header.';
    case 'insufficient_scope':
      return 'Do not retry. The API key in this MCP connection lacks the search:read scope. Ask the user to create a key with search:read on the GetHookd developer page.';
    case 'forbidden':
      return 'Do not retry with this API key; it will be refused again. Tell the user.';
    case 'not_found':
      return tool === 'get_ad'
        ? `No ad has id ${JSON.stringify(args.id)}. Use an id from search_ads results. Do not retry the same id.`
        : 'Do not retry. Tell the user this resource does not exist.';
    case 'internal_error':
    case 'unavailable':
      return `Call ${tool} again in a few seconds with the same arguments (this server already retried once). If it keeps failing, tell the user the GetHookd API is having problems.`;
    case 'bad_request':
      return `Do not retry the same arguments. Check them against the ${tool} input schema, then call it again.`;
    case 'method_not_allowed':
      return 'Do not retry. This MCP server sent a request the GetHookd API does not support; tell the user it is a bug in the MCP server.';
    case 'idempotency_key_reused':
      return `Call ${tool} again; a new call is sent as a new request.`;
    default:
      return v1.next_step;
  }
}

/**
 * The arguments to call again with, when v1 named a likely fix for every problem; otherwise
 * null, because a partly corrected call would still fail (or quietly search for something else).
 */
function correctedArguments(details: Detail[], args: Record<string, unknown>): Record<string, unknown> | null {
  if (details.length === 0 || details.length >= V1_MAX_DETAILS) {
    return null;
  }

  const corrected: Record<string, unknown> = { ...args };
  for (const detail of details) {
    const param = detail.param;
    if (param === undefined) {
      return null;
    }

    if (detail.issue === 'empty_value') {
      delete corrected[param];
      continue;
    }

    if (detail.did_you_mean === undefined) {
      return null;
    }

    if (detail.issue === 'unknown_parameter' || detail.issue === 'array_syntax') {
      // did_you_mean is the argument name meant; the value stays (v1 checks it under that name).
      if (!(param in args)) {
        return null;
      }
      corrected[detail.did_you_mean] = args[param];
      delete corrected[param];
      continue;
    }

    corrected[param] = toArgument(param, detail.did_you_mean);
  }

  return corrected;
}

/** A v1 query value as the tool argument it came from: niche is a list, limit a number. */
function toArgument(param: string, value: string): unknown {
  if (param === 'niche') {
    return value.split(',');
  }
  if (param === 'limit' && /^\d+$/.test(value)) {
    return Number(value);
  }

  return value;
}

function asV1Error(body: unknown): V1ErrorBody | null {
  const error = (body as { error?: unknown } | null)?.error as Partial<V1ErrorBody> | undefined;
  if (
    typeof error !== 'object' ||
    error === null ||
    typeof error.code !== 'string' ||
    typeof error.message !== 'string' ||
    typeof error.next_step !== 'string' ||
    typeof error.retryable !== 'boolean'
  ) {
    return null;
  }

  return {
    code: error.code,
    message: error.message,
    next_step: error.next_step,
    retryable: error.retryable,
    ...(typeof error.retry_after === 'number' ? { retry_after: error.retry_after } : {}),
    ...(typeof error.resets_at === 'string' ? { resets_at: error.resets_at } : {}),
    ...(Array.isArray(error.details) ? { details: error.details } : {}),
  };
}
