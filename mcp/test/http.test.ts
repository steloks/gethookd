import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { startHarness, type Harness } from './helpers.js';

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
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...headers },
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
});
