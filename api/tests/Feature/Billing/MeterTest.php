<?php

use App\Auth\ApiKeys;
use App\Billing\Meter;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Errors\ErrorCode;
use App\Support\LocalUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/*
 * The billable-call rule, as written in REFERENCE.md ("Billing").
 */

function billed(): int
{
    return DB::table('billable_calls')->count();
}

function v0Billing(string $key, array $headers = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->get('/api/v0/ads?api_key='.urlencode($key), $headers);
}

/**
 * Fills the account's quota for the current month up to $count calls, as earlier billed calls.
 */
function useQuota(string $key, int $count): void
{
    $token = PersonalAccessToken::findToken($key);
    DB::table('billable_calls')->insert(array_fill(0, $count, [
        'user_id' => $token->tokenable_id,
        'token_id' => $token->id,
        'endpoint' => 'v1.ads.search',
        'client' => 'rest',
        'idempotency_key' => null,
        'created_at' => now(),
    ]));
}

beforeEach(function () {
    $this->travelTo('2026-10-15 12:00:00');
    $this->key = v1Key();
    $this->ad = Ad::factory()->create();
});

describe('what is billed', function () {
    it('bills one call per successful search, search page, ad detail and v0 search', function () {
        Ad::factory()->create();
        $cursor = v1('GET', '/api/v1/ads?limit=1', $this->key)->assertOk()->assertHeader('Billed', 'true')->json('next_cursor');
        v1('GET', '/api/v1/ads?cursor='.rawurlencode($cursor), $this->key)->assertOk()->assertHeader('Billed', 'true');
        v1('GET', '/api/v1/ads/'.$this->ad->id, $this->key)->assertOk()->assertHeader('Billed', 'true');
        v0Billing($this->key)->assertOk()->assertHeader('Billed', 'true');

        $token = PersonalAccessToken::findToken($this->key);
        expect(DB::table('billable_calls')->orderBy('id')->get()->map(fn ($row) => [$row->user_id, $row->token_id, $row->endpoint, $row->client])->all())->toBe([
            [$token->tokenable_id, $token->id, 'v1.ads.search', 'rest'],
            [$token->tokenable_id, $token->id, 'v1.ads.search', 'rest'],
            [$token->tokenable_id, $token->id, 'v1.ads.show', 'rest'],
            [$token->tokenable_id, $token->id, 'v0.ads.search', 'rest'],
        ]);
    });

    it('records calls made through the MCP server, without billing them differently', function () {
        v1('GET', '/api/v1/ads', $this->key, ['X-Client' => 'mcp'])->assertOk()->assertHeader('Billed', 'true');
        v1('GET', '/api/v1/ads', $this->key, ['X-Client' => 'something-else'])->assertOk();

        expect(DB::table('billable_calls')->orderBy('id')->pluck('client')->all())->toBe(['mcp', 'rest']);
    });

    it('does not bill niches, errors or HEAD requests', function (Closure $call) {
        $call($this);

        expect(billed())->toBe(0);
    })->with([
        'niches' => [fn ($t) => v1('GET', '/api/v1/niches', $t->key)->assertOk()->assertHeaderMissing('Billed')],
        '422' => [fn ($t) => v1('GET', '/api/v1/ads?nich=skincare', $t->key)->assertStatus(422)->assertHeaderMissing('Billed')],
        '404' => [fn ($t) => v1('GET', '/api/v1/ads/999999', $t->key)->assertStatus(404)],
        '401' => [fn ($t) => v1('GET', '/api/v1/ads')->assertStatus(401)],
        '403' => [fn ($t) => v1('GET', '/api/v1/ads', v1Key([ApiScope::KeysManage]))->assertStatus(403)],
        '429 rate limited' => [function ($t) {
            spendRateLimit($t->key);
            v1('GET', '/api/v1/ads', $t->key)->assertStatus(429);
        }],
        '500' => [function ($t) {
            Exceptions::fake();
            Ad::retrieved(fn () => throw new RuntimeException('boom'));
            v1('GET', '/api/v1/ads', $t->key)->assertStatus(500);
        }],
        'v0 error' => [fn ($t) => v0Billing('ghk_'.str_repeat('a', 48))->assertStatus(400)],
        'HEAD' => [function ($t) {
            app('auth')->forgetGuards();
            $t->call('HEAD', '/api/v1/ads', server: ['HTTP_AUTHORIZATION' => "Bearer {$t->key}"])->assertOk();
        }],
    ]);

    it('fails without serving data when the call cannot be billed', function () {
        Exceptions::fake();
        Schema::drop('idempotency_keys');
        DB::statement('ALTER TABLE billable_calls RENAME TO billable_calls_gone');

        $response = v1('GET', '/api/v1/ads', $this->key);

        assertApiError($response, ErrorCode::InternalError);
        expect($response->getContent())->not->toContain($this->ad->headline);
    });
});

