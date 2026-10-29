<?php

namespace Database\Seeders;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Models\Niche;
use App\Seeding\SyntheticAdText;
use App\Support\CountryCatalog;
use App\Support\NicheCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic seed: the same rows, ids included, on every run and on every day.
 *
 * - Its own seeded engine, not Faker or mt_rand, so nothing else can shift the sequence.
 * - Dates are measured from a fixed anchor date, not from today.
 * - Existing ads and brands are truncated and ids restarted, so re-running replaces the data.
 */
class AdSeeder extends Seeder
{
    public const SEED = 20261029;

    public const ANCHOR_DATE = '2026-10-29';

    public const BRANDS = 300;

    public const ADS = 20_000;

    public const ACTIVE_RATIO = 0.60;

    public const NULL_RANK_RATIO = 0.40;

    /** Start dates are spread over the two years before the anchor date. */
    public const START_DATE_SPAN_DAYS = 730;

    /** Share of a brand's ads that stay in the brand's main niche. */
    private const MAIN_NICHE_RATIO = 0.85;

    private Randomizer $random;

    private SyntheticAdText $text;

    /** @var list<int> running total of brand weights, for weighted picks */
    private array $cumulativeWeights = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->random = new Randomizer(new Mt19937(self::SEED));
        $this->text = new SyntheticAdText($this->random);

        $anchor = CarbonImmutable::parse(self::ANCHOR_DATE, 'UTC');
        $nicheIds = Niche::query()->pluck('id', 'slug')->all();

