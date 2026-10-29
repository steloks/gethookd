<?php

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Models\Ad;
use App\Models\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insert a raw row so the database constraints are tested, not the factory or the casts.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertRawAd(array $overrides = []): void
{
    DB::table('ads')->insert(array_merge([
        'brand_id' => Brand::factory()->create()->id,
        'niche_id' => 1,
        'headline' => 'Glow serum',
        'body_text' => 'Body',
        'format' => 'image',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'impression_rank' => 1,
        'countries' => '["US"]',
        'discovered_at' => '2026-01-02 00:00:00+00',
    ], $overrides));
}

it('stores an ad and reads it back with typed attributes', function () {
    $ad = Ad::factory()->inNiche('pet-supplies')->create([
        'format' => AdFormat::Video,
        'countries' => ['US', 'GB'],
        'start_date' => '2026-08-01',
    ])->fresh();

    expect($ad->format)->toBe(AdFormat::Video)
        ->and($ad->status)->toBe(AdStatus::Active)
        ->and($ad->start_date->toDateString())->toBe('2026-08-01')
        ->and($ad->end_date)->toBeNull()
        ->and($ad->countries)->toBe(['US', 'GB'])
        ->and($ad->niche->name)->toBe('Pet Supplies')
        ->and($ad->brand->name)->toBeString();
});

it('accepts a valid row', function () {
    insertRawAd();

    expect(DB::table('ads')->count())->toBe(1);
});

it('rejects rows that break the spec invariants', function (array $overrides) {
    expect(fn () => insertRawAd($overrides))->toThrow(QueryException::class);
})->with([
    'active ad with an end_date' => [['status' => 'active', 'end_date' => '2026-02-01']],
    'inactive ad without an end_date' => [['status' => 'inactive', 'end_date' => null]],
    'end_date before start_date' => [['status' => 'inactive', 'end_date' => '2025-12-31']],
    'impression_rank of zero' => [['impression_rank' => 0]],
    'unknown format' => [['format' => 'gif']],
    'unknown status' => [['status' => 'paused']],
    'unknown niche' => [['niche_id' => 99]],
    'countries not a list' => [['countries' => '"US"']],
]);

it('allows a null impression_rank', function () {
    insertRawAd(['impression_rank' => null]);

    expect(DB::table('ads')->whereNull('impression_rank')->count())->toBe(1);
});

describe('days_active', function () {
    beforeEach(fn () => $this->travelTo('2026-10-05 15:30:00'));

    it('counts an active ad up to today', function () {
        $ad = Ad::factory()->make(['start_date' => '2026-08-01']);

        expect($ad->days_active)->toBe(65);
    });

    it('counts an inactive ad up to its end_date, not today', function () {
        $ad = Ad::factory()->inactive('2026-08-11')->make(['start_date' => '2026-08-01']);

        expect($ad->days_active)->toBe(10);
    });

    it('is zero for an ad that started today', function () {
        $ad = Ad::factory()->make(['start_date' => '2026-10-05']);

        expect($ad->days_active)->toBe(0);
    });

    it('uses the UTC date, not the time of day', function () {
        $this->travelTo('2026-10-05 23:59:59');
        $ad = Ad::factory()->make(['start_date' => '2026-10-04']);

        expect($ad->days_active)->toBe(1);
    });

    it('is not stored in the database', function () {
        expect(Schema::hasColumn('ads', 'days_active'))->toBeFalse();
    });
});