describe('Idempotency-Key', function () {
    it('bills repeats of the same request once, and still answers them', function () {
        $headers = ['Idempotency-Key' => 'search-1'];

        v1('GET', '/api/v1/ads?limit=5', $this->key, $headers)->assertOk()->assertHeader('Billed', 'true');
        $repeat = v1('GET', '/api/v1/ads?limit=5', $this->key, $headers)->assertOk()->assertHeader('Billed', 'false');

        expect(billed())->toBe(1)
            ->and(array_column($repeat->json('data'), 'id'))->toBe([$this->ad->id])
            ->and(DB::table('billable_calls')->value('idempotency_key'))->toBe('search-1');
    });

    it('runs a repeat again rather than replaying the first answer', function () {
        $headers = ['Idempotency-Key' => 'search-1'];
        v1('GET', '/api/v1/ads', $this->key, $headers)->assertOk();
        $new = Ad::factory()->create(['start_date' => now()->toDateString()]);

        $repeat = v1('GET', '/api/v1/ads', $this->key, $headers)->assertOk()->assertHeader('Billed', 'false');

        expect(array_column($repeat->json('data'), 'id'))->toContain($new->id);
    });

    it('bills a repeat again once 24 hours have passed since the billed call', function () {
        $headers = ['Idempotency-Key' => 'search-1'];
        v1('GET', '/api/v1/ads', $this->key, $headers)->assertHeader('Billed', 'true');

        $this->travelTo('2026-10-16 11:59:59');
        v1('GET', '/api/v1/ads', $this->key, $headers)->assertHeader('Billed', 'false');

        $this->travelTo('2026-10-16 12:00:00');
        v1('GET', '/api/v1/ads', $this->key, $headers)->assertHeader('Billed', 'true');

        $this->travelTo('2026-10-17 11:59:59');
        v1('GET', '/api/v1/ads', $this->key, $headers)->assertHeader('Billed', 'false');

        expect(billed())->toBe(2);
    });

    it('rejects a billed key sent with a different request, without billing it', function (string $other) {
        v1('GET', '/api/v1/ads?limit=5', $this->key, ['Idempotency-Key' => 'k1'])->assertOk();

        assertApiError(v1('GET', $other, $this->key, ['Idempotency-Key' => 'k1']), ErrorCode::IdempotencyKeyReused);
        expect(billed())->toBe(1);
    })->with(['/api/v1/ads?limit=6', '/api/v1/ads', 'ad detail' => fn () => '/api/v1/ads/'.Ad::query()->value('id')]);

    it('rejects a reused key on v0 with v0\'s error', function () {
        v1('GET', '/api/v1/ads', $this->key, ['Idempotency-Key' => 'k1'])->assertOk();

        expect(v0Billing($this->key, ['Idempotency-Key' => 'k1'])->assertStatus(400)->getContent())->toBe('{"error":"Invalid request"}')
            ->and(billed())->toBe(1);
    });

    it('does not use up the key on a failed request', function () {
        v1('GET', '/api/v1/ads?nich=skincare', $this->key, ['Idempotency-Key' => 'k1'])->assertStatus(422);

        v1('GET', '/api/v1/ads?niche=kitchen', $this->key, ['Idempotency-Key' => 'k1'])->assertOk()->assertHeader('Billed', 'true');
        expect(billed())->toBe(1);
    });

    it('keeps keys apart per API key', function () {
        $other = v1Key();

        v1('GET', '/api/v1/ads', $this->key, ['Idempotency-Key' => 'k1'])->assertHeader('Billed', 'true');
        v1('GET', '/api/v1/ads', $other, ['Idempotency-Key' => 'k1'])->assertHeader('Billed', 'true');

        expect(billed())->toBe(2);
    });

    it('rejects an invalid key on v1, and ignores it on v0', function (string $value) {
        $response = v1('GET', '/api/v1/ads', $this->key, ['Idempotency-Key' => $value]);

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect($response->json('error.details.0'))->toMatchArray(['param' => 'Idempotency-Key', 'issue' => 'invalid_value'])
            ->and(billed())->toBe(0);

        v0Billing($this->key, ['Idempotency-Key' => $value])->assertOk()->assertHeader('Billed', 'true');
        v0Billing($this->key, ['Idempotency-Key' => $value])->assertOk()->assertHeader('Billed', 'true');
        expect(billed())->toBe(2);
    })->with(['too long' => [str_repeat('k', 256)], 'space' => ['a b'], 'empty' => [''], 'non-ASCII' => ['ключ']]);

    it('rejects a repeated key header on v1', function () {
        // The test client can't send a header twice, so build the request directly.
        $request = Request::create('/api/v1/ads', server: ['HTTP_AUTHORIZATION' => "Bearer {$this->key}", 'HTTP_ACCEPT' => 'application/json']);
        $request->headers->set('Idempotency-Key', ['a', 'b']);
        app('auth')->forgetGuards();
        $response = app(Kernel::class)->handle($request);

        expect($response->getStatusCode())->toBe(422)
            ->and(json_decode($response->getContent(), true)['error']['details'][0]['issue'])->toBe('repeated_parameter');
    });

    it('accepts the longest key', function () {
        v1('GET', '/api/v1/ads', $this->key, ['Idempotency-Key' => str_repeat('k', 255)])->assertOk()->assertHeader('Billed', 'true');
    });
});

