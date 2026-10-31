import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { AD_BUDGET, clip, compactAd, fullAd, MAX_LIMIT, PAGE_BUDGET, RESULT_BUDGET } from '../src/budget.js';
import { ad, json, startHarness, type Harness } from './helpers.js';

/** Copy that is as expensive as possible once serialised: quotes and backslashes double, control characters become \u00XX. */
const nasty = (n: number) => '"\\\u0001😀'.repeat(n);

const worstAd = (id: number) =>
  ad({
    id,
    brand_name: nasty(500),
    headline: nasty(5_000),
    body_text: nasty(50_000),
    countries: Array.from({ length: 300 }, () => 'XX"\\'),
  });

describe('clip', () => {
  it('keeps short text as it is', () => {
    expect(clip('Vitamin C serum', 120)).toBe('Vitamin C serum');
  });

  it('cuts long text to the limit, ending in …', () => {
    const out = clip('a'.repeat(500), 120);
    expect(Array.from(out)).toHaveLength(120);
    expect(out.endsWith('…')).toBe(true);
  });

  it('never cuts an emoji in half', () => {
    const out = clip('😀'.repeat(10), 5);
    expect(out).toBe('😀😀😀😀…');
  });

  it('turns control characters and whitespace runs into one space', () => {
    expect(clip('a\n\n\tb\u0000c', 20)).toBe('a b c');
  });
});

describe('per-result budget', () => {
  it(`keeps every search result within ${RESULT_BUDGET} characters, however long the copy`, () => {
    expect(JSON.stringify(compactAd(worstAd(1))).length).toBeLessThanOrEqual(RESULT_BUDGET);
  });

  it('keeps headline and body of normal ads in full', () => {
    const normal = ad({ headline: 'h'.repeat(120), body_text: 'b'.repeat(160) });
    const out = compactAd(normal);
    expect(out.headline).toBe('h'.repeat(120));
    expect(out.body).toBe('b'.repeat(160));
  });

  it('cuts long body text to 160 characters in search results', () => {
    expect(Array.from(compactAd(ad({ body_text: 'b'.repeat(1_000) })).body as string)).toHaveLength(160);
  });

  it(`keeps get_ad within ${AD_BUDGET} characters, and keeps up to 2,000 characters of body`, () => {
    expect(JSON.stringify(fullAd(worstAd(1))).length).toBeLessThanOrEqual(AD_BUDGET);
    expect(fullAd(ad({ body_text: 'b'.repeat(2_000) })).body).toBe('b'.repeat(2_000));
    expect(Array.from(fullAd(ad({ body_text: 'b'.repeat(9_000) })).body as string)).toHaveLength(2_000);
  });
});

describe('whole responses', () => {
  let h: Harness;

  beforeEach(async () => {
    h = await startHarness();
  });

  afterEach(async () => {
    await h.stop();
  });

  it(`keeps a full page of ${MAX_LIMIT} worst-case results within ${PAGE_BUDGET} characters`, async () => {
    h.v1.reply((_req, res) =>
      json(res, 200, {
        data: Array.from({ length: MAX_LIMIT }, (_, i) => worstAd(i)),
        // v1 caps q at 200 characters; the echo and the cursor carry it.
        applied_filters: { q: nasty(50), niche: ['skincare', 'haircare', 'supplements', 'fitness', 'pet-supplies', 'home-decor', 'kitchen', 'apparel', 'jewelry', 'baby-kids', 'outdoor', 'tech-accessories'], format: 'carousel', status: 'inactive', started_after: '2026-01-01' },
        sort: 'impressions',
        sort_note: 'x'.repeat(300),
        limit: MAX_LIMIT,
        next_cursor: 'c'.repeat(1_500),
      }),
    );
    const client = await h.connect();

    const result = await client.callTool({ name: 'search_ads', arguments: { limit: MAX_LIMIT } });

    const text = (result.content as Array<{ text: string }>)[0]!.text;
    expect(text.length).toBeLessThanOrEqual(PAGE_BUDGET);
  });

  it(`keeps get_ad of a worst-case ad within ${AD_BUDGET} characters`, async () => {
    h.v1.reply((_req, res) => json(res, 200, { data: worstAd(1) }));
    const client = await h.connect();

    const result = await client.callTool({ name: 'get_ad', arguments: { id: 1 } });

    // The tool adds "today" around the ad.
    expect((result.content as Array<{ text: string }>)[0]!.text.length).toBeLessThanOrEqual(AD_BUDGET + 40);
  });
});
