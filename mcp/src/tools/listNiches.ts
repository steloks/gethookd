import { clip } from '../budget.js';
import { callV1, textResult, toQuery, type ToolDefinition } from './tool.js';

export const listNiches: ToolDefinition = {
  name: 'list_niches',
  title: 'List niches',
  description: `The 12 niches ads are filed under: slug (the value search_ads takes in niche), display name, and typical keywords. Free: not billed. Also returns today's date (UTC).

Call it first when the user names a category ("pet supplies", "Baby & Kids") and you are not sure of the slug. It does not return ads or counts.`,
  inputSchema: { type: 'object', properties: {}, additionalProperties: false },
  annotations: { title: 'List niches', readOnlyHint: true, openWorldHint: false },

  async run(args, context) {
    return callV1('list_niches', args, context, { path: '/api/v1/niches', query: toQuery(args) }, ({ body }) => {
      const data = (body as { data?: unknown } | null)?.data;
      if (!Array.isArray(data)) {
        return null;
      }

      return textResult({
        today: context.today,
        niches: (data as Array<{ slug: string; name: string; keywords: string[] }>).slice(0, 50).map((niche) => ({
          slug: niche.slug,
          name: clip(niche.name, 60),
          keywords: (Array.isArray(niche.keywords) ? niche.keywords : []).slice(0, 10).map((k) => clip(k, 40)),
        })),
        next_step: 'Pass slugs (not display names) in search_ads niche, e.g. {"niche": ["pet-supplies"]}.',
      });
    });
  },
};
