import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { FakeV1, json, payload, startHarness, type Harness } from './helpers.js';

let h: Harness;

beforeEach(async () => {
  h = await startHarness();
});

afterEach(async () => {
  await h.stop();
});

describe('every tool call becomes a v1 request with the caller\'s key', () => {
  it.each([
    ['search_ads', {}, '/api/v1/ads'],
    ['get_ad', { id: 42 }, '/api/v1/ads/42'],
    ['list_niches', {}, '/api/v1/niches'],
  ])('%s forwards the Authorization header unchanged, says it is the MCP client, and is a GET', async (tool, args, path) => {
    const client = await h.connect({ Authorization: 'Bearer ghk_caller_one' });

    await client.callTool({ name: tool, arguments: args });

    expect(h.v1.requests).toHaveLength(1);
    const seen = h.v1.requests[0]!;
    expect(seen.method).toBe('GET');
    expect(seen.path).toBe(path);
    expect(seen.headers.authorization).toBe('Bearer ghk_caller_one');
    expect(seen.headers['x-client']).toBe('mcp');
    expect(seen.headers['idempotency-key']).toMatch(/^mcp-[0-9a-f]{64}$/);
  });

  it('uses the key of each request, not of the session, so a changed or revoked key applies at once', async () => {
    const client = await h.connect({ Authorization: 'Bearer ghk_first' });
    await client.callTool({ name: 'search_ads', arguments: {} });

    // Same session, next HTTP request carries another key.
    const sessionId = (client.transport as { sessionId?: string }).sessionId!;
    const res = await fetch(h.mcpUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json, text/event-stream',
        'Mcp-Session-Id': sessionId,
        'Mcp-Protocol-Version': '2025-06-18',
        Authorization: 'Bearer ghk_second',
      },
      body: JSON.stringify({ jsonrpc: '2.0', id: 99, method: 'tools/call', params: { name: 'search_ads', arguments: {} } }),
    });
    await res.text();

    expect(h.v1.requests.map((r) => r.headers.authorization)).toEqual(['Bearer ghk_first', 'Bearer ghk_second']);
  });

  it('sends no key of its own when a call comes without one, so v1 answers unauthenticated', async () => {
    h.v1.reply((_req, res) => json(res, 401, { error: { code: 'unauthenticated', message: 'The API key is missing, invalid or revoked.', next_step: 'x', retryable: false } }));
    const client = await h.connect();
    const sessionId = (client.transport as { sessionId?: string }).sessionId!;

    const res = await fetch(h.mcpUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'Mcp-Session-Id': sessionId, 'Mcp-Protocol-Version': '2025-06-18' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 9, method: 'tools/call', params: { name: 'search_ads', arguments: {} } }),
    });
    const text = await res.text();

    expect(h.v1.requests[0]!.headers.authorization).toBeUndefined();
    expect(text).toContain('unauthenticated');
  });

  it('refuses to connect without a key, before any call reaches v1', async () => {
    await expect(h.connect({})).rejects.toThrow();
    expect(h.v1.requests).toHaveLength(0);
  });
});

