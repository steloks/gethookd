<?php

use App\Ingest\AdIngestor;
use App\Models\Ad;
use App\Models\Brand;
use App\Support\CountryCatalog;
use App\Support\NicheCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

const INGEST_NOW = '2026-11-02 12:00:00';

/**
 * Three brands, each with active ads ranked 1..4 plus two without impression data.
 */
function seedIngestWorld(): void
{
    foreach (['skincare', 'kitchen', 'outdoor'] as $slug) {
        $brand = Brand::factory()->create();
        foreach ([1, 2, 3, 4] as $rank) {
            Ad::factory()->for($brand)->inNiche($slug)->ranked($rank)->create();
        }
        Ad::factory()->for($brand)->inNiche($slug)->count(2)->create();
    }
}

function ingestor(int $seed = 1): AdIngestor
{
    return new AdIngestor(new Randomizer(new Mt19937($seed)));
}

beforeEach(function () {
    $this->travelTo(INGEST_NOW);
    $this->now = CarbonImmutable::now('UTC');
    seedIngestWorld();
});

it('inserts 1 to 5 new active ads per cycle, discovered now even when they started earlier', function () {
    $ingestor = ingestor();
    $inserted = [];
    $stoppedLater = [];
    for ($i = 0; $i < 20; $i++) {
        $tick = $ingestor->tick($this->now);
        expect(count($tick->insertedIds))->toBeBetween(1, 5);
        array_push($inserted, ...$tick->insertedIds);
        array_push($stoppedLater, ...$tick->deactivatedIds);
    }

    $ads = Ad::query()->findMany($inserted);
    expect($ads->filter(fn (Ad $ad) => $ad->start_date->lt($this->now->startOfDay())))
        ->not->toBeEmpty('No backdated ad in the sample, so discovered_at vs start_date is untested');

    foreach ($ads as $ad) {
        // A later cycle may have stopped an ad an earlier cycle inserted.
        $stopped = in_array($ad->id, $stoppedLater, true);
        expect($ad->status->value)->toBe($stopped ? 'inactive' : 'active')
            ->and($ad->end_date?->toDateString())->toBe($stopped ? '2026-11-02' : null)
            ->and($ad->discovered_at->equalTo($this->now))->toBeTrue()
            ->and($ad->start_date->betweenIncluded($this->now->subDays(90)->startOfDay(), $this->now->startOfDay()))->toBeTrue()
            ->and($ad->countries)->not->toBeEmpty()
            ->and(array_diff($ad->countries, CountryCatalog::CODES))->toBe([])
            ->and($ad->headline)->not->toBeEmpty();
    }
});

it('stops 1 to 2 ads that were running, ending them today, and touches nothing else', function () {
    $before = Ad::query()->orderBy('id')->get()->keyBy('id');

    $tick = ingestor()->tick($this->now);

    expect(count($tick->deactivatedIds))->toBeBetween(1, 2)
        ->and(array_intersect($tick->deactivatedIds, $tick->insertedIds))->toBe([]);

    foreach ($before as $id => $old) {
        $ad = Ad::query()->find($id);
        if (in_array($id, $tick->deactivatedIds, true)) {
            expect($old->status->value)->toBe('active')
                ->and($ad->status->value)->toBe('inactive')
                ->and($ad->end_date->toDateString())->toBe('2026-11-02');
        } else {
            expect($ad->getAttributes())->toBe($old->getAttributes());
        }
    }
});

