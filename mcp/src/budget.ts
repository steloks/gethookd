/**
 * Size budgets for what a tool call puts into the model's context, measured in characters of
 * the JSON text the tool returns (roughly 4 characters per token).
 *
 * - One search result: at most RESULT_BUDGET characters. Headline, body and brand are cut
 *   (with "…") so ads with long copy cost no more than short ones.
 * - One search page: at most MAX_LIMIT results, so at most PAGE_BUDGET characters with the
 *   echoed filters and cursor.
 * - get_ad: at most AD_BUDGET characters for the whole response (body text up to 2,000).
 */
export const RESULT_BUDGET = 600;
export const DEFAULT_LIMIT = 10;
export const MAX_LIMIT = 25;
export const PAGE_BUDGET = 20_000;
export const AD_BUDGET = 3_500;
/** Countries listed per search result (two-letter codes); get_ad lists up to 60. */
export const RESULT_COUNTRIES = 10;
/** What get_ad puts around the ad: {"today":"YYYY-MM-DD","ad":…}. */
const AD_ENVELOPE = 64;

/** One ad as v1 returns it. */
export interface V1Ad {
  id: number;
  brand_id: number;
  brand_name: string;
  headline: string;
  body_text: string;
  niche: string;
  format: string;
  status: string;
  start_date: string;
  end_date: string | null;
  days_active: number;
  impression_rank: number | null;
  countries: string[];
  discovered_at: string;
}

/**
 * Text cut to at most `max` characters (whole code points, never half an emoji), ending in "…"
 * when it was cut. Control characters and runs of whitespace become one space.
 */
export function clip(text: string, max: number): string {
  const chars = Array.from(String(text).replace(/[\p{Cc}\s]+/gu, ' ').trim());
  if (chars.length <= max) {
    return chars.join('');
  }

  return max <= 0 ? '' : `${chars.slice(0, max - 1).join('').trimEnd()}…`;
}

function countryCodes(countries: unknown, max: number): string[] {
  // ISO-2 codes: cut to two characters, without the "…" clip() would add.
  return (Array.isArray(countries) ? countries : []).slice(0, max).map((c) => Array.from(String(c).replace(/[\p{Cc}\s]+/gu, '')).slice(0, 2).join(''));
}

/** Longest headline, body and brand tried in turn until a result fits its budget. */
const RESULT_STEPS: Array<[headline: number, body: number, brand: number]> = [
  [120, 160, 60],
  [120, 80, 60],
  [80, 40, 40],
  [60, 0, 30],
  [30, 0, 20],
];

/** A search result: enough to pick ads and answer most questions; get_ad has the rest. */
export function compactAd(ad: V1Ad): Record<string, unknown> {
  let result: Record<string, unknown> = {};
  for (const [headline, body, brand] of RESULT_STEPS) {
    result = {
      id: ad.id,
      brand: clip(ad.brand_name, brand),
      headline: clip(ad.headline, headline),
      body: clip(ad.body_text, body),
      niche: ad.niche,
      format: ad.format,
      status: ad.status,
      start_date: ad.start_date,
      ...(ad.end_date === null ? {} : { end_date: ad.end_date }),
      days_active: ad.days_active,
      impression_rank_in_brand: ad.impression_rank,
      // There is no country filter, so the countries are here: a model can filter a page itself
      // instead of calling get_ad for every result.
      countries: countryCodes(ad.countries, RESULT_COUNTRIES),
    };
    if (JSON.stringify(result).length <= RESULT_BUDGET) {
      break;
    }
  }

  return result;
}

/** Longest body tried in turn until get_ad fits its budget (quotes in copy double in JSON). */
const AD_BODY_STEPS = [2_000, 1_200, 600, 200];

/** Every field of one ad, with the copy capped so one call stays within AD_BUDGET. */
export function fullAd(ad: V1Ad): Record<string, unknown> {
  let result: Record<string, unknown> = {};
  for (const body of AD_BODY_STEPS) {
    result = {
      id: ad.id,
      brand_id: ad.brand_id,
      brand: clip(ad.brand_name, 100),
      headline: clip(ad.headline, 300),
      body: clip(ad.body_text, body),
      niche: ad.niche,
      format: ad.format,
      status: ad.status,
      start_date: ad.start_date,
      end_date: ad.end_date,
      days_active: ad.days_active,
      impression_rank_in_brand: ad.impression_rank,
      countries: countryCodes(ad.countries, 60),
      discovered_at: ad.discovered_at,
    };
    if (JSON.stringify(result).length <= AD_BUDGET - AD_ENVELOPE) {
      break;
    }
  }

  return result;
}
