import { fullAd, type V1Ad } from '../budget.js';
import { errorResult } from '../errors.js';
import { callV1, scalar, textResult, toQuery, type ToolDefinition } from './tool.js';

export const getAd: ToolDefinition = {
  name: 'get_ad',
  title: 'Get one ad',
  description: `Every field of one ad, by the id from a search_ads result: full headline and body text (up to 2,000 characters), all countries, brand_id, start and end dates, days_active, discovered_at and impression_rank_in_brand (1 = this brand's most-seen ad; not comparable across brands).

Do not use it to search or browse: it needs an id you already have from search_ads. Search results already include each ad's countries, so don't call it just for those.`,
  inputSchema: {
    type: 'object',
    properties: {
      id: { type: 'integer', description: 'The ad id, from a search_ads result.' },
    },
    required: ['id'],
    additionalProperties: false,
  },
  annotations: { title: 'Get one ad', readOnlyHint: true, openWorldHint: false },

  async run(args, context) {
    const id = args.id === undefined || args.id === null ? '' : scalar(args.id);

    // A missing id, or one that a URL would read as "." or "..", would request another path.
    if (id.trim() === '' || /^(\.|%2e){1,2}$/i.test(id)) {
      return errorResult({
        code: 'invalid_parameters',
        message: id.trim() === '' ? 'get_ad needs an id.' : `${JSON.stringify(id)} is not an ad id.`,
        next_step: 'Call get_ad with {"id": <an id from a search_ads result>}. To find ads, call search_ads.',
        retryable: false,
        details: [{ param: 'id', issue: id.trim() === '' ? 'missing' : 'invalid_value', message: 'id is the number in a search_ads result\'s "id".' }],
      });
    }

    // Any other argument is sent too, so v1 rejects it rather than this server ignoring it.
    return callV1('get_ad', args, context, { path: `/api/v1/ads/${encodeURIComponent(id)}`, query: toQuery(args, ['id']) }, ({ body }) => {
      const data = (body as { data?: unknown } | null)?.data;
      if (typeof data !== 'object' || data === null) {
        return null;
      }

      return textResult({ today: context.today, ad: fullAd(data as V1Ad) });
    });
  },
};
