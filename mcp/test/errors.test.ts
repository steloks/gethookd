import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { json, payload, startHarness, v1Error, type Harness } from './helpers.js';
import { errorCodes, retryableCodes } from './laravel.js';

let h: Harness;

beforeEach(async () => {
  h = await startHarness();
});

afterEach(async () => {
  await h.stop();
});

describe('v1 error codes reach the model unchanged', () => {
  it('reads the 12 codes from the Laravel registry', () => {
    expect(errorCodes()).toHaveLength(12);
    expect(retryableCodes().sort()).toEqual(['internal_error', 'rate_limited', 'unavailable']);
  });

  it.each(errorCodes())('%s: same code, message and retryable as v1, as a tool error', async (code) => {
    const retryable = retryableCodes().includes(code);
    // 5xx codes are retried once; answer both attempts the same way.
    const reply = v1Error(code === 'internal_error' ? 500 : code === 'unavailable' ? 503 : 422, {
      code,
      message: `v1 says ${code}`,
      retryable,
      ...(code === 'rate_limited' ? { retry_after: 17 } : {}),
      ...(code === 'quota_exceeded' ? { resets_at: '2026-11-01T00:00:00Z' } : {}),
    });
    h.v1.reply(reply, reply);
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: { q: 'serum' } });

    expect(result.isError).toBe(true);
    const error = payload(result).error;
    expect(error.code).toBe(code);
    expect(error.message).toBe(`v1 says ${code}`);
    expect(error.retryable).toBe(retryable);
    expect(typeof error.next_step).toBe('string');
    expect(error.next_step.length).toBeGreaterThan(10);
  });
});

describe('next_step tells a literal reader what to do', () => {
  it('rate_limited: how many seconds to wait, from v1\'s retry_after', async () => {
    h.v1.reply(v1Error(429, { code: 'rate_limited', retryable: true, retry_after: 23 }, { 'Retry-After': '23' }));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'search_ads', arguments: {} })).error;

    expect(error).toMatchObject({ code: 'rate_limited', retryable: true, retry_after: 23 });
    expect(error.next_step).toContain('Wait 23 seconds');
  });

  it('quota_exceeded: do not retry, and when it resets', async () => {
    h.v1.reply(v1Error(429, { code: 'quota_exceeded', retryable: false, resets_at: '2026-11-01T00:00:00Z' }));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'get_ad', arguments: { id: 1 } })).error;

    expect(error).toMatchObject({ code: 'quota_exceeded', retryable: false, resets_at: '2026-11-01T00:00:00Z' });
    expect(error.next_step).toMatch(/^Do not retry/);
    expect(error.next_step).toContain('2026-11-01T00:00:00Z');
  });

  it('unauthenticated: do not retry, the user must fix the key in the MCP config', async () => {
    h.v1.reply(v1Error(401, { code: 'unauthenticated' }));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'list_niches', arguments: {} })).error;

    expect(error.next_step).toMatch(/^Do not retry/);
    expect(error.next_step).toContain('Authorization: Bearer');
  });

  it('not_found on get_ad: names the id and says where ids come from', async () => {
    h.v1.reply(v1Error(404, { code: 'not_found' }));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'get_ad', arguments: { id: 999999 } })).error;

    expect(error.next_step).toContain('999999');
    expect(error.next_step).toContain('search_ads');
  });

  it('never repeats the caller\'s key', async () => {
    h.v1.reply(v1Error(401, { code: 'unauthenticated' }));
    const client = await h.connect({ Authorization: 'Bearer ghk_secret_value_123' });

    const result = await client.callTool({ name: 'search_ads', arguments: {} });

    expect(JSON.stringify(result)).not.toContain('ghk_secret_value_123');
  });
});

describe('corrected_arguments: the whole call to make instead, when v1 knows the fix', () => {
  const invalid = (details: unknown[]) => v1Error(422, { code: 'invalid_parameters', details });

  it('fixes a misspelled argument name, keeping its value', async () => {
    h.v1.reply(invalid([{ param: 'nich', issue: 'unknown_parameter', message: 'm', did_you_mean: 'niche' }]));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'search_ads', arguments: { q: 'serum', nich: ['skincare'] } })).error;

    expect(error.corrected_arguments).toEqual({ q: 'serum', niche: ['skincare'] });
    expect(error.next_step).toContain('corrected_arguments');
  });

  it('fixes values: niche as a list of slugs, limit as a number, others as strings', async () => {
    h.v1.reply(
      invalid([
        { param: 'niche', issue: 'invalid_value', message: 'm', did_you_mean: 'pet-supplies,baby-kids' },
        { param: 'format', issue: 'invalid_value', message: 'm', did_you_mean: 'video' },
        { param: 'started_after', issue: 'invalid_value', message: 'm', did_you_mean: '2026-10-01' },
      ]),
    );
    const client = await h.connect();

    const error = payload(
      await client.callTool({ name: 'search_ads', arguments: { niche: ['Pet Supplies', 'Baby & Kids'], format: 'vidoe', started_after: '2026/10/01', status: 'active' } }),
    ).error;

    expect(error.corrected_arguments).toEqual({ niche: ['pet-supplies', 'baby-kids'], format: 'video', started_after: '2026-10-01', status: 'active' });
  });

  it('drops an empty argument', async () => {
    h.v1.reply(invalid([{ param: 'q', issue: 'empty_value', message: 'm' }]));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'search_ads', arguments: { q: '', format: 'video' } })).error;

    expect(error.corrected_arguments).toEqual({ format: 'video' });
  });

  it('offers none when one problem has no known fix, and passes v1\'s details on', async () => {
    const details = [
      { param: 'format', issue: 'invalid_value', message: 'm', allowed: ['image', 'video', 'carousel'], did_you_mean: 'video' },
      { param: 'colour', issue: 'unknown_parameter', message: 'm', allowed: ['q'] },
    ];
    h.v1.reply(invalid(details));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'search_ads', arguments: { format: 'vidoe', colour: 'red' } })).error;

    expect(error.corrected_arguments).toBeUndefined();
    expect(error.details).toEqual(details);
    expect(error.next_step).toContain('details');
  });

  it('offers none when v1 may have left problems out (10 details is v1\'s maximum)', async () => {
    const details = Array.from({ length: 10 }, (_, i) => ({ param: `p${i}`, issue: 'unknown_parameter', message: 'm', did_you_mean: 'q' }));
    h.v1.reply(invalid(details));
    const client = await h.connect();

    const args = Object.fromEntries(details.map((d) => [d.param, 'x']));
    const error = payload(await client.callTool({ name: 'search_ads', arguments: args })).error;

    expect(error.corrected_arguments).toBeUndefined();
  });

  it('on a cursor that belongs to another search, says how to continue or restart', async () => {
    h.v1.reply(invalid([{ param: 'cursor', issue: 'cursor_mismatch', message: 'm' }]));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'search_ads', arguments: { cursor: 'c', format: 'image' } })).error;

    expect(error.corrected_arguments).toBeUndefined();
    expect(error.next_step).toContain('{"cursor": <the cursor>}');
    expect(error.next_step).toContain('without cursor');
  });

  it('turns a v1 response not in the error shape into upstream_invalid_response', async () => {
    h.v1.reply((_req, res) => json(res, 404, { message: 'Not Found' }));
    const client = await h.connect();

    const error = payload(await client.callTool({ name: 'get_ad', arguments: { id: 1 } })).error;

    expect(error.code).toBe('upstream_invalid_response');
  });
});
