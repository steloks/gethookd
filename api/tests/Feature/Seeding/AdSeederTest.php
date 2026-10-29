<?php

use App\Support\CountryCatalog;
use App\Support\NicheCatalog;
use Database\Seeders\AdSeeder;
use Illuminate\Support\Facades\DB;

/**
 * One hash over every seeded row, ids included. Columns are rendered explicitly (UTC timestamps,
 * ISO dates) so the hash does not depend on the session's TimeZone or DateStyle settings.
 */
function seededDataHash(): string
{
    return DB::selectOne(<<<'SQL'
        SELECT md5(
            (SELECT string_agg(concat_ws(',', b.id, b.name), '|' ORDER BY b.id) FROM brands b)
            || '#' ||
            (SELECT string_agg(concat_ws(',',
                a.id, a.brand_id, a.niche_id, a.headline, a.body_text, a.format, a.status,
                to_char(a.start_date, 'YYYY-MM-DD'),
                coalesce(to_char(a.end_date, 'YYYY-MM-DD'), '-'),
                coalesce(a.impression_rank::text, '-'),
                a.countries::text,
                to_char(a.discovered_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS')
            ), '|' ORDER BY a.id) FROM ads a)
        ) AS hash
    SQL)->hash;
}

it('seeds the volumes and ratios from the spec', function () {
    $this->seed(AdSeeder::class);

    $stats = DB::selectOne(<<<'SQL'
        SELECT count(*) AS ads,
               count(DISTINCT brand_id) AS brands_with_ads,
               count(DISTINCT niche_id) AS niches,
               sum((status = 'active')::int) AS active,
               sum((impression_rank IS NULL)::int) AS null_rank,
               min(start_date) AS first_start,
               max(start_date) AS last_start
        FROM ads
    SQL);

    expect(DB::table('brands')->count())->toBe(300)
        ->and($stats->ads)->toBe(20_000)
        ->and($stats->brands_with_ads)->toBe(300)
        ->and($stats->niches)->toBe(12)
        ->and($stats->active)->toBe(12_000)
        ->and($stats->null_rank)->toBe(8_000)
        ->and($stats->first_start)->toBe('2024-10-29')
        ->and($stats->last_start)->toBe(AdSeeder::ANCHOR_DATE);
});

it('seeds rows that respect the data model', function () {
    $this->seed(AdSeeder::class);

    $violations = DB::selectOne(<<<'SQL'
        SELECT
            sum((end_date > DATE '2026-10-29')::int) AS ends_after_anchor,
            sum((discovered_at::date < start_date)::int) AS discovered_before_start,
            sum((discovered_at::date > coalesce(end_date, DATE '2026-10-29'))::int) AS discovered_after_end,
            sum((jsonb_array_length(countries) = 0)::int) AS no_countries
        FROM ads
    SQL);

    expect((array) $violations)->each->toBe(0);

    $countries = DB::table('ads')
        ->crossJoin(DB::raw('jsonb_array_elements_text(ads.countries) AS c(code)'))
        ->distinct()->pluck('c.code')->all();

    expect(array_diff($countries, CountryCatalog::CODES))->toBe([]);

    // All three formats are represented.
    expect(DB::table('ads')->distinct()->pluck('format')->sort()->values()->all())->toBe(['carousel', 'image', 'video']);
});

it('spreads start dates across the two years', function () {
    $this->seed(AdSeeder::class);

    // Share of ads in each of the 8 quarters before the anchor date (uniform would be 12.5%).
    $shares = DB::table('ads')
        ->selectRaw("least(floor((DATE '2026-10-29' - start_date) / 91.25), 7) AS quarter, count(*) / 20000.0 AS share")
        ->groupBy('quarter')->orderBy('quarter')->pluck('share', 'quarter');

    expect($shares)->toHaveCount(8);
    foreach ($shares as $quarter => $share) {
        expect((float) $share)->toBeBetween(0.08, 0.17, "Quarter {$quarter} holds {$share} of ads");
    }
});

it('ranks impressions within each brand as 1..k with no gaps or duplicates', function () {
    $this->seed(AdSeeder::class);

    $brokenBrands = DB::select(<<<'SQL'
        SELECT brand_id
        FROM ads
        WHERE impression_rank IS NOT NULL
        GROUP BY brand_id
        HAVING min(impression_rank) <> 1
            OR max(impression_rank) <> count(*)
            OR count(DISTINCT impression_rank) <> count(*)
    SQL);

    expect($brokenBrands)->toBe([]);
});

it('uses every niche keyword in that niche\'s headlines, and nowhere else', function () {
    $this->seed(AdSeeder::class);

    foreach (NicheCatalog::NICHES as $slug => $niche) {
        foreach ($niche['keywords'] as $keyword) {
            $pattern = '%'.$keyword.'%';
            $inNiche = DB::table('ads')->join('niches', 'niches.id', '=', 'ads.niche_id')
                ->where('niches.slug', $slug)->where('headline', 'ilike', $pattern)->count();
            $elsewhere = DB::table('ads')->join('niches', 'niches.id', '=', 'ads.niche_id')
                ->where('niches.slug', '!=', $slug)
                ->where(fn ($q) => $q->where('headline', 'ilike', $pattern)->orWhere('body_text', 'ilike', $pattern))
                ->count();

            expect($inNiche)->toBeGreaterThan(0, "No {$slug} headline uses \"{$keyword}\"")
                ->and($elsewhere)->toBe(0, "\"{$keyword}\" leaks into {$elsewhere} ads outside {$slug}");
        }
    }
});

it('produces identical data, ids included, when run twice on different days', function () {
    $this->travelTo('2026-10-29 09:00:00');
    $this->seed(AdSeeder::class);
    $first = seededDataHash();

    $this->travelTo('2027-03-15 18:45:00');
    $this->seed(AdSeeder::class);

    expect(seededDataHash())->toBe($first)
        ->and(DB::table('ads')->count())->toBe(20_000)
        ->and(DB::table('ads')->min('id'))->toBe(1);
});

it('matches the pinned golden hash, so any change to the generated data is deliberate', function () {
    $this->seed(AdSeeder::class);

    // If you changed the seeder or its word lists on purpose, update this value and say so in the commit.
    // An unexpected change means another environment (PHP version, library) would seed different data.
    expect(seededDataHash())->toBe('9a67325ffbefc5fdf146978983d7bf61');
});
