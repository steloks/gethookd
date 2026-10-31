import { createHash, randomUUID } from 'node:crypto';
import { createServer, type IncomingMessage, type Server as HttpServer, type ServerResponse } from 'node:http';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  CallToolRequestSchema,
  ErrorCode,
  isInitializeRequest,
  ListToolsRequestSchema,
  McpError,
  type RequestId,
} from '@modelcontextprotocol/sdk/types.js';
import { getAd } from './tools/getAd.js';
import { listNiches } from './tools/listNiches.js';
import { searchAds } from './tools/searchAds.js';
import type { ToolDefinition } from './tools/tool.js';
import { errorResult } from './errors.js';
import type { V1Client } from './v1Client.js';

export const TOOLS: ToolDefinition[] = [listNiches, searchAds, getAd];

const INSTRUCTIONS = `GetHookd ad library: search ads that brands run (copy, niche, format, status, dates). Niche slugs come from list_niches. search_ads returns compact pages with a cursor; get_ad returns one ad in full. Impression data ranks ads only within one brand; there is no cross-brand impression, spend or performance data. Errors include next_step and, when the fix is certain, corrected_arguments.`;

/**
 * The Idempotency-Key sent to v1 for one tool call. The same JSON-RPC request in the same
 * session (the client re-sending a message it got no answer to) gets the same key, and so does
 * every retry this server makes, so v1 bills it once. A new tool call has a new request id,
 * so it is a new key and is billed, even with the same arguments.
 */
export function idempotencyKey(sessionId: string | undefined, requestId: RequestId, tool: string, args: unknown): string {
  const hash = createHash('sha256')
    .update(JSON.stringify([sessionId ?? randomUUID(), requestId, tool, args]))
    .digest('hex');

  return `mcp-${hash}`;
}

export interface McpOptions {
  v1: V1Client;
  now?: () => Date;
}

/** One MCP server (one per session): lists the tools and runs them against v1. */
export function createMcpServer(options: McpOptions): Server {
  const server = new Server(
    { name: 'gethookd', title: 'GetHookd ad search', version: '1.0.0' },
    { capabilities: { tools: {} }, instructions: INSTRUCTIONS },
  );

  const today = () => (options.now?.() ?? new Date()).toISOString().slice(0, 10);

  // Descriptions carry today's date, so a model can turn "last 30 days" into started_after
  // before its first call. They are built per tools/list, not at startup.
  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: TOOLS.map(({ run: _run, ...tool }) => JSON.parse(JSON.stringify(tool).replaceAll('{{today}}', today()))),
  }));

  server.setRequestHandler(CallToolRequestSchema, async (request, extra) => {
    const tool = TOOLS.find((t) => t.name === request.params.name);
    if (tool === undefined) {
      throw new McpError(ErrorCode.InvalidParams, `Unknown tool ${JSON.stringify(request.params.name)}. Tools: ${TOOLS.map((t) => t.name).join(', ')}.`);
    }

    const args = request.params.arguments ?? {};
    const authorization = header(extra.requestInfo?.headers.authorization);

    try {
      return await tool.run(args, {
        v1: options.v1,
        idempotencyKey: idempotencyKey(extra.sessionId, extra.requestId, tool.name, args),
        today: today(),
        ...(authorization === undefined ? {} : { authorization }),
      });
    } catch (error) {
      logFailure(`${tool.name} failed`, error);
      return errorResult({
        code: 'mcp_internal_error',
        message: 'This MCP server failed while handling the call.',
        next_step: `Call ${tool.name} again in a few seconds. If it keeps failing, tell the user the GetHookd MCP server has a problem.`,
        retryable: true,
      });
    }
  });

  return server;
}

