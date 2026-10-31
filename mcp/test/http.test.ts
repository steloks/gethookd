import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { logFailure } from '../src/server.js';
import { FakeV1, startHarness, type Harness } from './helpers.js';

let h: Harness;

beforeEach(async () => {
  h = await startHarness();
});

afterEach(async () => {
  await h.stop();
});

const initialize = {
  jsonrpc: '2.0',
  id: 1,
  method: 'initialize',
  params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'curl', version: '1' } },
};

function post(url: string, body: unknown, headers: Record<string, string> = {}) {
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ghk_test_key', ...headers },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });
}

describe('Streamable HTTP endpoint', () => {
  it('answers /healthz', async () => {
    const res = await fetch(h.mcpUrl.replace('/mcp', '/healthz'));
    expect(res.status).toBe(200);
  });

  it('refuses a Host it does not serve (DNS rebinding)', async () => {
    // fetch sets Host from the URL, so use localhost:<port>, which this test server does not list.
    const res = await post(h.mcpUrl.replace('127.0.0.1', 'localhost'), initialize);
    expect(res.status).toBe(403);
    expect(h.v1.requests).toHaveLength(0);
  });

  it('starts a session on initialize and returns its id', async () => {
    const res = await post(h.mcpUrl, initialize);
    await res.text();
    expect(res.status).toBe(200);
    expect(res.headers.get('mcp-session-id')).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('answers 404 for an unknown session, so the client starts a new one', async () => {
    const res = await post(h.mcpUrl, { jsonrpc: '2.0', id: 2, method: 'tools/list' }, { 'Mcp-Session-Id': '00000000-0000-0000-0000-000000000000' });
    expect(res.status).toBe(404);
  });

  it('answers 400 to anything but initialize without a session', async () => {
    const res = await post(h.mcpUrl, { jsonrpc: '2.0', id: 2, method: 'tools/list' });
    expect(res.status).toBe(400);
  });

  it('refuses a body over 1 MB and invalid JSON', async () => {
    expect((await post(h.mcpUrl, 'x'.repeat(1_100_000))).status).toBe(413);
    expect((await post(h.mcpUrl, '{not json')).status).toBe(400);
  });

  it('ends a session on DELETE', async () => {
    const client = await h.connect();
    const sessionId = (client.transport as { sessionId?: string }).sessionId!;

    const del = await fetch(h.mcpUrl, { method: 'DELETE', headers: { 'Mcp-Session-Id': sessionId, 'Mcp-Protocol-Version': '2025-06-18' } });
    expect(del.status).toBe(200);

    const res = await post(h.mcpUrl, { jsonrpc: '2.0', id: 3, method: 'tools/list' }, { 'Mcp-Session-Id': sessionId, 'Mcp-Protocol-Version': '2025-06-18' });
    expect(res.status).toBe(404);
  });

  it('answers 404 outside /mcp', async () => {
    expect((await fetch(h.mcpUrl.replace('/mcp', '/other'))).status).toBe(404);
  });

  it('refuses to start a session without an API key, so keyless callers cannot fill the session table', async () => {
    const res = await post(h.mcpUrl, initialize, { Authorization: '' });
    expect(res.status).toBe(401);
    expect(await res.text()).toContain('Authorization: Bearer');
    expect((await post(h.mcpUrl, initialize, { Authorization: 'Basic abc' })).status).toBe(401);
  });

  it('when full, closes the session unused for longest instead of refusing new ones', async () => {
    await h.stop();
    h = await startHarness(new FakeV1(), {}, { maxSessions: 2 });
    const first = await h.connect();
    const second = await h.connect();
    await second.listTools();

    const third = await h.connect();

    await expect(third.listTools()).resolves.toBeDefined();
    await expect(second.listTools()).resolves.toBeDefined();
    const firstId = (first.transport as { sessionId?: string }).sessionId!;
    const res = await post(h.mcpUrl, { jsonrpc: '2.0', id: 5, method: 'tools/list' }, { 'Mcp-Session-Id': firstId, 'Mcp-Protocol-Version': '2025-06-18' });
    expect(res.status).toBe(404);
  });

  it('shuts down promptly with an open SSE stream once sessions are closed', async () => {
    const init = await post(h.mcpUrl, initialize);
    await init.text();
    const sessionId = init.headers.get('mcp-session-id')!;
    const controller = new AbortController();
    const stream = await fetch(h.mcpUrl, {
      headers: { Accept: 'text/event-stream', 'Mcp-Session-Id': sessionId, 'Mcp-Protocol-Version': '2025-06-18', Authorization: 'Bearer ghk_test_key' },
      signal: controller.signal,
    });
    expect(stream.status).toBe(200);

    const started = Date.now();
    await h.http.closeSessions();
    await new Promise<void>((resolve) => h.http.close(() => resolve()));

    expect(Date.now() - started).toBeLessThan(1_000);
    controller.abort();
  });

  it('logs failures as class and message only, with anything like a key redacted', () => {
    const log = vi.spyOn(console, 'error').mockImplementation(() => undefined);

    logFailure('search_ads failed', new TypeError('bad value ghk_AbC123secret and GHK%5Fother'));

    expect(log).toHaveBeenCalledWith('[mcp] search_ads failed: TypeError: bad value ghk_[redacted] and ghk_[redacted]');
    log.mockRestore();
  });

  it('closes idle sessions, but not one with an open stream', async () => {
    await h.stop();
    h = await startHarness(new FakeV1(), {}, { sessionIdleMs: 100 });
    const open = async () => {
      const res = await post(h.mcpUrl, initialize);
      await res.text();
      return res.headers.get('mcp-session-id')!;
    };
    const idle = await open();
    const listening = await open();
    const controller = new AbortController();
    await fetch(h.mcpUrl, {
      headers: { Accept: 'text/event-stream', 'Mcp-Session-Id': listening, 'Mcp-Protocol-Version': '2025-06-18', Authorization: 'Bearer ghk_test_key' },
      signal: controller.signal,
    });

    await new Promise((r) => setTimeout(r, 400));

    const ask = (id: string) => post(h.mcpUrl, { jsonrpc: '2.0', id: 7, method: 'tools/list' }, { 'Mcp-Session-Id': id, 'Mcp-Protocol-Version': '2025-06-18' });
    expect((await ask(idle)).status).toBe(404);
    const alive = await ask(listening);
    await alive.text();
    expect(alive.status).toBe(200);
    controller.abort();
  });
});