it('matches the spec proportions over many cycles', function () {
    $ingestor = ingestor();
    $insertCounts = [];
    $deactivateCounts = [];
    $inserted = [];

    for ($i = 0; $i < 300; $i++) {
        $tick = $ingestor->tick($this->now);
        $insertCounts[count($tick->insertedIds)] = true;
        $deactivateCounts[count($tick->deactivatedIds)] = true;
        array_push($inserted, ...$tick->insertedIds);
    }

    $new = DB::table('ads')->whereIn('id', $inserted)
        ->selectRaw("count(*) AS total,
            sum((start_date < DATE '2026-11-02')::int) AS backdated,
            min(start_date) AS earliest_start,
            max(start_date) FILTER (WHERE start_date < DATE '2026-11-02') AS latest_backdated_start,
            sum((impression_rank IS NULL)::int) AS null_rank")
        ->first();

    expect(array_keys($insertCounts))->toEqualCanonicalizing([1, 2, 3, 4, 5])
        ->and(array_keys($deactivateCounts))->toEqualCanonicalizing([1, 2])
        ->and($new->backdated / $new->total)->toBeBetween(0.25, 0.35)
        // "Up to 90 days": never further back than 90, and the whole 1..90 range is used.
        ->and($new->earliest_start >= '2026-08-04')->toBeTrue("Backdated more than 90 days: {$new->earliest_start}")
        ->and($new->earliest_start <= '2026-08-14')->toBeTrue("Never backdated near 90 days, earliest {$new->earliest_start}")
        ->and($new->latest_backdated_start >= '2026-10-28')->toBeTrue("Never backdated by a day or two, latest {$new->latest_backdated_start}")
        ->and($new->null_rank / $new->total)->toBeBetween(0.35, 0.45);
});

it('never rewrites existing ranks and keeps each brand ranked 1..k without gaps', function () {
    $ranksBefore = DB::table('ads')->whereNotNull('impression_rank')->orderBy('id')->pluck('impression_rank', 'id')->all();

    $ingestor = ingestor();
    for ($i = 0; $i < 100; $i++) {
        $ingestor->tick($this->now);
    }

    $ranksAfter = DB::table('ads')->whereIn('id', array_keys($ranksBefore))->orderBy('id')->pluck('impression_rank', 'id')->all();
    $gappyBrands = DB::select(<<<'SQL'
        SELECT brand_id FROM ads WHERE impression_rank IS NOT NULL GROUP BY brand_id
        HAVING min(impression_rank) <> 1 OR max(impression_rank) <> count(*)
    SQL);

    expect($ranksAfter)->toBe($ranksBefore)
        ->and($gappyBrands)->toBe([])
        ->and(DB::table('ads')->whereNotNull('impression_rank')->count())->toBeGreaterThan(count($ranksBefore));
});

it('puts new ads in the brand\'s niche with that niche\'s keywords, never another niche\'s', function () {
    $ingestor = ingestor();
    $inserted = [];
    for ($i = 0; $i < 150; $i++) {
        array_push($inserted, ...$ingestor->tick($this->now)->insertedIds);
    }

    // Each test brand advertises in exactly one niche, so that niche is its main niche.
    $mainNiche = DB::table('ads')->whereNotIn('id', $inserted)->distinct()->pluck('niche_id', 'brand_id');
    $slugs = DB::table('niches')->pluck('slug', 'id');
    $inMainNiche = 0;
    $withOwnKeyword = 0;

    foreach (DB::table('ads')->whereIn('id', $inserted)->get() as $ad) {
        $slug = $slugs[$ad->niche_id];
        $inMainNiche += (int) ($ad->niche_id === $mainNiche[$ad->brand_id]);
        $withOwnKeyword += (int) collect(NicheCatalog::keywords($slug))
            ->contains(fn (string $keyword) => stripos($ad->headline, $keyword) !== false);

        foreach (NicheCatalog::NICHES as $otherSlug => $other) {
            if ($otherSlug === $slug) {
                continue;
            }
            foreach ($other['keywords'] as $keyword) {
                expect(stripos($ad->headline.' '.$ad->body_text, $keyword))
                    ->toBeFalse("A {$slug} ad uses the {$otherSlug} keyword \"{$keyword}\"");
            }
        }
    }

    expect($inMainNiche / count($inserted))->toBeBetween(0.75, 0.95)
        ->and($withOwnKeyword / count($inserted))->toBeBetween(0.6, 0.8);
});

it('stops the last running ad when it is the only one left', function () {
    $last = Ad::query()->orderBy('id')->first();
    DB::table('ads')->where('id', '!=', $last->id)->update(['status' => 'inactive', 'end_date' => DB::raw('start_date')]);

    $tick = ingestor()->tick($this->now);

    expect($tick->deactivatedIds)->toBe([$last->id])
        ->and($last->fresh()->status->value)->toBe('inactive');
});

// Tests run inside one outer transaction. With the cycle in its own transaction (a savepoint), the
// failure rolls back only that cycle and the counts below hold. Without it, the error aborts the
// outer transaction and the next query fails with SQLSTATE 25P02, which also fails this test.
it('applies a cycle completely or not at all', function () {
    $before = DB::table('ads')->count();
    $activeBefore = DB::table('ads')->where('status', 'active')->count();

    // Make the deactivation step fail after the inserts have already run.
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fail_ad_update() RETURNS trigger AS $$
        BEGIN RAISE EXCEPTION 'simulated failure mid-cycle'; END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER fail_ad_update BEFORE UPDATE ON ads FOR EACH ROW EXECUTE FUNCTION fail_ad_update();
    SQL);

    expect(fn () => ingestor()->tick($this->now))->toThrow(QueryException::class, 'simulated failure mid-cycle')
        ->and(DB::table('ads')->count())->toBe($before)
        ->and(DB::table('ads')->where('status', 'active')->count())->toBe($activeBefore);
});

it('still inserts when there is nothing left to stop', function () {
    DB::table('ads')->update(['status' => 'inactive', 'end_date' => DB::raw('start_date')]);

    $tick = ingestor()->tick($this->now);

    expect($tick->insertedIds)->not->toBeEmpty()
        ->and($tick->deactivatedIds)->toBe([]);
});

it('fails with a clear message when the database has not been seeded', function () {
    DB::table('ads')->delete();
    DB::table('brands')->delete();

    expect(fn () => ingestor()->tick($this->now))->toThrow(RuntimeException::class, 'Run the seed first');
});

it('starts cycles one interval apart', function () {
    $startedAt = hrtime(true);

    $this->artisan('ads:ingest', ['--ticks' => 3, '--interval' => 200])->assertSuccessful();

    // Three cycles, two waits between them, no wait after the last one.
    $elapsedMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
    expect($elapsedMs)->toBeGreaterThanOrEqual(400)->toBeLessThan(1500);
});

it('rejects negative or non-numeric options', function (array $options) {
    $this->artisan('ads:ingest', $options)
        ->expectsOutputToContain('must be whole numbers of 0 or more')
        ->assertExitCode(2);
})->with([
    'negative ticks' => [['--ticks' => -1]],
    // --ticks=1 so that, without validation, this would finish (and fail the assertion) instead of running forever.
    'negative interval' => [['--ticks' => 1, '--interval' => -5]],
    'text ticks' => [['--ticks' => 'abc']],
]);

it('runs the requested number of cycles from the command line', function () {
    $before = DB::table('ads')->count();

    $this->artisan('ads:ingest', ['--ticks' => 3, '--interval' => 0])
        ->expectsOutputToContain('Stopped after 3 cycles.')
        ->assertSuccessful();

    expect(DB::table('ads')->count())->toBeGreaterThanOrEqual($before + 3);
});
