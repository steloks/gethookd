<?php

use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\Brand;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

/**
 * GET /api/v1/ads with a raw query string, so tests control repeats and encoding exactly.
 */
function search(string $query = ''): TestResponse
{
    return v1('GET', '/api/v1/ads'.($query === '' ? '' : "?{$query}"), test()->key);
}

/**
 * @return list<int>
 */
function searchIds(string $query = ''): array
{
    return array_column(search($query)->assertOk()->json('data'), 'id');
}

/**
 * The single problem in a 422, after checking the error shape.
 *
 * @return array<string, mixed>
 */
function soleProblem(TestResponse $response): array
{
    assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
    expect($response->json('error.details'))->toHaveCount(1);

    return $response->json('error.details.0');
}

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:00');
    $this->key = v1Key();
    $acme = Brand::factory()->create(['name' => 'Acme']);
    $other = Brand::factory()->create(['name' => 'Other']);

    // Each filter test needs ads the filter must exclude; see the comment on each ad.
    $this->ads = [
        // skincare, video, active, rank 1 at Acme; "serum" in the headline
        'a1' => Ad::factory()->inNiche('skincare')->ranked(1)->create([
            'brand_id' => $acme->id, 'headline' => 'Glow SERUM launch', 'format' => 'video', 'start_date' => '2026-09-20',
        ]),
        // skincare, image, inactive, no rank; "50%" in the body
        'a2' => Ad::factory()->inNiche('skincare')->inactive('2026-09-30')->create([
            'brand_id' => $acme->id, 'body_text' => 'Save 50% today', 'start_date' => '2026-07-01',
        ]),
        // haircare, video, active, rank 2 at Acme; "serum" in the body only
        'a3' => Ad::factory()->inNiche('haircare')->ranked(2)->create([
            'brand_id' => $acme->id, 'headline' => 'Shampoo for curls', 'body_text' => 'With serum oil', 'format' => 'video', 'start_date' => '2026-08-15',
        ]),
        // pet-supplies, carousel, active, no rank; "500" (not "50%") in the body
        'a4' => Ad::factory()->inNiche('pet-supplies')->create([
            'brand_id' => $other->id, 'body_text' => 'Save 500 today', 'format' => 'carousel', 'start_date' => '2026-10-01',
        ]),
        // kitchen, image, inactive, rank 1 at Other; starts exactly on 2026-09-15
        'a5' => Ad::factory()->ranked(1)->inactive('2026-10-01')->create([
            'brand_id' => $other->id, 'start_date' => '2026-09-15',
        ]),
    ];
});

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function ids(array $names): array
{
    return array_map(fn (string $name) => test()->ads[$name]->id, $names);
}

describe('results', function () {
    it('returns every ad, newest first, with the applied defaults', function () {
        $response = search()->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe(ids(['a4', 'a1', 'a5', 'a3', 'a2']))
            ->and($response->json('sort'))->toBe('newest')
            ->and($response->json('limit'))->toBe(20)
            ->and($response->getContent())->toContain('"applied_filters":{}');
    });

    it('returns ads in the same shape as the detail endpoint', function () {
        $item = search('niche=pet-supplies')->json('data.0');
        $detail = v1('GET', "/api/v1/ads/{$this->ads['a4']->id}", $this->key)->json('data');

        expect($item)->toBe($detail);
    });
});

