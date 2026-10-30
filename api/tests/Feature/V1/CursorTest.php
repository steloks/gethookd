<?php

use App\Ingest\AdIngestor;
use App\Models\Ad;
use App\Models\Brand;
use App\Search\Cursor;
use App\Support\Errors\ErrorCode;
use App\Support\NicheCatalog;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Random\Engine\Mt19937;
use Random\Randomizer;

function pageOf(string $query): TestResponse
{
    return v1('GET', '/api/v1/ads?'.$query, test()->key);
}

/**
 * Follows next_cursor (sent alone) to the end. $between runs before every page after the first.
 *
 * @return list<list<int>> the ids of each page
 */
function walk(string $query, ?Closure $between = null): array
{
    $pages = [];
    $response = pageOf($query)->assertOk();
    while (true) {
        $pages[] = array_column($response->json('data'), 'id');
        $cursor = $response->json('next_cursor');
        if ($cursor === null) {
            return $pages;
        }
        expect(count($pages))->toBeLessThan(200, 'the walk does not end');
        if ($between !== null) {
            $between();
        }
        $response = pageOf('cursor='.rawurlencode($cursor))->assertOk();
    }
}

/**
 * @return list<int> every matching ad in one page (the fixtures stay under 100)
 */
function allAtOnce(string $query): array
{
    return array_column(pageOf(trim("{$query}&limit=100", '&'))->assertOk()->json('data'), 'id');
}

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:00');
    $this->key = v1Key();

    // 4 brands, each with ranks 1–3 and 2 ads without impression data: rank ties across brands,
    // start_date ties, a null section, both statuses and both formats, and "serum" in some headlines,
    // so every filter excludes some ads.
    $niches = ['skincare', 'kitchen', 'haircare', 'skincare'];
    foreach ($niches as $b => $slug) {
        $brand = Brand::factory()->create();
        foreach ([1, 2, 3, null, null] as $i => $rank) {
            $factory = Ad::factory()->for($brand)->inNiche($slug)->state([
                'impression_rank' => $rank,
                'start_date' => '2026-09-'.str_pad((string) (10 + ($b + $i) % 4), 2, '0', STR_PAD_LEFT),
                'format' => ($b + $i) % 2 === 0 ? 'video' : 'image',
                'headline' => $i % 2 === 0 ? 'Night serum' : 'Plain test headline',
            ]);
            ($i === 2 ? $factory->inactive('2026-09-30') : $factory)->create();
        }
    }
});

describe('pages', function () {
    it('walk every result once, in the same order as one big page', function (string $sort, int $limit, string $filter) {
        $query = trim("sort={$sort}&limit={$limit}&{$filter}", '&');

        $pages = walk($query);

        expect(array_merge(...$pages))->toBe(allAtOnce(trim("sort={$sort}&{$filter}", '&')))
            ->and(array_map('count', array_slice($pages, 0, -1)))->each->toBe($limit);
    })->with(['newest', 'impressions'])->with([1, 3, 7])->with([
        'no filter' => '',
        'two niches' => 'niche=skincare,kitchen',
        'active' => 'status=active',
        'keyword' => 'q=serum',
        'format and start date' => 'format=video&started_after=2026-09-11',
    ]);

    it('give no next_cursor when the last page is exactly full', function () {
        expect(pageOf('limit=20')->assertOk()->json('next_cursor'))->toBeNull()
            ->and(walk('limit=19'))->toHaveCount(2);
    });

    it('cross from ranked ads into ads without impression data at a page boundary', function () {
        $ranked = Ad::query()->whereNotNull('impression_rank')->count();
        $nulls = Ad::query()->whereNull('impression_rank')->orderByDesc('id')->pluck('id')->all();

        $pages = walk("sort=impressions&limit={$ranked}");

        expect($pages)->toHaveCount(2)
            ->and($pages[1])->toBe($nulls);
    });

    it('page inside the section without impression data', function () {
        $nulls = Ad::query()->whereNull('impression_rank')->orderByDesc('id')->pluck('id')->all();

        $pages = walk('sort=impressions&limit=5');

        expect(array_slice(array_merge(...$pages), -count($nulls)))->toBe($nulls);
    });
});