describe('arguments are sent to v1 as they are, for v1 to check', () => {
  it('sends every search argument as a query parameter, with niche as comma-separated slugs', async () => {
    const client = await h.connect();

    await client.callTool({
      name: 'search_ads',
      arguments: { q: 'serum glow', niche: ['skincare', 'haircare'], format: 'video', status: 'active', started_after: '2026-10-01', sort: 'impressions', limit: 5 },
    });

    expect(Object.fromEntries(h.v1.requests[0]!.query)).toEqual({
      q: 'serum glow',
      niche: 'skincare,haircare',
      format: 'video',
      status: 'active',
      started_after: '2026-10-01',
      sort: 'impressions',
      limit: '5',
    });
  });

  it('forwards unknown and misspelled arguments instead of dropping them, so v1 rejects them', async () => {
    const client = await h.connect();

    await client.callTool({ name: 'search_ads', arguments: { nich: 'skincare', colour: 'red' } });
    await client.callTool({ name: 'get_ad', arguments: { id: 3, verbose: true } });
    await client.callTool({ name: 'list_niches', arguments: { lang: 'en' } });

    expect(h.v1.requests.map((r) => r.url)).toEqual([
      '/api/v1/ads?nich=skincare&colour=red&limit=10',
      '/api/v1/ads/3?verbose=true',
      '/api/v1/niches?lang=en',
    ]);
  });

  it('sends a null argument as an empty value for v1 to reject, instead of dropping it', async () => {
    const client = await h.connect();

    await client.callTool({ name: 'search_ads', arguments: { format: null, q: 'tent' } });

    expect(h.v1.requests[0]!.url).toBe('/api/v1/ads?format=&q=tent&limit=10');
  });

  it('does not follow a redirect with the caller\'s key', async () => {
    const redirect = (_req: unknown, res: import('node:http').ServerResponse) => res.writeHead(302, { Location: '/api/v1/niches' }).end();
    h.v1.reply(redirect, redirect);
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: {} });

    expect(h.v1.requests.every((r) => r.path === '/api/v1/ads')).toBe(true);
    expect(payload(result).error.code).toBe('upstream_invalid_response');
  });

  it('does not normalise values: a display name goes to v1 as sent', async () => {
    const client = await h.connect();

    await client.callTool({ name: 'search_ads', arguments: { niche: 'Pet Supplies', format: 'VIDEO' } });

    expect(h.v1.requests[0]!.query.get('niche')).toBe('Pet Supplies');
    expect(h.v1.requests[0]!.query.get('format')).toBe('VIDEO');
  });

  it('asks for 10 results on a new search without limit, leaves the page size to the cursor, and passes the cursor through untouched', async () => {
    const client = await h.connect();

    await client.callTool({ name: 'search_ads', arguments: {} });
    await client.callTool({ name: 'search_ads', arguments: { cursor: 'eyJ.abc-_' } });
    await client.callTool({ name: 'search_ads', arguments: { cursor: 'eyJ.abc-_', limit: 3 } });

    expect(h.v1.requests.map((r) => Object.fromEntries(r.query))).toEqual([
      { limit: '10' },
      { cursor: 'eyJ.abc-_' },
      { cursor: 'eyJ.abc-_', limit: '3' },
    ]);
  });

  it('refuses a limit above 25 without calling v1, with the corrected call', async () => {
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: { q: 'tent', limit: 100 } });

    expect(h.v1.requests).toHaveLength(0);
    expect(result.isError).toBe(true);
    const error = payload(result).error;
    expect(error).toMatchObject({ code: 'invalid_parameters', retryable: false, corrected_arguments: { q: 'tent', limit: 25 } });
  });

  it('allows a limit of exactly 25', async () => {
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: { limit: 25 } });

    expect(result.isError).toBeFalsy();
    expect(h.v1.requests[0]!.query.get('limit')).toBe('25');
  });

  it('puts the get_ad id in the path encoded, so it cannot reach another endpoint', async () => {
    const client = await h.connect();

    await client.callTool({ name: 'get_ad', arguments: { id: '1/../../niches?x=1' } });

    expect(h.v1.requests[0]!.url).toBe('/api/v1/ads/1%2F..%2F..%2Fniches%3Fx%3D1');
  });

  it.each([[undefined], [''], ['.'], ['..'], ['%2E%2e']])('refuses get_ad id %j without calling v1', async (id) => {
    const client = await h.connect();

    const result = await client.callTool({ name: 'get_ad', arguments: id === undefined ? {} : { id } });

    expect(h.v1.requests).toHaveLength(0);
    expect(result.isError).toBe(true);
    expect(payload(result).error.code).toBe('invalid_parameters');
  });
});

