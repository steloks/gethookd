<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\Brand;
use App\Models\PersonalAccessToken;
use App\Support\LocalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * v0 contract test. v0 is FROZEN: this pins its behaviour, quirks included, and must keep passing.
 * If a change here is ever needed, v0 is being broken for customers who depend on it.
 */

/**
 * @param  list<ApiScope>  $scopes
 */
function v0Key(array $scopes = [ApiScope::SearchRead]): string
{
    return app(ApiKeys::class)->issue(LocalUser::get(), 'v0 test', $scopes)[1];
}

/**
 * @param  array<string, mixed>  $params
 */
function v0(array $params = [], ?string $key = null): TestResponse
{
    $key ??= test()->key;
    freshRequest();

    return test()->get('/api/v0/ads?'.http_build_query(['api_key' => $key] + $params));
}

/**
 * The test client reuses one app instance, and an auth guard caches the user it resolved on the
 * first request. Real servers start each request with fresh guards, so reset them here; otherwise a
 * revoked key would appear to keep working in tests.
 */
function freshRequest(): void
{
    app('auth')->forgetGuards();
}

/**
 * @return list<int>
 */
function v0Ids(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'id');
}

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:00');
    $this->key = v0Key();

    $pets = Brand::factory()->create(['name' => 'Acme Pets']);
    $glow = Brand::factory()->create(['name' => 'Glow Labs']);

    $this->petAd = Ad::factory()->for($pets)->inNiche('pet-supplies')->create([
        'headline' => 'Chew-proof Collar for big dogs', 'format' => 'video', 'start_date' => '2026-08-01',
    ]);
    $this->skinAd = Ad::factory()->for($glow)->inNiche('skincare')->inactive('2026-07-11')->create([
        'body_text' => 'Our SERUM makes skin glow.', 'format' => 'image', 'start_date' => '2026-07-01',
    ]);
    $this->kitchenAd = Ad::factory()->inNiche('kitchen')->create([
        'headline' => '100% off_sale knife', 'format' => 'carousel', 'start_date' => '2026-09-01',
    ]);
});

describe('response shape', function () {
    it('returns data, page and total, with exactly the documented fields per ad, in order', function () {
        $json = v0(['niche' => 'pet-supplies'])->assertOk()->assertHeader('Content-Type', 'application/json')->json();

        expect(array_keys($json))->toBe(['data', 'page', 'total'])
            ->and($json['page'])->toBe(1)
            ->and($json['total'])->toBe(1)
            ->and($json['data'][0])->toBe([
                'id' => $this->petAd->id,
                'brand' => 'Acme Pets',
                'headline' => 'Chew-proof Collar for big dogs',
                'niche' => 'Pet Supplies',
                'format' => 'video',
                'status' => 'active',
                'start_date' => '2026-08-01',
                'days_active' => 65,
            ]);
    });

    it('computes days_active up to end_date for inactive ads', function () {
        $ad = v0(['status' => 'inactive'])->json('data.0');

        expect($ad['id'])->toBe($this->skinAd->id)
            ->and($ad['status'])->toBe('inactive')
            ->and($ad['days_active'])->toBe(10)
            ->and($ad['days_active'])->toBe($this->skinAd->fresh()->days_active);
    });

    it('returns an empty list, not an error, when nothing matches', function () {
        v0(['q' => 'no ad says this'])->assertOk()->assertExactJson(['data' => [], 'page' => 1, 'total' => 0]);
    });
});

describe('q', function () {
    it('matches the headline as a case-insensitive substring', function () {
        expect(v0Ids(v0(['q' => 'COLLAR'])))->toBe([$this->petAd->id]);
    });

    it('matches the body text too', function () {
        expect(v0Ids(v0(['q' => 'serum'])))->toBe([$this->skinAd->id]);
    });

    it('treats % and _ as literal characters', function () {
        Ad::factory()->create(['headline' => '1000 reasons', 'start_date' => '2026-01-01']);
        Ad::factory()->create(['headline' => 'off sale today', 'start_date' => '2026-01-01']);

        expect(v0Ids(v0(['q' => '100%'])))->toBe([$this->kitchenAd->id])
            ->and(v0Ids(v0(['q' => 'f_s'])))->toBe([$this->kitchenAd->id]);
    });

    it('is not applied when empty', function () {
        expect(v0(['q' => ''])->json('total'))->toBe(3);
    });
});

describe('niche', function () {
    it('accepts the slug or the display name, case-insensitively', function (string $niche) {
        expect(v0Ids(v0(['niche' => $niche])))->toBe([$this->petAd->id]);
    })->with(['pet-supplies', 'Pet Supplies', 'pet supplies', 'PET-SUPPLIES']);

    it('QUIRK: ignores an unknown niche and returns unfiltered results', function () {
        expect(v0(['niche' => 'pets'])->json('total'))->toBe(3);
    });
});

