import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { once } from 'node:events';
import { createServer, type Server as HttpServer } from 'node:http';
import type { AddressInfo } from 'node:net';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createHttpServer } from '../../src/server.js';
import { createV1Client } from '../../src/v1Client.js';
import { payload } from '../helpers.js';

/*
 * The MCP server in front of the real Laravel API (test database gethookd_test, Redis
 * database 15), proving that MCP shares auth, revocation, the rate limit and billing with REST.
 * Needs Postgres and Redis running (make up); run with `npm run test:e2e`.
 */

const apiDir = fileURLToPath(new URL('../../../api', import.meta.url));
const script = fileURLToPath(new URL('./laravel.php', import.meta.url));
const env = {
  ...process.env,
  APP_ENV: 'testing',
  DB_DATABASE: 'gethookd_test',
  DB_URL: '',
  REDIS_DB: '15',
  REDIS_URL: '',
  CACHE_STORE: 'array',
  GETHOOKD_API_DIR: apiDir,
};

const laravel = (...args: string[]) => execFileSync('php', [script, ...args], { env, encoding: 'utf8' });
const prefix = (key: string) => key.slice(0, 12);
const billed = (key: string): { total: number; mcp: number } => JSON.parse(laravel('billed', prefix(key)));
const lastMcpCall = (key: string): string | null => JSON.parse(laravel('mcp-call', prefix(key))).last_mcp_call_at;

let php: ChildProcess;
let proxy: HttpServer;
let mcp: HttpServer;
let apiUrl: string;
let mcpUrl: string;
let keys: Record<string, string>;
/** When set, the proxy lets the next /api/v1/ads request reach Laravel, then drops the connection before the answer. */
let dropNextAdsResponse = false;
const clients: Client[] = [];

async function freePort(): Promise<number> {
  const probe = createServer().listen(0, '127.0.0.1');
  await once(probe, 'listening');
  const port = (probe.address() as AddressInfo).port;
  probe.close();
  return port;
}

async function connect(key: string): Promise<Client> {
  const client = new Client({ name: 'e2e', version: '1.0.0' });
  await client.connect(new StreamableHTTPClientTransport(new URL(mcpUrl), { requestInit: { headers: { Authorization: `Bearer ${key}` } } }));
  clients.push(client);
  return client;
}

const rest = (path: string, key: string) => fetch(`${apiUrl}${path}`, { headers: { Authorization: `Bearer ${key}`, Accept: 'application/json' } });

beforeAll(async () => {
  keys = JSON.parse(laravel('setup', 'billing', 'revoke', 'limit', 'retry', 'connect'));

  const port = await freePort();
  apiUrl = `http://127.0.0.1:${port}`;
  php = spawn('php', ['-S', `127.0.0.1:${port}`, `${apiDir}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`], {
    cwd: `${apiDir}/public`,
    env,
    stdio: 'ignore',
  });
  for (let i = 0; ; i++) {
    try {
      await fetch(`${apiUrl}/api/v1/niches`);
      break;
    } catch {
      if (i > 100) throw new Error('The Laravel test server did not start.');
      await new Promise((r) => setTimeout(r, 100));
    }
  }

  // A pass-through proxy between the MCP server and Laravel that can lose one answer.
  proxy = createServer(async (req, res) => {
    const upstream = await fetch(`${apiUrl}${req.url}`, { headers: req.headers as Record<string, string> });
    const body = Buffer.from(await upstream.arrayBuffer());
    if (dropNextAdsResponse && req.url?.startsWith('/api/v1/ads')) {
      dropNextAdsResponse = false;
      res.socket?.destroy();
      return;
    }
    res.writeHead(upstream.status, Object.fromEntries([...upstream.headers].filter(([name]) => name !== 'content-encoding' && name !== 'transfer-encoding')));
    res.end(body);
  }).listen(0, '127.0.0.1');
  await once(proxy, 'listening');

  const allowedHosts: string[] = [];
  mcp = createHttpServer({
    v1: createV1Client({ baseUrl: `http://127.0.0.1:${(proxy.address() as AddressInfo).port}`, timeoutMs: 10_000, retryDelayMs: 50 }),
    allowedHosts,
  }).listen(0, '127.0.0.1');
  await once(mcp, 'listening');
  allowedHosts.push(`127.0.0.1:${(mcp.address() as AddressInfo).port}`);
  mcpUrl = `http://127.0.0.1:${(mcp.address() as AddressInfo).port}/mcp`;
}, 60_000);