describe('under live ingest', function () {
    beforeEach(function () {
        $this->ingest = new AdIngestor(new Randomizer(new Mt19937(7)));
        $this->inserted = [];
        $this->deactivated = [];
        $this->victim = null;
        $this->tick = function () {
            // An ad the walk has certainly not reached yet (set by the test) is stopped on the first tick.
            if ($this->victim !== null && Ad::query()->whereKey($this->victim)->where('status', 'active')->exists()) {
                Ad::query()->whereKey($this->victim)->update(['status' => 'inactive', 'end_date' => '2026-10-05']);
                $this->deactivated[] = $this->victim;
            }

            $tick = $this->ingest->tick(now()->toImmutable());
            array_push($this->inserted, ...$tick->insertedIds);
            array_push($this->deactivated, ...$tick->deactivatedIds);

            // And the inserts most likely to shift a page: the newest start date, the oldest,
            // a rank-1 tie, and the head of the null section.
            $brand = Brand::query()->orderBy('id')->first();
            $this->inserted[] = Ad::factory()->for($brand)->create(['start_date' => '2026-10-05'])->id;
            $this->inserted[] = Ad::factory()->for($brand)->create(['start_date' => '2024-01-01'])->id;
            $this->inserted[] = Ad::factory()->create(['start_date' => '2026-09-11'])->id;
            $this->inserted[] = Ad::factory()->ranked(1)->create(['start_date' => '2026-09-11'])->id;
        };
    });

    it('returns every ad that existed at page 1 exactly once, in order, and no new ads', function (string $sort) {
        $expected = allAtOnce("sort={$sort}");
        $this->victim = Ad::query()->whereIn('id', $expected)->where('status', 'active')->get()
            ->sortBy(fn (Ad $ad) => array_search($ad->id, $expected))->last()->id;

        $walked = array_merge(...walk("sort={$sort}&limit=2", $this->tick));

        expect($this->inserted)->not->toBeEmpty()
            ->and($this->deactivated)->toContain($this->victim)
            ->and($walked)->toBe($expected)
            ->and(Ad::query()->find($this->victim)->status->value)->toBe('inactive');
    })->with(['newest', 'impressions']);

    it('with a status filter, drops ads deactivated before their page was read, and nothing else', function (string $sort) {
        $expected = allAtOnce("sort={$sort}&status=active");
        $this->victim = end($expected);

        $walked = array_merge(...walk("sort={$sort}&status=active&limit=2", $this->tick));

        $stillActive = Ad::query()->whereIn('id', $expected)->where('status', 'active')->pluck('id')->all();
        expect($walked)->toBe(array_values(array_intersect($expected, $walked)))
            ->and($walked)->toEqual(array_unique($walked))
            ->and(array_diff($stillActive, $walked))->toBe([])
            ->and(array_intersect($walked, $this->inserted))->toBe([])
            ->and($walked)->not->toContain($this->victim);
    })->with(['newest', 'impressions']);

    it('with status=inactive, may also return ads deactivated mid-walk, and nothing else new', function (string $sort) {
        $expected = allAtOnce("sort={$sort}&status=inactive");
        $this->victim = Ad::query()->where('status', 'active')->whereNotNull('impression_rank')->orderBy('id')->value('id');

        $walked = array_merge(...walk("sort={$sort}&status=inactive&limit=2", $this->tick));

        expect($walked)->toEqual(array_unique($walked))
            ->and(array_diff($expected, $walked))->toBe([])
            ->and(array_values(array_diff($walked, $expected)))->each->toBeIn($this->deactivated)
            ->and(array_intersect($walked, $this->inserted))->toBe([]);
    })->with(['newest', 'impressions']);
});

