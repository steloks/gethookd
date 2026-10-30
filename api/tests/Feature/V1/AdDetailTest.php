<?php

use App\Models\Ad;
use App\Models\Brand;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:00');
    $this->key = v1Key();
    $this->brand = Brand::factory()->create(['name' => 'Glow Labs']);

    $this->active = Ad::factory()->inNiche('skincare')->ranked(3)->create([
        'brand_id' => $this->brand->id,
        'headline' => 'Serum that makes you glow',
        'body_text' => 'SPF 50 all day.',
        'format' => 'video',
        'start_date' => '2026-08-01',
        'countries' => ['US', 'GB'],
        'discovered_at' => '2026-08-03 09:15:42+00',
    ]);

    $this->inactive = Ad::factory()->inNiche('pet-supplies')->inactive('2026-09-10')->create([
        'brand_id' => $this->brand->id,
        'start_date' => '2026-06-01',
    ]);
});

it('returns every field of an active ad, with the spec field names, in a fixed order', function () {
    $response = v1('GET', "/api/v1/ads/{$this->active->id}", $this->key)->assertOk();

    expect($response->json())->toBe(['data' => [
        'id' => $this->active->id,
        'brand_id' => $this->brand->id,
        'brand_name' => 'Glow Labs',
        'headline' => 'Serum that makes you glow',
        'body_text' => 'SPF 50 all day.',
        'niche' => 'skincare',
        'format' => 'video',
        'status' => 'active',
        'start_date' => '2026-08-01',
        'end_date' => null,
        'days_active' => 65,
        'impression_rank' => 3,
        'countries' => ['US', 'GB'],
        'discovered_at' => '2026-08-03T09:15:42Z',
    ]]);
});

it('returns an inactive ad with its end date and days counted up to it', function () {
    $response = v1('GET', "/api/v1/ads/{$this->inactive->id}", $this->key)->assertOk();

    expect($response->json('data'))
        ->status->toBe('inactive')
        ->niche->toBe('pet-supplies')
        ->end_date->toBe('2026-09-10')
        ->days_active->toBe(101)
        ->impression_rank->toBeNull();
});

it('reports days_active exactly as v0 does for the same ad', function () {
    app('auth')->forgetGuards();
    $v0 = collect($this->get('/api/v0/ads?api_key='.urlencode($this->key))->assertOk()->json('data'))
        ->pluck('days_active', 'id');

    foreach ([$this->active, $this->inactive] as $ad) {
        $v1 = v1('GET', "/api/v1/ads/{$ad->id}", $this->key)->assertOk()->json('data.days_active');

        expect($v1)->toBe($v0[$ad->id]);
    }
    expect($v0->all())->toBe([$this->active->id => 65, $this->inactive->id => 101]);
});

it('gives discovered_at in UTC whatever the PHP timezone', function () {
    $original = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        $discovered = v1('GET', "/api/v1/ads/{$this->active->id}", $this->key)->json('data.discovered_at');
    } finally {
        date_default_timezone_set($original);
    }

    expect($discovered)->toBe('2026-08-03T09:15:42Z');
});

it('returns 404 not_found for an id that does not exist', function () {
    $missing = Ad::query()->max('id') + 1;

    $response = v1('GET', "/api/v1/ads/{$missing}", $this->key);

    assertApiError($response, ErrorCode::NotFound);
    expect($response->json('error.message'))->toBe("There is no ad with id {$missing}.")
        ->and($response->json('error.next_step'))->toContain('search result');
});

it('returns 404 for an id that cannot exist, without querying the database', function (string $id) {
    Exceptions::fake();

    $response = v1('GET', '/api/v1/ads/'.rawurlencode($id), $this->key);

    assertApiError($response, ErrorCode::NotFound);
    expect($response->json('error.message'))->toBe('Ad ids are positive whole numbers.');
    Exceptions::assertNothingReported();
})->with([
    'word' => ['abc'],
    'negative' => ['-1'],
    'decimal' => ['1.5'],
    'past bigint' => ['99999999999999999999'],
    'leading space' => [' 1'],
]);

it('rejects query parameters instead of ignoring them', function (string $query) {
    $path = "/api/v1/ads/{$this->active->id}";

    $response = v1('GET', "{$path}?{$query}", $this->key);

    assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
    expect($response->json('error.details.0.issue'))->toBe('unknown_parameter')
        ->and($response->json('error.next_step'))->toBe("Send instead: GET {$path}");
})->with(['fields=headline', 'fields', 'id=1&id=2']);