function header(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

/**
 * Logs a failure as its class and message only: never request headers or bodies, and anything
 * that looks like an API key is redacted.
 */
export function logFailure(context: string, error: unknown): void {
  const text = error instanceof Error ? `${error.name}: ${error.message}` : String(error);
  console.error(`[mcp] ${context}: ${text.replace(/ghk(?:_|%5f)[A-Za-z0-9]*/gi, 'ghk_[redacted]').slice(0, 500)}`);
}

export interface HttpOptions extends McpOptions {
  /** Host headers accepted (DNS-rebinding protection), e.g. ["localhost:3001", "127.0.0.1:3001"]. */
  allowedHosts: string[];
  /** Sessions unused for this long are closed. */
  sessionIdleMs?: number;
  maxSessions?: number;
}

/** Larger request bodies are refused; a tool call is a few hundred bytes. */
const MAX_BODY_BYTES = 1_000_000;

interface Session {
  transport: StreamableHTTPServerTransport;
  lastSeen: number;
  /** Open GET (SSE) streams; a session that is listening is not idle. */
  streams: number;
}

export type McpHttpServer = HttpServer & {
  /** Ends every session and its open streams, so close() does not wait on them. */
  closeSessions(): Promise<void>;
};

/**
 * The Streamable HTTP endpoint at /mcp (plus /healthz). Each session has its own MCP server;
 * the API key is read from every request, never stored with the session, so a revoked key
 * fails on the very next call.
 */
export function createHttpServer(options: HttpOptions): McpHttpServer {
  const sessions = new Map<string, Session>();
  const idleMs = options.sessionIdleMs ?? 30 * 60_000;
  const maxSessions = options.maxSessions ?? 1_000;

  const sweep = setInterval(() => {
    const cutoff = Date.now() - idleMs;
    for (const [id, session] of sessions) {
      if (session.streams === 0 && session.lastSeen < cutoff) {
        sessions.delete(id);
        void session.transport.close();
      }
    }
  }, Math.min(idleMs, 60_000));
  sweep.unref();

  const closeSessions = async () => {
    const open = [...sessions.values()];
    sessions.clear();
    await Promise.all(open.map((session) => session.transport.close()));
  };

  const http = createServer((req, res) => {
    handle(req, res).catch((error) => {
      logFailure(`${req.method} ${new URL(req.url ?? '/', 'http://localhost').pathname}`, error);
      if (!res.headersSent) {
        jsonRpcError(res, 500, ErrorCode.InternalError, 'Internal server error.');
      } else {
        res.end();
      }
    });
  });
  http.on('close', () => {
    clearInterval(sweep);
    void closeSessions();
  });

  async function handle(req: IncomingMessage, res: ServerResponse): Promise<void> {
    const path = new URL(req.url ?? '/', 'http://localhost').pathname;

    if (path === '/healthz' && req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end('{"ok":true}');
      return;
    }
    if (path !== '/mcp') {
      jsonRpcError(res, 404, ErrorCode.InvalidRequest, 'Not found. The MCP endpoint is /mcp.');
      return;
    }

    const sessionId = header(req.headers['mcp-session-id']);
    const existing = sessionId === undefined ? undefined : sessions.get(sessionId);

    if (req.method === 'POST') {
      const body = await readJson(req, res);
      if (body === undefined) {
        return;
      }

      if (existing !== undefined) {
        existing.lastSeen = Date.now();
        await existing.transport.handleRequest(req, res, body);
        return;
      }
      if (sessionId !== undefined) {
        // The client should start a new session (MCP spec: 404 for an unknown session).
        jsonRpcError(res, 404, -32001, 'Session not found. Start a new session with initialize.');
        return;
      }
      if (!isInitializeRequest(body)) {
        jsonRpcError(res, 400, ErrorCode.InvalidRequest, 'No session. Send initialize first, then send its Mcp-Session-Id header.');
        return;
      }
      // Only callers with a key may open sessions (v1 checks the key itself on every tool call).
      if (!/^Bearer \S+$/i.test(header(req.headers.authorization) ?? '')) {
        jsonRpcError(res, 401, -32001, 'Send your GetHookd API key as "Authorization: Bearer <key>" in the MCP client config.');
        return;
      }
      if (sessions.size >= maxSessions) {
        evictOldest();
      }

      const transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: () => randomUUID(),
        enableDnsRebindingProtection: true,
        allowedHosts: options.allowedHosts,
        onsessioninitialized: (id) => {
          sessions.set(id, { transport, lastSeen: Date.now(), streams: 0 });
        },
      });
      transport.onclose = () => {
        if (transport.sessionId !== undefined) {
          sessions.delete(transport.sessionId);
        }
      };
      await createMcpServer(options).connect(transport);
      await transport.handleRequest(req, res, body);
      return;
    }

    if (req.method === 'GET' || req.method === 'DELETE') {
      if (existing === undefined) {
        jsonRpcError(res, sessionId === undefined ? 400 : 404, -32001, 'Session not found.');
        return;
      }
      existing.lastSeen = Date.now();
      if (req.method === 'GET') {
        const session = existing;
        session.streams++;
        res.on('close', () => {
          session.streams--;
          session.lastSeen = Date.now();
        });
      }
      await existing.transport.handleRequest(req, res);
      return;
    }

    res.setHeader('Allow', 'GET, POST, DELETE');
    jsonRpcError(res, 405, ErrorCode.InvalidRequest, 'Method not allowed.');
  }

  /** Makes room for a new session by closing the one unused for longest. */
  function evictOldest(): void {
    let oldest: [string, Session] | undefined;
    for (const entry of sessions) {
      if (oldest === undefined || entry[1].lastSeen < oldest[1].lastSeen) {
        oldest = entry;
      }
    }
    if (oldest !== undefined) {
      sessions.delete(oldest[0]);
      void oldest[1].transport.close();
    }
  }

  return Object.assign(http, { closeSessions });
}

/** The parsed JSON body, or undefined after answering with an error. */
async function readJson(req: IncomingMessage, res: ServerResponse): Promise<unknown> {
  const body = await new Promise<Buffer | undefined>((resolve, reject) => {
    const chunks: Buffer[] = [];
    let size = 0;
    req.on('data', (chunk: Buffer) => {
      if (size > MAX_BODY_BYTES) {
        return; // already answered; the rest is read and dropped
      }
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        // Answer now but keep reading the upload: destroying the socket while the client is
        // still sending makes it see a connection reset (EPIPE) instead of the 413.
        res.setHeader('Connection', 'close');
        jsonRpcError(res, 413, ErrorCode.InvalidRequest, 'Request body too large.');
        resolve(undefined);
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(size > MAX_BODY_BYTES ? undefined : Buffer.concat(chunks)));
    req.on('error', reject);
  });
  if (body === undefined) {
    return undefined;
  }

  try {
    return JSON.parse(body.toString('utf8'));
  } catch {
    jsonRpcError(res, 400, ErrorCode.ParseError, 'The body is not valid JSON.');
    return undefined;
  }
}

function jsonRpcError(res: ServerResponse, status: number, code: number, message: string): void {
  res.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify({ jsonrpc: '2.0', error: { code, message }, id: null }));
}