        DB::transaction(function () use ($anchor, $nicheIds) {
            DB::statement('TRUNCATE ads, brands RESTART IDENTITY');

            $brands = $this->insertBrands($nicheIds);
            $ads = $this->generateAds($anchor, $brands, array_flip($nicheIds));

            foreach (array_chunk($ads, 1000) as $chunk) {
                DB::table('ads')->insert($chunk);
            }
        });
    }

    /**
     * Brands get a main niche round-robin (25 per niche) and a skewed size weight,
     * so there are a few large advertisers and a long tail of small ones.
     *
     * @param  array<string, int>  $nicheIds
     * @return list<array{id: int, niche_id: int, weight: int}>
     */
    private function insertBrands(array $nicheIds): array
    {
        $slugs = NicheCatalog::slugs();
        $brands = [];

        foreach ($this->text->brandNames(self::BRANDS) as $i => $name) {
            $brands[] = [
                'id' => $i + 1,
                'niche_id' => $nicheIds[$slugs[$i % count($slugs)]],
                'weight' => (int) round(1000 / ($i + 1) ** 0.8),
            ];
            DB::table('brands')->insert(['id' => $i + 1, 'name' => $name]);
        }

        // Explicit ids were inserted, so move the sequence past them.
        DB::statement("SELECT setval(pg_get_serial_sequence('brands', 'id'), (SELECT MAX(id) FROM brands))");

        $total = 0;
        $this->cumulativeWeights = [];
        foreach ($brands as $brand) {
            $this->cumulativeWeights[] = $total += $brand['weight'];
        }

        return $brands;
    }

    /**
     * @param  list<array{id: int, niche_id: int, weight: int}>  $brands
     * @param  array<int, string>  $nicheSlugs
     * @return list<array<string, mixed>>
     */
    private function generateAds(CarbonImmutable $anchor, array $brands, array $nicheSlugs): array
    {
        $activeCount = (int) round(self::ADS * self::ACTIVE_RATIO);
        $nullRankCount = (int) round(self::ADS * self::NULL_RANK_RATIO);

        // Exact counts, randomly placed.
        $isActive = $this->flags(self::ADS, $activeCount);
        $hasNullRank = $this->flags(self::ADS, $nullRankCount);

        $ads = [];
        for ($i = 0; $i < self::ADS; $i++) {
            // Every brand gets at least one ad, the rest are spread by weight.
            $brand = $i < count($brands) ? $brands[$i] : $this->pickWeighted($brands);
            $nicheId = $this->random->getFloat(0, 1) < self::MAIN_NICHE_RATIO
                ? $brand['niche_id']
                : $this->random->getInt(1, count($nicheSlugs));
            $slug = $nicheSlugs[$nicheId];

            $startDate = $anchor->subDays($this->random->getInt(0, self::START_DATE_SPAN_DAYS));
            $endDate = $isActive[$i]
                ? null
                : $startDate->addDays($this->random->getInt(0, (int) $startDate->diffInDays($anchor)));

            $ads[] = [
                'brand_id' => $brand['id'],
                'niche_id' => $nicheId,
                'headline' => $this->text->headline($slug, NicheCatalog::keywords($slug)),
                'body_text' => $this->text->bodyText($slug),
                'format' => $this->pickFormat()->value,
                'status' => ($isActive[$i] ? AdStatus::Active : AdStatus::Inactive)->value,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate?->toDateString(),
                'impression_rank' => $hasNullRank[$i] ? null : 0, // real ranks assigned per brand below
                'countries' => json_encode($this->countries()),
                'discovered_at' => $this->discoveredAt($startDate, $endDate ?? $anchor, $anchor),
            ];
        }

        $this->assignImpressionRanks($ads);

        // Ids follow discovery order, as they would in production.
        $order = array_keys($ads);
        usort($order, fn (int $a, int $b) => [$ads[$a]['discovered_at'], $a] <=> [$ads[$b]['discovered_at'], $b]);

        return array_map(fn (int $i) => $ads[$i], $order);
    }

    /**
     * impression_rank is a position in the ad's own brand's list: each brand's ranked ads
     * get a shuffled 1..k with no gaps or duplicates.
     *
     * @param  list<array<string, mixed>>  $ads
     */
    private function assignImpressionRanks(array &$ads): void
    {
        $rankedByBrand = [];
        foreach ($ads as $i => $ad) {
            if ($ad['impression_rank'] !== null) {
                $rankedByBrand[$ad['brand_id']][] = $i;
            }
        }

        foreach ($rankedByBrand as $indexes) {
            foreach ($this->random->shuffleArray($indexes) as $rank => $i) {
                $ads[$i]['impression_rank'] = $rank + 1;
            }
        }
    }

    /**
     * @return list<bool> exactly $trueCount true values in random positions
     */
    private function flags(int $total, int $trueCount): array
    {
        $flags = array_merge(array_fill(0, $trueCount, true), array_fill(0, $total - $trueCount, false));

        return $this->random->shuffleArray($flags);
    }

    /**
     * @param  list<array{id: int, niche_id: int, weight: int}>  $brands
     * @return array{id: int, niche_id: int, weight: int}
     */
    private function pickWeighted(array $brands): array
    {
        $cumulative = $this->cumulativeWeights;
        $target = $this->random->getInt(1, $cumulative[count($cumulative) - 1]);
        $low = 0;
        $high = count($cumulative) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $cumulative[$mid] < $target ? $low = $mid + 1 : $high = $mid;
        }

        return $brands[$low];
    }

    private function pickFormat(): AdFormat
    {
        $roll = $this->random->getInt(1, 100);

        return match (true) {
            $roll <= 50 => AdFormat::Image,
            $roll <= 85 => AdFormat::Video,
            default => AdFormat::Carousel,
        };
    }

    /**
     * @return list<string>
     */
    private function countries(): array
    {
        $keys = $this->random->pickArrayKeys(CountryCatalog::CODES, $this->random->getInt(1, 4));

        return array_map(fn (int $key) => CountryCatalog::CODES[$key], $keys);
    }

    /**
     * Found within two weeks of starting, never after it stopped, never after the anchor.
     */
    private function discoveredAt(CarbonImmutable $startDate, CarbonImmutable $lastDay, CarbonImmutable $anchor): string
    {
        $lagDays = min($this->random->getInt(0, 14), (int) $startDate->diffInDays($lastDay));
        $at = $startDate->addDays($lagDays)->addSeconds($this->random->getInt(0, 86_399));

        return $at->min($anchor)->format('Y-m-d H:i:sP');
    }
}