afterAll(async () => {
  await Promise.all(clients.map((c) => c.close().catch(() => undefined)));
  mcp?.closeAllConnections();
  mcp?.close();
  proxy?.closeAllConnections();
  proxy?.close();
  php?.kill();
});

describe('MCP on the real API', () => {
  it('bills search_ads and get_ad once each, as MCP calls, and never list_niches', async () => {
    const client = await connect(keys.billing!);

    await client.callTool({ name: 'list_niches', arguments: {} });
    const search = payload(await client.callTool({ name: 'search_ads', arguments: { limit: 3 } }));
    expect(search.count).toBe(3);
    const ad = payload(await client.callTool({ name: 'get_ad', arguments: { id: search.results[0].id } }));
    expect(ad.ad.id).toBe(search.results[0].id);

    expect(billed(keys.billing!)).toEqual({ total: 2, mcp: 2 });
  });

  it('marks the key as connected on its first MCP call, even a free one (the developer page\'s check)', async () => {
    expect(lastMcpCall(keys.connect!)).toBeNull();
    const client = await connect(keys.connect!);

    await client.callTool({ name: 'list_niches', arguments: {} });

    expect(lastMcpCall(keys.connect!)).not.toBeNull();
    expect(billed(keys.connect!)).toEqual({ total: 0, mcp: 0 });
  });

  it('passes v1\'s did-you-mean through as corrected_arguments', async () => {
    const client = await connect(keys.billing!);

    const error = payload(await client.callTool({ name: 'search_ads', arguments: { nich: ['kitchen'], format: 'vidoe' } })).error;

    expect(error.code).toBe('invalid_parameters');
    expect(error.corrected_arguments).toEqual({ niche: ['kitchen'], format: 'video' });
  });

  it('refuses a revoked key on the very next call, in the same MCP session', async () => {
    const client = await connect(keys.revoke!);
    expect((await client.callTool({ name: 'search_ads', arguments: {} })).isError).toBeFalsy();

    laravel('revoke', prefix(keys.revoke!));

    const result = await client.callTool({ name: 'search_ads', arguments: {} });
    expect(result.isError).toBe(true);
    expect(payload(result).error).toMatchObject({ code: 'unauthenticated', retryable: false });
    expect((await rest('/api/v1/ads', keys.revoke!)).status).toBe(401);
  });

  it('shares one 60-per-minute limit between REST and MCP, and says how long to wait', async () => {
    // Stay inside one clock minute: 60 requests take a few seconds.
    const second = new Date().getUTCSeconds();
    if (second > 40) {
      await new Promise((r) => setTimeout(r, (61 - second) * 1_000));
    }
    const client = await connect(keys.limit!);

    for (let i = 0; i < 59; i++) {
      expect((await rest('/api/v1/niches', keys.limit!)).status).toBe(200);
    }
    expect((await client.callTool({ name: 'list_niches', arguments: {} })).isError).toBeFalsy();

    const error = payload(await client.callTool({ name: 'search_ads', arguments: {} })).error;
    expect(error).toMatchObject({ code: 'rate_limited', retryable: true });
    expect(error.retry_after).toBeGreaterThanOrEqual(1);
    expect(error.retry_after).toBeLessThanOrEqual(60);
    expect(error.next_step).toContain(`Wait ${error.retry_after} seconds`);
    expect((await rest('/api/v1/niches', keys.limit!)).status).toBe(429);
  }, 90_000);

  it('bills a call once when its answer was lost and the MCP server retried it', async () => {
    const client = await connect(keys.retry!);
    dropNextAdsResponse = true;

    const result = await client.callTool({ name: 'search_ads', arguments: { limit: 2 } });

    expect(result.isError).toBeFalsy();
    expect(dropNextAdsResponse).toBe(false); // the first answer really was dropped
    expect(billed(keys.retry!)).toEqual({ total: 1, mcp: 1 });
  });
});