describe('Idempotency-Key: one per tool call', () => {
  it('differs between two calls with the same arguments, so each call is billed', async () => {
    const client = await h.connect();

    await client.callTool({ name: 'search_ads', arguments: { q: 'serum' } });
    await client.callTool({ name: 'search_ads', arguments: { q: 'serum' } });

    const [first, second] = h.v1.requests.map((r) => r.headers['idempotency-key']);
    expect(first).not.toBe(second);
  });

  it('is the same when the client re-sends the same message (same session and request id)', async () => {
    const client = await h.connect();
    const sessionId = (client.transport as { sessionId?: string }).sessionId!;
    const send = async () => {
      const res = await fetch(h.mcpUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json, text/event-stream',
          'Mcp-Session-Id': sessionId,
          'Mcp-Protocol-Version': '2025-06-18',
          Authorization: 'Bearer ghk_test_key',
        },
        body: JSON.stringify({ jsonrpc: '2.0', id: 'call-7', method: 'tools/call', params: { name: 'search_ads', arguments: { q: 'serum' } } }),
      });
      await res.text();
    };

    await send();
    await send();

    expect(h.v1.requests).toHaveLength(2);
    expect(h.v1.requests[0]!.headers['idempotency-key']).toBe(h.v1.requests[1]!.headers['idempotency-key']);
  });

  it('differs between sessions that happen to use the same request id', async () => {
    const one = await h.connect();
    const two = await h.connect();

    await one.callTool({ name: 'search_ads', arguments: {} });
    await two.callTool({ name: 'search_ads', arguments: {} });

    const [first, second] = h.v1.requests.map((r) => r.headers['idempotency-key']);
    expect(first).not.toBe(second);
  });
});

describe('retries', () => {
  it.each([500, 502, 503, 504])('retries once after a %i with the same Idempotency-Key, then returns the result', async (status) => {
    h.v1.reply((_req, res) => json(res, status, { error: { code: 'unavailable', message: 'm', next_step: 'n', retryable: true } }));
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: { q: 'serum' } });

    expect(result.isError).toBeFalsy();
    expect(h.v1.requests).toHaveLength(2);
    expect(h.v1.requests[0]!.headers['idempotency-key']).toBe(h.v1.requests[1]!.headers['idempotency-key']);
  });

  it('retries once after the connection drops, with the same Idempotency-Key', async () => {
    h.v1.reply((_req, res) => res.socket?.destroy());
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: {} });

    expect(result.isError).toBeFalsy();
    expect(h.v1.requests).toHaveLength(2);
    expect(h.v1.requests[0]!.headers['idempotency-key']).toBe(h.v1.requests[1]!.headers['idempotency-key']);
  });

  it.each([400, 401, 403, 404, 422, 429])('does not retry a %i', async (status) => {
    h.v1.reply((_req, res) => json(res, status, { error: { code: 'x', message: 'm', next_step: 'n', retryable: status === 429, retry_after: 5 } }, { 'Retry-After': '5' }));
    const client = await h.connect();

    await client.callTool({ name: 'search_ads', arguments: {} });

    expect(h.v1.requests).toHaveLength(1);
  });

  it('gives up after one retry and passes v1\'s error on', async () => {
    const failing = (_req: unknown, res: import('node:http').ServerResponse) =>
      json(res, 503, { error: { code: 'unavailable', message: 'The service is temporarily unavailable.', next_step: 'n', retryable: true } });
    h.v1.reply(failing, failing, failing);
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: {} });

    expect(h.v1.requests).toHaveLength(2);
    expect(result.isError).toBe(true);
    expect(payload(result).error).toMatchObject({ code: 'unavailable', retryable: true });
  });
});

describe('when v1 cannot be reached', () => {
  it('times out, retries once, then says upstream_unavailable and that retrying may help', async () => {
    await h.stop();
    const hanging = new FakeV1(() => {
      /* never answers */
    });
    h = await startHarness(hanging, { timeoutMs: 200 });
    const client = await h.connect();

    const started = Date.now();
    const result = await client.callTool({ name: 'search_ads', arguments: {} });

    expect(Date.now() - started).toBeLessThan(2_000);
    expect(hanging.requests).toHaveLength(2);
    expect(hanging.requests[0]!.headers['idempotency-key']).toBe(hanging.requests[1]!.headers['idempotency-key']);
    expect(payload(result).error).toMatchObject({ code: 'upstream_unavailable', retryable: true });
  });

  it('retries an answer that is not JSON once, then says upstream_invalid_response', async () => {
    const html = (_req: unknown, res: import('node:http').ServerResponse) => res.writeHead(200, { 'Content-Type': 'text/html' }).end('<html>');
    h.v1.reply(html, html);
    const client = await h.connect();

    const result = await client.callTool({ name: 'get_ad', arguments: { id: 1 } });

    expect(h.v1.requests).toHaveLength(2);
    expect(payload(result).error).toMatchObject({ code: 'upstream_invalid_response', retryable: true });
  });
});