describe('filters (each test fails if its filter is ignored)', function () {
    it('q matches headline or body, case-insensitively', function () {
        expect(searchIds('q=serum'))->toBe(ids(['a1', 'a3']));
    });

    it('q decodes + and %20 as spaces, as form encoding does', function (string $query) {
        expect(searchIds($query))->toBe(ids(['a1']));
    })->with(['q=glow+serum', 'q=glow%20serum']);

    it('q treats % and _ literally', function () {
        expect(searchIds('q='.rawurlencode('50%')))->toBe(ids(['a2']));
    });

    it('niche keeps one niche', function () {
        expect(searchIds('niche=skincare'))->toBe(ids(['a1', 'a2']));
    });

    it('niche keeps several comma-separated niches', function () {
        expect(searchIds('niche=skincare,haircare'))->toBe(ids(['a1', 'a3', 'a2']));
    });

    it('format keeps one format', function () {
        expect(searchIds('format=video'))->toBe(ids(['a1', 'a3']));
    });

    it('status keeps one status', function () {
        expect(searchIds('status=inactive'))->toBe(ids(['a5', 'a2']));
    });

    it('started_after keeps ads that started on or after the day', function () {
        expect(searchIds('started_after=2026-09-15'))->toBe(ids(['a4', 'a1', 'a5']))
            ->and(searchIds('started_after=2026-09-16'))->toBe(ids(['a4', 'a1']));
    });

    it('combines filters with AND', function () {
        expect(searchIds('niche=skincare,haircare&format=video&q=serum&started_after=2026-09-01'))->toBe(ids(['a1']));
    });

    it('limit caps the number of results', function () {
        expect(searchIds('limit=2'))->toBe(ids(['a4', 'a1']));
    });

    it('limit defaults to 20', function () {
        Ad::factory()->count(20)->create();

        expect(searchIds())->toHaveCount(20);
    });
});

describe('sorting', function () {
    it('impressions puts rank 1 first and ads without impression data last', function () {
        // Rank ties (rank 1 at two brands) and null ties break by id, newest id first.
        expect(searchIds('sort=impressions'))->toBe(ids(['a5', 'a1', 'a3', 'a4', 'a2']));
    });

    it('impressions keeps nulls last whatever their other fields', function () {
        $late = Ad::factory()->create(['start_date' => '2026-10-04']);

        $ids = searchIds('sort=impressions');

        expect(array_slice($ids, -3))->toBe([$late->id, ...ids(['a4', 'a2'])]);
    });

    // Ids are inserted out of order, so the rows' physical order differs from id order. Index scans
    // are turned off because both sort indexes end in id and would hide a missing tiebreak; other
    // filters can make the planner skip them in real use. Only an explicit ORDER BY id passes.
    it('breaks ties by id, newest id first, in both sorts', function (string $sort) {
        foreach (['enable_indexscan', 'enable_indexonlyscan', 'enable_bitmapscan'] as $setting) {
            DB::statement("SET LOCAL {$setting} = off");
        }
        $shuffled = [9004, 9001, 9007, 9003, 9008, 9002, 9006, 9005];
        foreach ($shuffled as $id) {
            Ad::factory()->create(['id' => $id, 'start_date' => '2026-10-04']);
        }

        $tied = array_values(array_filter(searchIds("sort={$sort}"), fn (int $id) => $id >= 9000));

        expect($tied)->toBe([9008, 9007, 9006, 9005, 9004, 9003, 9002, 9001]);
    })->with(['newest', 'impressions']);

    it('newest breaks start_date ties by id, newest id first', function () {
        $first = Ad::factory()->create(['start_date' => '2026-10-04']);
        $second = Ad::factory()->create(['start_date' => '2026-10-04']);

        expect(array_slice(searchIds(), 0, 2))->toBe([$second->id, $first->id]);
    });

    it('impressions says the ranks are within one brand, not across brands', function () {
        $response = search('sort=impressions')->assertOk();

        expect(array_keys($response->json()))->toBe(['data', 'applied_filters', 'sort', 'sort_note', 'limit', 'next_cursor'])
            ->and($response->json('sort_note'))->toBe(
                "impression_rank compares ads within one brand only (1 = that brand's most-shown ad). "
                ."Results list every brand's rank-1 ads, then rank 2, and so on, with ads without impression data last. "
                .'This is not a ranking of impressions across brands.'
            );
    });

    it('newest needs no note', function () {
        expect(array_keys(search()->assertOk()->json()))->toBe(['data', 'applied_filters', 'sort', 'limit', 'next_cursor']);
    });
});

describe('applied filters', function () {
    it('echoes what was applied, normalised', function () {
        $response = search('q=%20%20serum%20&niche='.rawurlencode(' haircare , skincare,skincare,').'&format=video&status=active&started_after=2026-09-01&sort=impressions&limit=5')
            ->assertOk();

        expect($response->json('applied_filters'))->toBe([
            'q' => 'serum',
            'niche' => ['skincare', 'haircare'],
            'format' => 'video',
            'status' => 'active',
            'started_after' => '2026-09-01',
        ])
            ->and($response->json('sort'))->toBe('impressions')
            ->and($response->json('limit'))->toBe(5);
    });
});