describe('sending a cursor', function () {
    beforeEach(function () {
        $first = pageOf('niche=skincare&status=active&sort=impressions&limit=2')->assertOk();
        $this->cursor = $first->json('next_cursor');
        $this->rest = array_slice(allAtOnce('niche=skincare&status=active&sort=impressions'), 2);
    });

    it('alone continues the same search and says what is applied', function () {
        $response = pageOf('cursor='.rawurlencode($this->cursor))->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe(array_slice($this->rest, 0, 2))
            ->and($response->json('applied_filters'))->toBe(['niche' => ['skincare'], 'status' => 'active'])
            ->and($response->json('sort'))->toBe('impressions')
            ->and($response->json('limit'))->toBe(2);
    });

    it('with the same parameters, however they are spelled, also continues', function () {
        $response = pageOf('niche=%20skincare,skincare&status=active&sort=impressions&cursor='.rawurlencode($this->cursor))->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe(array_slice($this->rest, 0, 2));
    });

    it('with a different limit continues with the new page size', function () {
        $response = pageOf('limit=5&cursor='.rawurlencode($this->cursor))->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe(array_slice($this->rest, 0, 5));
    });

    it('with a different search is rejected, naming what differs', function (string $query, string $differs) {
        $response = pageOf("{$query}&cursor=".rawurlencode($this->cursor));

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect($response->json('error.details'))->toHaveCount(1)
            ->and($response->json('error.details.0.issue'))->toBe('cursor_mismatch')
            ->and($response->json('error.details.0.message'))->toStartWith("cursor continues a search with a different {$differs}.")
            ->and($response->json('error.next_step'))->toStartWith('To continue the earlier search: GET /api/v1/ads?cursor='.$this->cursor.'. To run the new search from its first page: GET /api/v1/ads?');
    })->with([
        ['niche=haircare', 'niche'],
        ['niche=skincare,kitchen', 'niche'],
        ['sort=newest', 'sort'],
        ['q=serum', 'q'],
        ['status=inactive&format=video', 'format, status'],
    ]);

    it('under another name suggests cursor, with the corrected call', function () {
        $response = pageOf('next_cursor='.rawurlencode($this->cursor));

        expect($response->json('error.details.0.did_you_mean'))->toBe('cursor')
            ->and($response->json('error.next_step'))->toStartWith('Send instead: GET /api/v1/ads?')->toContain('cursor='.$this->cursor);
    });
});

it('gives both calls when a cursor is sent with a different search', function () {
    $cursor = pageOf('niche=skincare&limit=2')->json('next_cursor');

    $response = pageOf('niche=haircare&cursor='.rawurlencode($cursor));

    expect($response->json('error.next_step'))->toBe(
        "To continue the earlier search: GET /api/v1/ads?cursor={$cursor}. "
        .'To run the new search from its first page: GET /api/v1/ads?niche=haircare&sort=newest&limit=2'
    );
});

it('does not take "after" for a cursor, as it usually means a date', function () {
    $response = pageOf('after=2026-09-01');

    expect($response->json('error.details'))->toHaveCount(1)
        ->and($response->json('error.details.0.issue'))->toBe('unknown_parameter')
        ->and($response->json('error.details.0'))->not->toHaveKey('did_you_mean');
});

it('carries the longest possible search', function () {
    $q = str_repeat("\u{2028}", 99).'serum'.str_repeat("\u{2028}", 96);
    $query = 'q='.rawurlencode($q).'&niche='.implode(',', NicheCatalog::slugs()).'&limit=1';
    Ad::factory()->count(2)->create(['headline' => $q]);

    $cursor = pageOf($query)->assertOk()->json('next_cursor');

    expect(strlen($cursor))->toBeGreaterThan(1500);
    pageOf('cursor='.rawurlencode($cursor))->assertOk();
});

