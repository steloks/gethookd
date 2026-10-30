<?php

namespace App\Ingest;

use App\Enums\AdStatus;
use App\Seeding\SyntheticAdText;
use App\Support\NicheCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * One cycle of the live ingest: discover 1–5 new ads and stop 1–2 running ones.
 *
 * Each cycle is one transaction, so readers see all of a cycle's changes or none. Cycles never
 * overlap, even across processes, so ad ids become visible in increasing order; the search
 * cursor's snapshot (highest id at page 1) relies on that.
 */
final class AdIngestor
{
    public const MIN_INSERTS = 1;

    public const MAX_INSERTS = 5;

    public const MIN_DEACTIVATIONS = 1;

    public const MAX_DEACTIVATIONS = 2;

    /** Share of new ads that started before we found them. */
    public const BACKDATED_RATIO = 0.30;

    public const MAX_BACKDATE_DAYS = 90;

    public const NULL_RANK_RATIO = 0.40;

    /** Advisory lock key that serialises ingest cycles across processes. */
    public const LOCK_KEY = 74_201_001;

    /** Share of a brand's new ads that go to the niche it advertises in most. */
    private const MAIN_NICHE_RATIO = 0.85;

    private readonly SyntheticAdText $text;

    public function __construct(private readonly Randomizer $random = new Randomizer)
    {
        $this->text = new SyntheticAdText($random);
    }

    public function tick(CarbonImmutable $now): IngestTick
    {
        $now = $now->utc();

        return DB::transaction(function () use ($now) {
            // Held until commit: a second ingest process waits here instead of interleaving its
            // inserts, so an ad can never commit with an id below one already visible.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $inserted = [];
            $count = $this->random->getInt(self::MIN_INSERTS, self::MAX_INSERTS);
            for ($i = 0; $i < $count; $i++) {
                $inserted[] = $this->insertAd($now);
            }

            $deactivated = $this->deactivateAds(
                $this->random->getInt(self::MIN_DEACTIVATIONS, self::MAX_DEACTIVATIONS),
                $now,
                $inserted,
            );

            return new IngestTick($inserted, $deactivated);
        });
    }

    private function insertAd(CarbonImmutable $now): int
    {
        $brandId = $this->pickBrandId();
        $nicheId = $this->pickNicheId($brandId);
        $slug = DB::table('niches')->where('id', $nicheId)->value('slug');

        $startDate = $this->random->getFloat(0, 1) < self::BACKDATED_RATIO
            ? $now->subDays($this->random->getInt(1, self::MAX_BACKDATE_DAYS))
            : $now;

        return DB::table('ads')->insertGetId([
            'brand_id' => $brandId,
            'niche_id' => $nicheId,
            'headline' => $this->text->headline($slug, NicheCatalog::keywords($slug)),
            'body_text' => $this->text->bodyText($slug),
            'format' => $this->text->format()->value,
            'status' => AdStatus::Active->value,
            'start_date' => $startDate->toDateString(),
            'end_date' => null,
            'impression_rank' => $this->nextImpressionRank($brandId),
            'countries' => json_encode($this->text->countries()),
            // The column has whole-second precision; truncate rather than let Postgres round up into the future.
            'discovered_at' => $now->format('Y-m-d H:i:sP'),
        ]);
    }

    /**
     * NULL (no impression data) or the brand's current max + 1. Existing ranks are never
     * rewritten, because that would move ads under a live impressions cursor.
     *
     * The brand row lock serialises concurrent ingest processes on one brand, so two
     * processes cannot both read the same max and collide on the unique index.
     */
    private function nextImpressionRank(int $brandId): ?int
    {
        if ($this->random->getFloat(0, 1) < self::NULL_RANK_RATIO) {
            return null;
        }

        DB::table('brands')->where('id', $brandId)->lockForUpdate()->value('id');

        return (int) DB::table('ads')->where('brand_id', $brandId)->max('impression_rank') + 1;
    }

    /**
     * Stop up to $count random running ads that existed before this cycle: status inactive, end_date today.
     *
     * @param  list<int>  $insertedThisTick
     * @return list<int>
     */
    private function deactivateAds(int $count, CarbonImmutable $now, array $insertedThisTick): array
    {
        $active = DB::table('ads')->where('status', AdStatus::Active->value)->whereNotIn('id', $insertedThisTick);
        $available = $active->clone()->count();
        $offsets = [];
        while (count($offsets) < min($count, $available)) {
            $offsets[$this->random->getInt(0, $available - 1)] = true;
        }

        $ids = [];
        foreach (array_keys($offsets) as $offset) {
            $ids[] = $active->clone()->orderBy('id')->offset($offset)->limit(1)->value('id');
        }

        if ($ids === []) {
            return [];
        }

        // The status guard makes this a no-op for an ad another process stopped in the meantime.
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "UPDATE ads SET status = ?, end_date = ? WHERE status = ? AND id IN ({$placeholders}) RETURNING id",
            [AdStatus::Inactive->value, $now->toDateString(), AdStatus::Active->value, ...$ids],
        );

        return collect($rows)->pluck('id')->sort()->values()->all();
    }

    private function pickBrandId(): int
    {
        $count = DB::table('brands')->count();
        if ($count === 0) {
            throw new \RuntimeException('There are no brands to ingest ads for. Run the seed first (php artisan db:seed).');
        }

        return DB::table('brands')->orderBy('id')->offset($this->random->getInt(0, $count - 1))->limit(1)->value('id');
    }

    private function pickNicheId(int $brandId): int
    {
        $mainNiche = DB::table('ads')
            ->where('brand_id', $brandId)
            ->groupBy('niche_id')
            ->orderByRaw('count(*) desc, niche_id')
            ->limit(1)
            ->value('niche_id');

        if ($mainNiche !== null && $this->random->getFloat(0, 1) < self::MAIN_NICHE_RATIO) {
            return $mainNiche;
        }

        return DB::table('niches')->orderBy('id')
            ->offset($this->random->getInt(0, count(NicheCatalog::NICHES) - 1))->limit(1)->value('id');
    }
}