describe('auth', function () {
    it('requires a key with search:read', function () {
        assertApiError(v1('GET', '/api/v1/ads'), ErrorCode::Unauthenticated);
        assertApiError(v1('GET', '/api/v1/ads', v1Key([ApiScope::KeysManage])), ErrorCode::InsufficientScope);
    });

    it('checks the key before the parameters', function () {
        assertApiError(v1('GET', '/api/v1/ads?nich=skincare'), ErrorCode::Unauthenticated);
    });
});

describe('request forms', function () {
    it('accepts percent-encoded parameter names', function () {
        expect(search('%73ort=impressions')->assertOk()->json('sort'))->toBe('impressions');
    });

    it('answers HEAD like GET, including validation', function () {
        app('auth')->forgetGuards();
        $this->call('HEAD', '/api/v1/ads?niche=skincare', server: ['HTTP_AUTHORIZATION' => "Bearer {$this->key}"])->assertOk();
        app('auth')->forgetGuards();
        $this->call('HEAD', '/api/v1/ads?nich=skincare', server: ['HTTP_AUTHORIZATION' => "Bearer {$this->key}"])->assertStatus(422);
    });

    // CORS preflight cannot carry a key, so it is answered without auth. Part 3 must not meter it.
    it('answers an OPTIONS preflight without a key and without running the search', function () {
        DB::enableQueryLog();

        $status = $this->call('OPTIONS', '/api/v1/ads', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->getStatusCode();

        expect($status)->toBeLessThan(300)
            ->and(DB::getQueryLog())->toBe([]);
    });
});

describe('validation', function () {
    it('rejects an unknown parameter and suggests the right one', function () {
        $response = search('nich=skincare');
        $problem = soleProblem($response);

        expect($problem)->toBe([
            'param' => 'nich',
            'issue' => 'unknown_parameter',
            'message' => '"nich" is not a search parameter.',
            'allowed' => ['q', 'niche', 'format', 'status', 'started_after', 'sort', 'limit', 'cursor'],
            'did_you_mean' => 'niche',
        ])
            ->and($response->json('error.next_step'))->toBe('Send instead: GET /api/v1/ads?niche=skincare');
    });

    it('suggests a parameter from a common alias', function (string $query, string $expected, string $corrected) {
        $response = search($query);

        expect(soleProblem($response)['did_you_mean'])->toBe($expected)
            ->and($response->json('error.next_step'))->toBe("Send instead: GET /api/v1/ads?{$corrected}");
    })->with([
        ['query=serum', 'q', 'q=serum'],
        ['per_page=5', 'limit', 'limit=5'],
        ['since=2026-09-01', 'started_after', 'started_after=2026-09-01'],
        ['category=skincare', 'niche', 'niche=skincare'],
    ]);

    it('does not guess for a name that is nothing like a parameter', function () {
        $response = search('x=1');

        expect(soleProblem($response))->not->toHaveKey('did_you_mean')
            ->and($response->json('error.next_step'))->toBe(ErrorCode::InvalidParameters->nextStep());
    });

    it('rejects a niche that is not an exact slug and suggests it', function (string $value, string $expected) {
        $problem = soleProblem(search('niche='.rawurlencode($value)));

        expect($problem['issue'])->toBe('invalid_value')
            ->and($problem['did_you_mean'])->toBe($expected)
            ->and($problem['allowed'])->toContain('pet-supplies')->toHaveCount(12);
    })->with([
        'capitalised' => ['Skincare', 'skincare'],
        'display name' => ['Pet Supplies', 'pet-supplies'],
        'display name with &' => ['Baby & Kids', 'baby-kids'],
        'typo' => ['skincar', 'skincare'],
        'one bad of two' => ['skincare,Haircare', 'skincare,haircare'],
    ]);

    it('lists the allowed niches but no guess for an unrecognisable niche', function () {
        $problem = soleProblem(search('niche=skincare,cars'));

        expect($problem['message'])->toContain('"cars"')
            ->and($problem)->not->toHaveKey('did_you_mean');
    });

    it('rejects an invalid format, status or sort and suggests the right value', function (string $query, array $allowed, ?string $expected) {
        $problem = soleProblem(search($query));

        expect($problem['issue'])->toBe('invalid_value')
            ->and($problem['allowed'])->toBe($allowed)
            ->and($problem['did_you_mean'] ?? null)->toBe($expected);
    })->with([
        ['format=Video', ['image', 'video', 'carousel'], 'video'],
        ['format=gif', ['image', 'video', 'carousel'], null],
        ['status=Active', ['active', 'inactive'], 'active'],
        ['status=running', ['active', 'inactive'], null],
        ['sort=most_impressions', ['newest', 'impressions'], 'impressions'],
        ['sort=oldest', ['newest', 'impressions'], null],
    ]);

    it('rejects a started_after that is not YYYY-MM-DD and says what today is', function (string $value, ?string $expected) {
        $problem = soleProblem(search('started_after='.rawurlencode($value)));

        expect($problem['message'])->toContain('YYYY-MM-DD')->toContain('on or after')->toContain('Today is 2026-10-05')
            ->and($problem['did_you_mean'] ?? null)->toBe($expected);
    })->with([
        ['2026/09/15', '2026-09-15'],
        ['2026-9-5', '2026-09-05'],
        ['2026-09-15T00:00:00Z', '2026-09-15'],
        ['2026-13-01', null],
        ['2026-02-30', null],
        ['last 30 days', null],
    ]);

    it('rejects a limit outside 1 to 100 instead of clamping it', function (string $value, string $issue, ?string $expected) {
        $problem = soleProblem(search("limit={$value}"));

        expect($problem['issue'])->toBe($issue)
            ->and($problem['did_you_mean'] ?? null)->toBe($expected);
    })->with([
        ['500', 'out_of_range', '100'],
        ['0', 'invalid_value', null],
        ['-5', 'invalid_value', null],
        ['ten', 'invalid_value', null],
        ['2.5', 'invalid_value', null],
    ]);

    it('rejects empty values and suggests leaving the parameter out', function (string $name) {
        $response = search("{$name}=");

        expect(soleProblem($response)['issue'])->toBe('empty_value')
            ->and($response->json('error.next_step'))->toBe('Send instead: GET /api/v1/ads');
    })->with(['q', 'niche', 'format', 'status', 'started_after', 'sort']);

    it('rejects a q over 200 characters', function () {
        expect(soleProblem(search('q='.str_repeat('a', 201)))['issue'])->toBe('too_long');
        search('q='.str_repeat('a', 200))->assertOk();
    });

    it('counts the length of q after trimming it', function () {
        search('q='.str_repeat('%20', 5).str_repeat('a', 200).'%20')->assertOk();
        expect(soleProblem(search('q=%20'.str_repeat('a', 201)))['issue'])->toBe('too_long');
    });

    it('rejects a repeated niche instead of silently keeping the last one', function () {
        $response = search('niche=skincare&niche=haircare');

        expect(soleProblem($response))
            ->issue->toBe('repeated_parameter')
            ->did_you_mean->toBe('skincare,haircare')
            ->and($response->json('error.next_step'))->toBe('Send instead: GET /api/v1/ads?niche=skincare,haircare');
    });

    it('suggests only real slugs for a repeated niche', function () {
        expect(search('niche=Skincare&niche=haircare')->json('error.details.0'))->toMatchArray(['issue' => 'repeated_parameter', 'did_you_mean' => 'skincare,haircare'])
            ->and(search('niche=nope&niche=haircare')->json('error.details.0'))->toMatchArray(['issue' => 'repeated_parameter'])->not->toHaveKey('did_you_mean');
    });

    it('suggests each slug once when a repeated niche repeats a slug', function () {
        $response = search('niche=skincare&niche=skincare');

        expect(soleProblem($response)['did_you_mean'])->toBe('skincare')
            ->and($response->json('error.next_step'))->toBe('Send instead: GET /api/v1/ads?niche=skincare');
    });

    it('rejects a repeated niche with invalid UTF-8 as a 422, not a server error', function (string $query) {
        Exceptions::fake();

        $response = search($query);

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect(array_column($response->json('error.details'), 'issue'))->toContain('invalid_value')
            ->and($response->json('error.next_step'))->toBe(ErrorCode::InvalidParameters->nextStep());
        Exceptions::assertNothingReported();
    })->with(['niche=%FF&niche=skincare', 'niche=skincare&niche%5B%5D=%FF', 'niche=a%00&niche=skincare']);

    it('rejects a repeated single-value parameter without guessing', function () {
        $response = search('format=video&format=image');

        expect(soleProblem($response))->issue->toBe('repeated_parameter')
            ->and($response->json('error.next_step'))->toBe(ErrorCode::InvalidParameters->nextStep());
    });

    it('rejects array syntax', function () {
        $response = search('niche%5B%5D=skincare');

        expect(soleProblem($response))->issue->toBe('array_syntax')->param->toBe('niche[]')
            ->and($response->json('error.next_step'))->toBe('Send instead: GET /api/v1/ads?niche=skincare');
    });

    it('reports every problem at once and gives the fully corrected call', function () {
        $response = search('nich=Skincare&format=Video&limit=500&q=serum');

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect(array_column($response->json('error.details'), 'param'))->toBe(['nich', 'niche', 'format', 'limit'])
            ->and($response->json('error.message'))->toBe('4 problems with the search parameters: nich, niche, format, limit. See details.')
            ->and($response->json('error.next_step'))->toBe('Send instead: GET /api/v1/ads?q=serum&niche=skincare&format=video&limit=100');
    });

    it('rejects text that is not UTF-8 or contains NUL with a 422, not a database error', function (string $raw) {
        Exceptions::fake();

        expect(soleProblem(search("q={$raw}"))['issue'])->toBe('invalid_value');
        Exceptions::assertNothingReported();
    })->with([
        'invalid UTF-8' => ['%FF%FE'],
        'NUL byte' => ['a%00b'],
    ]);

    it('names at most five unknown niches in the message', function () {
        $unknown = array_map(fn (int $i) => "nope{$i}", range(1, 300));

        $problem = soleProblem(search('niche='.implode(',', $unknown)));

        expect($problem['message'])->toStartWith('Unknown niche "nope1", "nope2", "nope3", "nope4", "nope5" and 295 more.')
            ->and(mb_strlen($problem['message']))->toBeLessThan(250);
    });

    it('never echoes an API key sent as a value or a name', function (string $query) {
        $key = 'ghk_'.str_repeat('k', 48);

        // UPPER: the key in capitals; ENCODED: its underscore still percent-encoded after decoding.
        $response = search(str_replace(['UPPER', 'ENCODED', 'KEY'], [strtoupper($key), str_replace('_', '%255F', $key), $key], $query));

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect(strtolower($response->getContent()))->not->toContain('kkkkkkkk')->toContain('ghk_[redacted]');
    })->with(['format=KEY', 'KEY=1', 'niche=KEY', 'niche=KEY&niche=skincare', 'format=UPPER', 'niche=ENCODED']);

    it('never repeats an API key in the corrected request', function (string $query) {
        $key = 'ghk_'.str_repeat('k', 48);

        $response = search(str_replace('KEY', $key, $query));

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect($response->getContent())->not->toContain(substr($key, 0, 20))
            ->and($response->json('error.next_step'))->toBe(ErrorCode::InvalidParameters->nextStep());
    })->with(['query=KEY', 'q=KEY&order=newest', 'q=KEY&niche=Skincare']);

    it('lists at most 10 problems, however many parameters are sent', function () {
        $response = search(implode('&', array_map(fn (int $i) => "a{$i}=1", range(1, 500))));

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect($response->json('error.details'))->toHaveCount(10)
            ->and($response->json('error.details.9.param'))->toBe('a10')
            ->and($response->json('error.message'))->toBe('500 problems with the search parameters: a1, a2, a3, a4, a5 and 495 more. See details (the first 10).')
            ->and(strlen($response->getContent()))->toBeLessThan(8000);
    });

    it('never echoes a long input in full', function () {
        $problem = soleProblem(search('format='.str_repeat('x', 500)));

        expect(mb_strlen($problem['message']))->toBeLessThan(100);
    });
});
