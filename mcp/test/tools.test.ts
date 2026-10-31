import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ad, json, payload, startHarness, type Harness } from './helpers.js';
import { formats, nicheSlugs, searchParams, sorts, statuses } from './laravel.js';

let h: Harness;

beforeEach(async () => {
  h = await startHarness();
});

afterEach(async () => {
  await h.stop();
});

async function tools() {
  const client = await h.connect();
  const { tools } = await client.listTools();
  return Object.fromEntries(tools.map((t) => [t.name, t]));
}

describe('tool list', () => {
  it('has exactly search_ads, get_ad and list_niches, all read-only', async () => {
    const list = await tools();

    expect(Object.keys(list).sort()).toEqual(['get_ad', 'list_niches', 'search_ads']);
    for (const tool of Object.values(list)) {
      expect(tool.annotations?.readOnlyHint).toBe(true);
      expect(tool.description!.length).toBeGreaterThan(100);
    }
  });

  it('search_ads takes exactly v1\'s search parameters', async () => {
    const schema = (await tools()).search_ads!.inputSchema;

    expect(Object.keys(schema.properties!).sort()).toEqual(searchParams().sort());
  });

  it('search_ads names every allowed value v1 has, and says niche slugs come from list_niches', async () => {
    const tool = (await tools()).search_ads!;
    const properties = tool.inputSchema.properties as Record<string, { description: string }>;

    for (const slug of nicheSlugs()) {
      expect(properties.niche!.description).toContain(slug);
    }
    expect(properties.niche!.description).toContain('list_niches');
    for (const format of formats()) {
      expect(properties.format!.description).toContain(format);
    }
    for (const status of statuses()) {
      expect(properties.status!.description).toContain(status);
    }
    for (const sort of sorts()) {
      expect(properties.sort!.description).toContain(sort);
    }
  });

  it('puts today\'s date in the search_ads description, so "last 30 days" works before the first call', async () => {
    const tool = (await tools()).search_ads!;

    expect(tool.description).toContain('Today is 2026-10-31 (UTC)');
    expect((tool.inputSchema.properties as Record<string, { description: string }>).started_after!.description).toContain('Today is 2026-10-31 (UTC)');
    expect(JSON.stringify(tool)).not.toContain('{{today}}');
  });

  it('turns an unexpected failure into mcp_internal_error and logs it without the key', async () => {
    const log = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    h.v1.reply((_req, res) => json(res, 200, { data: [null], applied_filters: {}, sort: 'newest', limit: 10, next_cursor: null }));
    const client = await h.connect({ Authorization: 'Bearer ghk_never_in_logs' });

    const result = await client.callTool({ name: 'search_ads', arguments: {} });

    expect(payload(result).error).toMatchObject({ code: 'mcp_internal_error', retryable: true });
    expect(log).toHaveBeenCalled();
    expect(JSON.stringify(log.mock.calls)).toContain('search_ads failed');
    expect(JSON.stringify(log.mock.calls)).not.toContain('ghk_never_in_logs');
    log.mockRestore();
  });

  it('is honest that impressions only rank ads within one brand', async () => {
    const list = await tools();

    expect(list.search_ads!.description).toContain('only ranks ads WITHIN one brand');
    expect((list.search_ads!.inputSchema.properties as Record<string, { description: string }>).sort!.description).toContain('NOT a ranking across brands');
    expect(list.get_ad!.description).toContain('not comparable across brands');
  });

  it('says there is no country filter and that results carry countries, so get_ad is not called per result', async () => {
    const list = await tools();

    expect(list.search_ads!.description).toContain('There is no country filter');
    expect(list.search_ads!.description).toContain('Do not call get_ad on each result to find its countries');
    expect(list.get_ad!.description).toContain('Search results already include each ad\'s countries');
  });

  it('says when not to use each tool', async () => {
    const list = await tools();

    expect(list.search_ads!.description).toContain('Do not use it for');
    expect(list.get_ad!.description).toContain('Do not use it to search');
    expect(list.list_niches!.description).toContain('does not return ads');
  });
});

