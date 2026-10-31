/**
 * The only way this server reaches data: GET requests to the v1 REST API, sent with the caller's
 * own Authorization header. The server never holds a key, never caches, and never checks
 * arguments itself, so auth, scopes, revocation, the rate limit and billing are exactly v1's.
 */

export interface V1Request {
  /** Path under the v1 base URL, e.g. "/api/v1/ads". */
  path: string;
  query?: URLSearchParams;
  /** The Authorization header the MCP client sent, forwarded unchanged (absent if it sent none). */
  authorization?: string;
  /** The same for every attempt of one tool call, so v1 bills the call once however often it is retried. */
  idempotencyKey: string;
}

export interface V1Response {
  status: number;
  body: unknown;
  /** The v1 "Billed" header ("true"/"false"), when present. */
  billed?: string;
}

/** v1 could not be reached, did not answer in time, or answered with something that is not JSON. */
export class UpstreamError extends Error {
  constructor(
    public readonly reason: 'timeout' | 'network' | 'bad_response',
    message: string,
  ) {
    super(message);
  }
}

export interface V1ClientOptions {
  baseUrl: string;
  /** Per attempt. */
  timeoutMs: number;
  /** Extra attempts after a timeout, network error or 5xx. Never after a 4xx or 429. */
  retries?: number;
  retryDelayMs?: number;
  fetch?: typeof fetch;
}

/** Statuses where the same request may succeed a moment later. 429 is excluded: it says how long to wait. */
const RETRY_STATUSES = new Set([500, 502, 503, 504]);

export type V1Client = (request: V1Request) => Promise<V1Response>;

export function createV1Client(options: V1ClientOptions): V1Client {
  const doFetch = options.fetch ?? fetch;
  const retries = options.retries ?? 1;
  const retryDelayMs = options.retryDelayMs ?? 250;
  const base = options.baseUrl.replace(/\/+$/, '');

  return async (request) => {
    const query = request.query?.toString() ?? '';
    const url = `${base}${request.path}${query === '' ? '' : `?${query}`}`;
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Client': 'mcp',
      'Idempotency-Key': request.idempotencyKey,
    };
    if (request.authorization !== undefined) {
      headers.Authorization = request.authorization;
    }

    let failure: UpstreamError | V1Response | undefined;
    for (let attempt = 0; attempt <= retries; attempt++) {
      if (attempt > 0) {
        await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
      }

      try {
        // Never follow a redirect: it would carry the caller's key somewhere v1 did not answer from.
        const response = await doFetch(url, { headers, redirect: 'manual', signal: AbortSignal.timeout(options.timeoutMs) });
        const result = await read(response);
        if (!RETRY_STATUSES.has(result.status)) {
          return result;
        }
        failure = result;
      } catch (error) {
        failure =
          error instanceof UpstreamError
            ? error
            : isTimeout(error)
              ? new UpstreamError('timeout', `The GetHookd API did not answer within ${options.timeoutMs / 1000} seconds.`)
              : new UpstreamError('network', 'The GetHookd API could not be reached.');
      }
    }

    if (failure instanceof UpstreamError) {
      throw failure;
    }

    // A 5xx every time: v1's own error (internal_error, unavailable) is passed on.
    return failure as V1Response;
  };
}

async function read(response: Response): Promise<V1Response> {
  if (response.status >= 300 && response.status <= 399) {
    throw new UpstreamError('bad_response', `The GetHookd API answered with a redirect (HTTP ${response.status}), which this server does not follow.`);
  }
  const text = await response.text();
  let body: unknown;
  try {
    body = JSON.parse(text);
  } catch {
    throw new UpstreamError('bad_response', `The GetHookd API answered HTTP ${response.status} without a JSON body.`);
  }

  const billed = response.headers.get('Billed');

  return { status: response.status, body, ...(billed === null ? {} : { billed }) };
}

function isTimeout(error: unknown): boolean {
  return error instanceof DOMException && (error.name === 'TimeoutError' || error.name === 'AbortError');
}