describe('invalid cursors', function () {
    it('are rejected with a 422, not echoed, and not reported as errors', function (Closure $make) {
        Exceptions::fake();
        $first = pageOf('limit=2')->assertOk()->json('next_cursor');
        $cursor = $make($first);

        $response = pageOf('cursor='.rawurlencode($cursor));

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect($response->json('error.details'))->toHaveCount(1)
            ->and($response->json('error.details.0'))->toMatchArray(['param' => 'cursor', 'issue' => 'invalid_value'])
            ->and($response->getContent())->not->toContain(substr($cursor, 0, 12));
        Exceptions::assertNothingReported();
    })->with([
        'not a cursor' => fn () => 'page2please',
        // Well-formed, but the snapshot is moved and the old signature kept.
        'payload changed' => function (string $c) {
            [$body, $signature] = explode('.', $c);
            $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
            $payload['x'] = 999999;

            return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=').'.'.$signature;
        },
        'signature changed' => fn (string $c) => substr($c, 0, -2).(substr($c, -2) === 'AA' ? 'AB' : 'AA'),
        'signed with another key' => function (string $c) {
            $key = config('app.key');
            config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
            $other = pageOf('limit=2')->json('next_cursor');
            config(['app.key' => $key]);

            return $other;
        },
        'too long' => fn () => str_repeat('a', 3000).'.b',
        'signed, unknown version' => fn () => Cursor::seal(['v' => 2, 's' => 'newest', 'f' => [], 'l' => 2, 'x' => 9, 'k' => ['2026-01-01', 1]]),
        'signed, impossible date' => fn () => Cursor::seal(['v' => 1, 's' => 'newest', 'f' => [], 'l' => 2, 'x' => 9, 'k' => ['2026-02-30', 1]]),
        'signed, bad sort key' => fn () => Cursor::seal(['v' => 1, 's' => 'newest', 'f' => [], 'l' => 2, 'x' => 9, 'k' => ["2026-01-01'; --", 1]]),
        'signed, bad rank' => fn () => Cursor::seal(['v' => 1, 's' => 'impressions', 'f' => [], 'l' => 2, 'x' => 9, 'k' => ['1', 1]]),
        'signed, unknown filter' => fn () => Cursor::seal(['v' => 1, 's' => 'newest', 'f' => ['brand' => 'x'], 'l' => 2, 'x' => 9, 'k' => ['2026-01-01', 1]]),
        'signed, limit too big' => fn () => Cursor::seal(['v' => 1, 's' => 'newest', 'f' => [], 'l' => 1000, 'x' => 9, 'k' => ['2026-01-01', 1]]),
    ]);

    it('validates the search a signed cursor carries like any other parameters', function () {
        $cursor = Cursor::seal(['v' => 1, 's' => 'newest', 'f' => ['niche' => ['cars']], 'l' => 2, 'x' => 9, 'k' => ['2026-01-01', 1]]);

        $response = pageOf('cursor='.rawurlencode($cursor));

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect(array_column($response->json('error.details'), 'param'))->toBe(['niche']);
    });

    it('are neither issued nor accepted without an APP_KEY to sign them', function (string $appKey) {
        $cursor = pageOf('limit=2')->json('next_cursor');
        Exceptions::fake();
        config(['app.key' => $appKey]);

        expect(fn () => Cursor::seal(['v' => 1, 's' => 'newest', 'f' => [], 'l' => 2, 'x' => 9, 'k' => ['2026-01-01', 1]]))->toThrow(LogicException::class);
        assertApiError(pageOf('limit=2'), ErrorCode::InternalError);
        assertApiError(pageOf('cursor='.rawurlencode($cursor)), ErrorCode::InternalError);
        Exceptions::assertReported(LogicException::class);
    })->with(['missing' => [''], 'too short' => ['base64:c2hvcnQ=']]);

    it('does not repeat an API key in the hint for a new search', function () {
        $key = 'ghk_'.str_repeat('k', 48);

        $response = pageOf('cursor='.rawurlencode(pageOf('limit=2')->json('next_cursor')).'&q='.$key);

        expect($response->json('error.details.0.issue'))->toBe('cursor_mismatch')
            ->and($response->json('error.next_step'))->toEndWith('. To run the new search, leave cursor out.')
            ->and($response->getContent())->not->toContain('kkkkkkkk');
    });

    it('rejects an empty or repeated cursor', function (string $query, string $issue) {
        expect(pageOf($query)->json('error.details.0.issue'))->toBe($issue);
    })->with([
        ['cursor=', 'empty_value'],
        ['cursor=a.b&cursor=a.b', 'repeated_parameter'],
    ]);
});