describe('search_ads output', () => {
  it('is compact results plus applied filters, today, and how to get more', async () => {
    h.v1.reply((_req, res) =>
      json(res, 200, {
        data: [ad({ id: 5, impression_rank: 2, status: 'inactive', end_date: '2026-10-25', days_active: 5 })],
        applied_filters: { niche: ['skincare'] },
        sort: 'impressions',
        sort_note: 'Within-brand rank.',
        limit: 5,
        next_cursor: 'CURSOR',
      }),
    );
    const client = await h.connect();

    const out = payload(await client.callTool({ name: 'search_ads', arguments: { niche: ['skincare'], sort: 'impressions' } }));

    expect(out).toEqual({
      today: '2026-10-31',
      applied_filters: { niche: ['skincare'] },
      sort: 'impressions',
      sort_note: 'Within-brand rank.',
      limit: 5,
      count: 1,
      results: [
        {
          id: 5,
          brand: 'Glow Lab',
          headline: 'Vitamin C serum for a morning glow',
          body: 'Our serum brightens skin in two weeks.',
          niche: 'skincare',
          format: 'video',
          status: 'inactive',
          start_date: '2026-10-20',
          end_date: '2026-10-25',
          days_active: 5,
          impression_rank_in_brand: 2,
          countries: ['US', 'GB'],
        },
      ],
      next_cursor: 'CURSOR',
      next_step: expect.stringContaining('{"cursor": next_cursor} (it remembers the search and the page size of 5)'),
    });
  });

  it('says it is the last page when there is no next cursor, and what to try when nothing matched', async () => {
    h.v1.reply(
      (_req, res) => json(res, 200, { data: [ad()], applied_filters: {}, sort: 'newest', limit: 10, next_cursor: null }),
      (_req, res) => json(res, 200, { data: [], applied_filters: {}, sort: 'newest', limit: 10, next_cursor: null }),
    );
    const client = await h.connect();

    const last = payload(await client.callTool({ name: 'search_ads', arguments: {} }));
    const none = payload(await client.callTool({ name: 'search_ads', arguments: { q: 'zzz' } }));

    expect(last.next_step).toContain('last page');
    expect(none).toMatchObject({ count: 0, results: [], next_cursor: null });
    expect(none.next_step).toContain('No ads match');
  });

  it('treats a 200 without a data list as an invalid upstream response', async () => {
    const odd = (_req: unknown, res: import('node:http').ServerResponse) => json(res, 200, { ads: [] });
    h.v1.reply(odd);
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: {} });

    expect(result.isError).toBe(true);
    expect(payload(result).error.code).toBe('upstream_invalid_response');
  });
});

describe('get_ad and list_niches output', () => {
  it('get_ad returns every field, with the rank named as within-brand', async () => {
    const client = await h.connect();

    const out = payload(await client.callTool({ name: 'get_ad', arguments: { id: 42 } }));

    expect(out).toEqual({
      today: '2026-10-31',
      ad: {
        id: 42,
        brand_id: 7,
        brand: 'Glow Lab',
        headline: 'Vitamin C serum for a morning glow',
        body: 'Our serum brightens skin in two weeks.',
        niche: 'skincare',
        format: 'video',
        status: 'active',
        start_date: '2026-10-20',
        end_date: null,
        days_active: 11,
        impression_rank_in_brand: 1,
        countries: ['US', 'GB'],
        discovered_at: '2026-10-21T08:00:00Z',
      },
    });
  });

  it('list_niches returns slug, name and keywords, and today', async () => {
    const client = await h.connect();

    const out = payload(await client.callTool({ name: 'list_niches', arguments: {} }));

    expect(out.today).toBe('2026-10-31');
    expect(out.niches).toEqual([{ slug: 'skincare', name: 'Skincare', keywords: ['serum', 'glow', 'SPF'] }]);
    expect(out.next_step).toContain('slugs');
  });
});
