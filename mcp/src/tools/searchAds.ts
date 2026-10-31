import { compactAd, DEFAULT_LIMIT, MAX_LIMIT, type V1Ad } from '../budget.js';
import { errorResult } from '../errors.js';
import { callV1, textResult, toQuery, type ToolDefinition } from './tool.js';

export const searchAds: ToolDefinition = {
  name: 'search_ads',
  title: 'Search ads',
  description: `Search the GetHookd ad library (ads that brands run, with their copy, niche, format, status and dates). Returns up to \`limit\` compact results (default ${DEFAULT_LIMIT}, max ${MAX_LIMIT}; about 150 tokens each) and a \`next_cursor\` for the next page.

Use it to find ads by keyword, niche, format, status or start date. There is no brand filter: q searches the ad copy only, not brand names.
Do not use it for:
- one ad's full text or countries: call get_ad with the id.
- exact totals: no counts are returned; page with the cursor if you must count, and say the number is what you saw.
- ranking brands against each other: impression data only ranks ads WITHIN one brand (impression_rank_in_brand 1 = that brand's most-seen ad). It says nothing about which brand gets more impressions, and there is no spend, reach or click data. Say so instead of guessing.

All arguments are optional and combine with AND. Unknown argument names and invalid values are rejected with the allowed values and corrected_arguments, never ignored.`,
  inputSchema: {
    type: 'object',
    properties: {
      q: {
        type: 'string',
        description: 'Keyword or phrase, matched case-insensitively anywhere in the headline or body text. Up to 200 characters.',
      },
      niche: {
        type: 'array',
        items: { type: 'string' },
        description:
          'Niche slugs; an ad matches if it is in any of them. Slugs only, as list_niches returns them (e.g. "pet-supplies", not "Pet Supplies"): skincare, haircare, supplements, fitness, pet-supplies, home-decor, kitchen, apparel, jewelry, baby-kids, outdoor, tech-accessories.',
      },
      format: { type: 'string', description: 'One of: image, video, carousel.' },
      status: { type: 'string', description: 'One of: active (still running), inactive (stopped).' },
      started_after: {
        type: 'string',
        description:
          'YYYY-MM-DD (UTC). Keeps ads that started running on or after this day. For "the last 30 days" use today minus 30 days; today is in every response from this server (`today`).',
      },
      sort: {
        type: 'string',
        description:
          'newest (default): latest start_date first. impressions: each brand\'s most-seen ad (rank 1) first, then each brand\'s rank 2, and so on; ads without impression data last. impressions is NOT a ranking across brands.',
      },
      limit: { type: 'integer', minimum: 1, maximum: MAX_LIMIT, description: `Results per page, 1 to ${MAX_LIMIT}. Default ${DEFAULT_LIMIT}. Send it again with cursor to keep a page size other than the default.` },
      cursor: {
        type: 'string',
        description:
          'next_cursor from the previous search_ads response, to get the next page. It remembers the search, so send it alone (or with limit). To change the search, leave cursor out.',
      },
    },
    additionalProperties: false,
  },
  annotations: { title: 'Search ads', readOnlyHint: true, openWorldHint: false },

  async run(args, context) {
    const limit = args.limit;
    if ((typeof limit === 'number' || (typeof limit === 'string' && /^\d+$/.test(limit))) && Number(limit) > MAX_LIMIT) {
      // This tool's size budget, not a v1 rule (v1 allows up to 100), so it is checked here.
      return errorResult({
        code: 'invalid_parameters',
        message: `limit is ${MAX_LIMIT} at most in search_ads, so one page stays small enough for the context window.`,
        next_step: 'Call search_ads again with corrected_arguments (the whole argument object), then follow next_cursor for more.',
        retryable: false,
        details: [{ param: 'limit', issue: 'out_of_range', message: `limit must be a whole number from 1 to ${MAX_LIMIT}.`, did_you_mean: String(MAX_LIMIT) }],
        corrected_arguments: { ...args, limit: MAX_LIMIT },
      });
    }

    const query = toQuery(args);
    if (!('limit' in args)) {
      query.set('limit', String(DEFAULT_LIMIT));
    }

    return callV1('search_ads', args, context, { path: '/api/v1/ads', query }, ({ body }) => {
      const page = body as { data?: unknown; applied_filters?: unknown; sort?: unknown; sort_note?: unknown; next_cursor?: unknown };
      if (!Array.isArray(page?.data)) {
        return null;
      }

      const results = (page.data as V1Ad[]).map(compactAd);
      const nextCursor = typeof page.next_cursor === 'string' ? page.next_cursor : null;

      return textResult({
        today: context.today,
        applied_filters: page.applied_filters,
        sort: page.sort,
        ...(page.sort_note === undefined ? {} : { sort_note: page.sort_note }),
        count: results.length,
        results,
        next_cursor: nextCursor,
        next_step:
          results.length === 0
            ? 'No ads match. Try fewer filters, a broader keyword, or an earlier started_after.'
            : (nextCursor === null
                ? 'This is the last page.'
                : 'More results: call search_ads with {"cursor": next_cursor} (it remembers the search).') +
              ' Call get_ad with an id for the full body text and countries.',
      });
    });
  },
};