describe('format and status', function () {
    it('filters by exact format', function () {
        expect(v0Ids(v0(['format' => 'video'])))->toBe([$this->petAd->id]);
    });

    it('filters by exact status', function () {
        expect(v0Ids(v0(['status' => 'inactive'])))->toBe([$this->skinAd->id]);
    });

    it('QUIRK: ignores a value that is not an exact match, so the filter is not applied', function (string $param, string $value) {
        expect(v0([$param => $value])->json('total'))->toBe(3);
    })->with([
        'format in capitals' => ['format', 'Video'],
        'unknown format' => ['format', 'gif'],
        'status in capitals' => ['status', 'Active'],
        'unknown status' => ['status', 'paused'],
    ]);

    it('combines filters with AND', function () {
        expect(v0Ids(v0(['niche' => 'skincare', 'format' => 'image', 'status' => 'inactive'])))->toBe([$this->skinAd->id])
            ->and(v0(['niche' => 'skincare', 'status' => 'active'])->json('total'))->toBe(0);
    });
});

describe('quirks', function () {
    it('ignores unknown and misspelled parameters', function () {
        expect(v0(['nich' => 'skincare', 'sort' => 'impressions', 'foo' => 'bar'])->json('total'))->toBe(3);
    });

    it('ignores a filter passed as an array instead of failing', function () {
        expect(v0(['niche' => ['skincare']])->assertOk()->json('total'))->toBe(3);
    });

    it('sorts by start_date, newest first', function () {
        expect(v0Ids(v0()))->toBe([$this->kitchenAd->id, $this->petAd->id, $this->skinAd->id]);
    });
});

describe('pagination', function () {
    beforeEach(function () {
        Ad::factory()->count(120)->sequence(fn ($s) => ['start_date' => '2025-01-01'])->create();
    });

    it('defaults to page 1 with 20 per page', function () {
        $response = v0();

        expect($response->json('page'))->toBe(1)
            ->and($response->json('data'))->toHaveCount(20)
            ->and($response->json('total'))->toBe(123);
    });

    it('returns the requested page and echoes it', function () {
        $response = v0(['page' => 7, 'per_page' => 20]);

        expect($response->json('page'))->toBe(7)
            ->and($response->json('data'))->toHaveCount(3)
            ->and($response->json('total'))->toBe(123);
    });

    it('returns an empty page past the end', function () {
        v0(['page' => 99])->assertOk()->assertJson(['data' => [], 'page' => 99, 'total' => 123]);
    });

    it('QUIRK: silently clamps per_page above 100 to 100', function () {
        expect(v0(['per_page' => 500])->json('data'))->toHaveCount(100);
    });

    it('accepts per_page up to 100', function () {
        expect(v0(['per_page' => 100])->json('data'))->toHaveCount(100)
            ->and(v0(['per_page' => 7])->json('data'))->toHaveCount(7);
    });

    it('ignores invalid page and per_page values and uses the defaults', function (array $params) {
        $response = v0($params)->assertOk();

        expect($response->json('page'))->toBe(1)
            ->and($response->json('data'))->toHaveCount(20);
    })->with([
        'page 0' => [['page' => 0]],
        'negative page' => [['page' => -2]],
        'text page' => [['page' => 'abc']],
        'per_page 0' => [['per_page' => 0]],
        'text per_page' => [['per_page' => 'lots']],
    ]);
});

describe('errors', function () {
    function assertV0Error(TestResponse $response): void
    {
        $response->assertStatus(400);
        expect($response->getContent())->toBe('{"error":"Invalid request"}');
    }

    it('returns the generic 400 for a missing, unknown or malformed key', function (?string $key) {
        assertV0Error(test()->get('/api/v0/ads'.($key === null ? '' : '?api_key='.urlencode($key))));
    })->with([
        'no key' => [null],
        'empty key' => [''],
        'unknown key' => ['ghk_'.str_repeat('a', 48)],
        'garbage' => ['not-a-key'],
        'sanctum id form with a non-numeric id' => ['abc|x'],
        'sanctum id form with a valid key' => ['1|ghk_'.str_repeat('a', 48)],
    ]);

    it('returns the generic 400 for a revoked key, on the very next call', function () {
        v0()->assertOk();

        app(ApiKeys::class)->revoke(PersonalAccessToken::query()->sole());

        assertV0Error(v0());
    });

    it('returns the generic 400 for a key without the search:read scope', function () {
        assertV0Error(v0([], v0Key([ApiScope::KeysManage])));
    });

    it('only reads the key from the api_key query parameter', function () {
        assertV0Error($this->withHeader('Authorization', "Bearer {$this->key}")->get('/api/v0/ads'));
    });

    it('returns the generic 400 for a key passed as an array', function () {
        assertV0Error($this->get('/api/v0/ads?api_key[]='.urlencode($this->key)));
    });

    it('returns the generic 400 for a wrong method or an unknown v0 path', function () {
        assertV0Error($this->post('/api/v0/ads?api_key='.urlencode($this->key)));
        assertV0Error($this->get('/api/v0/nothing?api_key='.urlencode($this->key)));
    });

    it('returns the generic 400 even for a server error', function () {
        DB::statement('ALTER TABLE ads RENAME TO ads_gone');

        assertV0Error(v0());
    });
});