describe('monthly quota', function () {
    it('allows 1000 billable calls a month, then refuses with when the quota resets', function () {
        useQuota($this->key, 999);
        v1('GET', '/api/v1/ads', $this->key)->assertOk()->assertHeader('Billed', 'true');

        $response = v1('GET', '/api/v1/ads', $this->key);

        assertApiError($response, ErrorCode::QuotaExceeded, withResetsAt: true);
        expect($response->json('error'))->toMatchArray([
            'retryable' => false,
            'resets_at' => '2026-11-01T00:00:00Z',
            'message' => 'This account has used its 1000 billable calls for October 2026 (UTC). The quota resets at 2026-11-01T00:00:00Z.',
        ]);
        $response->assertHeader('Retry-After', (string) (17 * 86400 - 12 * 3600));
        expect(billed())->toBe(1000);
    });

    it('still answers free calls and repeats of billed keys over the quota', function () {
        v1('GET', '/api/v1/ads', $this->key, ['Idempotency-Key' => 'k1'])->assertOk();
        useQuota($this->key, 999);

        assertApiError(v1('GET', '/api/v1/ads/'.$this->ad->id, $this->key), ErrorCode::QuotaExceeded, withResetsAt: true);
        v1('GET', '/api/v1/niches', $this->key)->assertOk();
        v1('GET', '/api/v1/ads', $this->key, ['Idempotency-Key' => 'k1'])->assertOk()->assertHeader('Billed', 'false');
        expect(billed())->toBe(1000);
    });

    it('refuses an over-quota call before running it, so fixing the request would not help', function () {
        useQuota($this->key, 1000);
        Ad::retrieved(fn () => throw new RuntimeException('the search ran'));

        assertApiError(v1('GET', '/api/v1/ads?nich=skincare', $this->key), ErrorCode::QuotaExceeded, withResetsAt: true);
        assertApiError(v1('GET', '/api/v1/ads/'.$this->ad->id, $this->key), ErrorCode::QuotaExceeded, withResetsAt: true);
    });

    it('is shared by every key of the account, and v0 gets its usual error', function () {
        useQuota($this->key, 1000);

        assertApiError(v1('GET', '/api/v1/ads', v1Key()), ErrorCode::QuotaExceeded, withResetsAt: true);
        expect(v0Billing($this->key)->assertStatus(400)->getContent())->toBe('{"error":"Invalid request"}');
    });

    it('counts each account on its own', function () {
        useQuota($this->key, 1000);
        $otherUser = User::factory()->create();
        $other = app(ApiKeys::class)->issue($otherUser, 'other account', [ApiScope::SearchRead])[1];

        v1('GET', '/api/v1/ads', $other)->assertOk()->assertHeader('Billed', 'true');
    });

    it('resets at the start of the next calendar month (UTC)', function () {
        $this->travelTo('2026-10-31 23:59:59');
        useQuota($this->key, 1000);
        assertApiError(v1('GET', '/api/v1/ads', $this->key), ErrorCode::QuotaExceeded, withResetsAt: true);

        $this->travelTo('2026-11-01 00:00:00');

        v1('GET', '/api/v1/ads', $this->key)->assertOk()->assertHeader('Billed', 'true');
        expect(app(Meter::class)->usage(LocalUser::get()->id, now()))->toBe(1);
    });
});
