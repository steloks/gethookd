import { once } from 'node:events';
import { createServer, type IncomingHttpHeaders, type Server as HttpServer, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import type { V1Ad } from '../src/budget.js';
import { createHttpServer } from '../src/server.js';
import { createV1Client, type V1ClientOptions } from '../src/v1Client.js';

export interface SeenRequest {
  method: string;
  /** Path and query exactly as received. */
  url: string;
  path: string;
  query: URLSearchParams;
  headers: IncomingHttpHeaders;
}

type Reply = (request: SeenRequest, res: ServerResponse) => void;

/**
 * A stand-in for the v1 API that records every request. Replies are taken from a queue, then
 * from the default reply.
 */
export class FakeV1 {
  readonly requests: SeenRequest[] = [];
  private readonly queue: Reply[] = [];
  private server!: HttpServer;
  url = '';

  constructor(private readonly fallback: Reply = (req, res) => json(res, 200, defaultBody(req))) {}

  async start(): Promise<this> {
    this.server = createServer((req, res) => {
      const url = new URL(req.url ?? '/', 'http://v1');
      const seen = { method: req.method ?? '', url: req.url ?? '', path: url.pathname, query: url.searchParams, headers: req.headers };
      this.requests.push(seen);
      (this.queue.shift() ?? this.fallback)(seen, res);
    });
    this.server.listen(0, '127.0.0.1');
    await once(this.server, 'listening');
    this.url = `http://127.0.0.1:${(this.server.address() as AddressInfo).port}`;

    return this;
  }

  /** The next requests get these replies, in order. */
  reply(...replies: Reply[]): this {
    this.queue.push(...replies);
    return this;
  }

  async stop(): Promise<void> {
    this.server.closeAllConnections();
    this.server.close();
  }
}

export function json(res: ServerResponse, status: number, body: unknown, headers: Record<string, string> = {}): void {
  res.writeHead(status, { 'Content-Type': 'application/json', ...headers }).end(JSON.stringify(body));
}

export function v1Error(status: number, error: Record<string, unknown>, headers: Record<string, string> = {}): Reply {
  return (_req, res) => json(res, status, { error: { message: 'm', next_step: 'n', retryable: false, ...error } }, headers);
}

export function ad(overrides: Partial<V1Ad> = {}): V1Ad {
  return {
    id: 1,
    brand_id: 7,
    brand_name: 'Glow Lab',
    headline: 'Vitamin C serum for a morning glow',
    body_text: 'Our serum brightens skin in two weeks.',
    niche: 'skincare',
    format: 'video',
    status: 'active',
    start_date: '2026-10-20',
    end_date: null,
    days_active: 11,
    impression_rank: 1,
    countries: ['US', 'GB'],
    discovered_at: '2026-10-21T08:00:00Z',
    ...overrides,
  };
}

function defaultBody(req: SeenRequest): unknown {
  if (req.path === '/api/v1/niches') {
    return { data: [{ slug: 'skincare', name: 'Skincare', keywords: ['serum', 'glow', 'SPF'] }] };
  }
  if (req.path.startsWith('/api/v1/ads/')) {
    return { data: ad({ id: Number(req.path.split('/').pop()) }) };
  }

  return { data: [ad()], applied_filters: {}, sort: 'newest', limit: 10, next_cursor: null };
}

export interface Harness {
  v1: FakeV1;
  mcpUrl: string;
  connect(headers?: Record<string, string>): Promise<Client>;
  stop(): Promise<void>;
}

/** The real HTTP MCP server in front of a fake v1, and a way to connect real MCP clients to it. */
export async function startHarness(v1 = new FakeV1(), clientOptions: Partial<V1ClientOptions> = {}): Promise<Harness> {
  await v1.start();
  const allowedHosts: string[] = [];
  const http = createHttpServer({
    v1: createV1Client({ baseUrl: v1.url, timeoutMs: 2_000, retryDelayMs: 10, ...clientOptions }),
    allowedHosts,
    now: () => new Date('2026-10-31T12:00:00Z'),
  });
  http.listen(0, '127.0.0.1');
  await once(http, 'listening');
  const port = (http.address() as AddressInfo).port;
  // DNS-rebinding protection checks the Host header; tests connect to 127.0.0.1:<port>.
  allowedHosts.push(`127.0.0.1:${port}`);
  const mcpUrl = `http://127.0.0.1:${port}/mcp`;
  const clients: Client[] = [];

  return {
    v1,
    mcpUrl,
    async connect(headers = { Authorization: 'Bearer ghk_test_key' }) {
      const client = new Client({ name: 'test', version: '1.0.0' });
      await client.connect(new StreamableHTTPClientTransport(new URL(mcpUrl), { requestInit: { headers } }));
      clients.push(client);
      return client;
    },
    async stop() {
      await Promise.all(clients.map((c) => c.close()));
      http.closeAllConnections();
      http.close();
      await v1.stop();
    },
  };
}

/** The JSON a tool returned (its single text block). */
export function payload(result: unknown): any {
  const content = (result as { content: Array<{ type: string; text: string }> }).content;
  return JSON.parse(content[0]!.text);
}
