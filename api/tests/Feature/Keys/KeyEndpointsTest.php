<?php

use App\Auth\ApiKeys;
use App\Billing\BillableCall;
use App\Billing\Meter;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Errors\ErrorCode;
use App\Support\LocalUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Both scopes, so it may create keys of either scope; tests that need a manage-only key make one.
    $this->manager = v1Key([ApiScope::SearchRead, ApiScope::KeysManage]);
});

function keyRow(string $plaintext): PersonalAccessToken
{
    return PersonalAccessToken::findToken($plaintext);
}

function billCall(string $plaintext, string $client, CarbonImmutable $at): void
{
    $key = keyRow($plaintext);
    app(Meter::class)->bill(new BillableCall($key->tokenable_id, $key->getKey(), 'v1.ads.search', $client, null, ''), $at);
}

describe('scopes', function () {
    it('needs keys:manage to list, create or revoke keys', function () {
        $search = v1Key([ApiScope::SearchRead]);

        assertApiError(v1('GET', '/api/v1/keys', $search), ErrorCode::InsufficientScope);
        assertApiError(v1('POST', '/api/v1/keys', $search, body: ['name' => 'x']), ErrorCode::InsufficientScope);
        assertApiError(v1('DELETE', '/api/v1/keys/'.keyRow($search)->getKey(), $search), ErrorCode::InsufficientScope);

        expect(keyRow($search)->revoked_at)->toBeNull()
            ->and(PersonalAccessToken::query()->count())->toBe(2);
    });

    it('lets a keys:manage key manage keys but not search', function () {
        $manageOnly = v1Key([ApiScope::KeysManage]);

        v1('GET', '/api/v1/keys', $manageOnly)->assertOk();

        assertApiError(v1('GET', '/api/v1/ads', $manageOnly), ErrorCode::InsufficientScope);
    });

    it('lets a key create keys only with scopes it has itself', function () {
        $manageOnly = v1Key([ApiScope::KeysManage]);
        $before = PersonalAccessToken::query()->count();

        $minted = v1('POST', '/api/v1/keys', $manageOnly, body: ['name' => 'searcher', 'scopes' => ['search:read']]);
        $default = v1('POST', '/api/v1/keys', $manageOnly, body: ['name' => 'searcher']);

        assertApiError($minted, ErrorCode::InsufficientScope);
        assertApiError($default, ErrorCode::InsufficientScope);
        expect($minted->json('error.message'))->toContain('lacks search:read')
            ->and(PersonalAccessToken::query()->count())->toBe($before);
        v1('POST', '/api/v1/keys', $manageOnly, body: ['name' => 'admin', 'scopes' => ['keys:manage']])->assertCreated();
        v1('POST', '/api/v1/keys', $this->manager, body: ['name' => 'both', 'scopes' => ['search:read', 'keys:manage']])->assertCreated();
    });

    it('reports invalid fields before missing scopes', function () {
        assertApiError(v1('POST', '/api/v1/keys', v1Key([ApiScope::KeysManage]), body: ['nme' => 'x']), ErrorCode::InvalidParameters, withDetails: true);
    });

    it('shows usage to a key with either scope', function () {
        v1('GET', '/api/v1/usage', v1Key([ApiScope::SearchRead]))->assertOk();
        v1('GET', '/api/v1/usage', $this->manager)->assertOk();
    });
});

describe('list', function () {
    it('lists the account\'s keys newest first, with prefix, scopes and dates, never the key or its hash', function () {
        $search = v1Key([ApiScope::SearchRead]);

        $response = v1('GET', '/api/v1/keys', $this->manager)->assertOk();

        expect($response->json('data.*.id'))->toBe([keyRow($search)->getKey(), keyRow($this->manager)->getKey()])
            ->and($response->json('data.0'))->toMatchArray([
                'name' => 'v1 test',
                'prefix' => substr($search, 0, 12),
                'scopes' => ['search:read'],
                'revoked_at' => null,
                'billable_calls_this_period' => 0,
            ])
            ->and($response->json('data.0.created_at'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
            ->and($response->getContent())->not->toContain($search)
            ->and($response->getContent())->not->toContain(keyRow($search)->token);
    });

    it('shows when each key was last used, including the listing call itself', function () {
        $search = v1Key([ApiScope::SearchRead]);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:15:00', 'UTC'));
        v1('GET', '/api/v1/niches', $search)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:20:00', 'UTC'));

        $keys = collect(v1('GET', '/api/v1/keys', $this->manager)->json('data'))->keyBy('prefix');

        expect($keys[substr($search, 0, 12)]['last_used_at'])->toBe('2026-10-31T09:15:00Z')
            ->and($keys[substr($this->manager, 0, 12)]['last_used_at'])->toBe('2026-10-31T09:20:00Z');
    });

    it('does not list another account\'s keys', function () {
        $other = User::factory()->create();
        [$foreign] = app(ApiKeys::class)->issue($other, 'theirs', [ApiScope::SearchRead]);

        $ids = v1('GET', '/api/v1/keys', $this->manager)->json('data.*.id');

        expect($ids)->not->toContain($foreign->getKey());
    });

    it('counts each key\'s billable calls in the current month only, from the billing table', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'));
        $search = v1Key([ApiScope::SearchRead]);
        billCall($search, 'rest', CarbonImmutable::parse('2026-09-30 23:59:59', 'UTC'));
        billCall($search, 'rest', CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'));
        billCall($search, 'mcp', CarbonImmutable::parse('2026-10-31 11:00:00', 'UTC'));

        $keys = collect(v1('GET', '/api/v1/keys', $this->manager)->json('data'))->keyBy('prefix');

        expect($keys[substr($search, 0, 12)]['billable_calls_this_period'])->toBe(2)
            ->and($keys[substr($this->manager, 0, 12)]['billable_calls_this_period'])->toBe(0);
    });
});

describe('create', function () {
    it('creates a working key, shows it once, and stores only its hash', function () {
        $response = v1('POST', '/api/v1/keys', $this->manager, body: ['name' => '  Claude Desktop  ', 'scopes' => ['search:read']]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $secret = $response->json('secret');
        expect($secret)->toMatch('/^ghk_[A-Za-z0-9]{40}[0-9a-f]{8}$/')
            ->and($response->json('data'))->toMatchArray(['name' => 'Claude Desktop', 'prefix' => substr($secret, 0, 12), 'scopes' => ['search:read'], 'last_used_at' => null])
            ->and($response->json('note'))->toContain('only time')
            ->and(DB::table('personal_access_tokens')->where('token', $secret)->exists())->toBeFalse()
            ->and(keyRow($secret)->token)->toBe(hash('sha256', $secret))
            ->and(keyRow($secret)->tokenable_id)->toBe(LocalUser::get()->getKey());

        v1('GET', '/api/v1/niches', $secret)->assertOk();
        expect(v1('GET', '/api/v1/keys', $this->manager)->getContent())->not->toContain($secret);
    });

    it('gives a new key search:read when no scopes are sent, and both when asked', function () {
        $default = v1('POST', '/api/v1/keys', $this->manager, body: ['name' => 'a'])->json('data.scopes');
        $both = v1('POST', '/api/v1/keys', $this->manager, body: ['name' => 'b', 'scopes' => ['search:read', 'keys:manage', 'search:read']])->json('data.scopes');

        expect($default)->toBe(['search:read'])->and($both)->toBe(['search:read', 'keys:manage']);
    });

    it('creates keys for the calling key\'s account', function () {
        $other = User::factory()->create();
        [, $theirManager] = app(ApiKeys::class)->issue($other, 'admin', [ApiScope::KeysManage]);

        $secret = v1('POST', '/api/v1/keys', $theirManager, body: ['name' => 'x', 'scopes' => ['keys:manage']])->json('secret');

        expect(keyRow($secret)->tokenable_id)->toBe($other->getKey());
    });

    it('rejects each problem with what is allowed and a likely fix, and creates nothing', function (array $body, array $details) {
        $before = PersonalAccessToken::query()->count();

        $response = v1('POST', '/api/v1/keys', $this->manager, body: $body);

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect(array_map(fn (array $d) => array_intersect_key($d, array_flip(['param', 'issue', 'did_you_mean'])), $response->json('error.details')))->toBe($details)
            ->and(PersonalAccessToken::query()->count())->toBe($before);
    })->with([
        'no name' => [['scopes' => ['search:read']], [['param' => 'name', 'issue' => 'missing']]],
        'empty name' => [['name' => '   '], [['param' => 'name', 'issue' => 'empty_value']]],
        'name not text' => [['name' => 42], [['param' => 'name', 'issue' => 'invalid_value']]],
        'name too long' => [['name' => str_repeat('n', 101)], [['param' => 'name', 'issue' => 'too_long']]],
        'misspelled field' => [['name' => 'x', 'scope' => ['search:read']], [['param' => 'scope', 'issue' => 'unknown_parameter', 'did_you_mean' => 'scopes']]],
        'misspelled scope' => [['name' => 'x', 'scopes' => ['search_read']], [['param' => 'scopes', 'issue' => 'invalid_value', 'did_you_mean' => 'search:read']]],
        'unknown scope' => [['name' => 'x', 'scopes' => ['admin']], [['param' => 'scopes', 'issue' => 'invalid_value']]],
        'no scopes' => [['name' => 'x', 'scopes' => []], [['param' => 'scopes', 'issue' => 'empty_value']]],
        'scopes not a list' => [['name' => 'x', 'scopes' => 'search:read'], [['param' => 'scopes', 'issue' => 'invalid_value']]],
        'two problems' => [['nme' => 'x'], [['param' => 'nme', 'issue' => 'unknown_parameter', 'did_you_mean' => 'name'], ['param' => 'name', 'issue' => 'missing']]],
    ]);

    it('accepts names of up to 100 characters after trimming, counted as characters', function (string $name) {
        v1('POST', '/api/v1/keys', $this->manager, body: ['name' => $name])->assertCreated()->assertJsonPath('data.name', trim($name));
    })->with([
        '100 characters' => str_repeat('n', 100),
        '100 multibyte characters' => str_repeat('ж', 100),
        '100 characters with surrounding spaces' => '  '.str_repeat('n', 100).'  ',
    ]);

    it('rejects a 101-character multibyte name', function () {
        assertApiError(v1('POST', '/api/v1/keys', $this->manager, body: ['name' => str_repeat('ж', 101)]), ErrorCode::InvalidParameters, withDetails: true);
    });

    it('rejects a name that looks like an API key, and never stores or echoes it', function () {
        $secret = v1Key();
        $before = PersonalAccessToken::query()->count();

        $response = v1('POST', '/api/v1/keys', $this->manager, body: ['name' => "my key {$secret}"]);

        assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
        expect($response->json('error.details.0'))->toMatchArray(['param' => 'name', 'issue' => 'invalid_value'])
            ->and($response->getContent())->not->toContain($secret)
            ->and(PersonalAccessToken::query()->count())->toBe($before)
            ->and(v1('GET', '/api/v1/keys', $this->manager)->getContent())->not->toContain($secret);
    });

    it('refuses a body larger than PHP accepts with 413 in the error shape', function () {
        app('auth')->forgetGuards();
        $response = $this->call('POST', '/api/v1/keys', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$this->manager}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_LENGTH' => (string) (64 * 1024 * 1024),
        ], content: '{}');

        assertApiError($response, ErrorCode::PayloadTooLarge);
        expect(PersonalAccessToken::query()->count())->toBe(1);
    });

    it('rejects a body that is not a JSON object', function (string $content) {
        app('auth')->forgetGuards();
        $response = $this->call('POST', '/api/v1/keys', server: [
            'HTTP_AUTHORIZATION' => "Bearer {$this->manager}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $content);

        assertApiError($response, ErrorCode::BadRequest);
    })->with(['empty' => '', 'invalid JSON' => '{"name":', 'a list' => '["x"]', 'a string' => '"x"', 'too deep' => str_repeat('{"a":', 20).'1'.str_repeat('}', 20)]);

    it('never echoes a key put in a field name or scope', function () {
        $secret = v1Key();

        $response = v1('POST', '/api/v1/keys', $this->manager, body: ['name' => 'x', $secret => 1, 'scopes' => [$secret]]);

        expect($response->getContent())->not->toContain($secret)->toContain('ghk_[redacted]');
    });
});

describe('revoke', function () {
    it('revokes a key of the account; it fails on its very next call and stays listed', function () {
        $search = v1Key([ApiScope::SearchRead]);
        v1('GET', '/api/v1/niches', $search)->assertOk();

        $response = v1('DELETE', '/api/v1/keys/'.keyRow($search)->getKey(), $this->manager)->assertOk();

        expect($response->json('data.revoked_at'))->not->toBeNull();
        assertApiError(v1('GET', '/api/v1/niches', $search), ErrorCode::Unauthenticated);
        expect(collect(v1('GET', '/api/v1/keys', $this->manager)->json('data'))->firstWhere('id', keyRow($search)->getKey())['revoked_at'])
            ->toBe($response->json('data.revoked_at'));
    });

    it('keeps the first revocation time when revoked again', function () {
        $id = keyRow(v1Key())->getKey();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00', 'UTC'));
        $first = v1('DELETE', "/api/v1/keys/{$id}", $this->manager)->json('data.revoked_at');
        $this->travelTo(CarbonImmutable::parse('2026-10-31 11:00:00', 'UTC'));

        $second = v1('DELETE', "/api/v1/keys/{$id}", $this->manager)->assertOk()->json('data.revoked_at');

        expect($second)->toBe($first)->toBe('2026-10-31T10:00:00Z');
    });

    it('lets a key revoke itself, and it then stops working', function () {
        v1('DELETE', '/api/v1/keys/'.keyRow($this->manager)->getKey(), $this->manager)->assertOk();

        assertApiError(v1('GET', '/api/v1/keys', $this->manager), ErrorCode::Unauthenticated);
    });

    it('answers not_found for another account\'s key, and leaves it working', function () {
        $other = User::factory()->create();
        [$foreign, $foreignKey] = app(ApiKeys::class)->issue($other, 'theirs', [ApiScope::SearchRead]);

        assertApiError(v1('DELETE', '/api/v1/keys/'.$foreign->getKey(), $this->manager), ErrorCode::NotFound);

        expect($foreign->refresh()->revoked_at)->toBeNull();
        v1('GET', '/api/v1/niches', $foreignKey)->assertOk();
    });

    it('does not read a malformed id as the number it starts with', function (string $suffix) {
        $key = v1Key();
        $id = keyRow($key)->getKey();

        assertApiError(v1('DELETE', "/api/v1/keys/{$id}{$suffix}", $this->manager), ErrorCode::NotFound);

        expect(keyRow($key)->revoked_at)->toBeNull();
    })->with(['.0', 'abc', '%20']);

    it('answers not_found for an id that is not a key', function (string $id) {
        assertApiError(v1('DELETE', '/api/v1/keys/'.$id, $this->manager), ErrorCode::NotFound);
    })->with(['999999', 'abc', '1.5', '-1', '99999999999999999999999']);
});

describe('usage', function () {
    it('counts this month\'s billable calls from the billing table, by client, against the quota', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'));
        $search = v1Key([ApiScope::SearchRead]);
        billCall($search, 'rest', CarbonImmutable::parse('2026-09-30 23:59:59', 'UTC'));
        billCall($search, 'rest', CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'));
        billCall($search, 'mcp', CarbonImmutable::parse('2026-10-03 08:00:00', 'UTC'));
        billCall($this->manager, 'mcp', CarbonImmutable::parse('2026-10-31 11:00:00', 'UTC'));

        $usage = v1('GET', '/api/v1/usage', $search)->json('data');

        expect($usage)->toBe([
            'period_start' => '2026-10-01T00:00:00Z',
            'resets_at' => '2026-11-01T00:00:00Z',
            'billable_calls' => 3,
            'quota' => Meter::MONTHLY_QUOTA,
            'remaining' => Meter::MONTHLY_QUOTA - 3,
            'by_client' => ['rest' => 1, 'mcp' => 2],
        ])->and($usage['billable_calls'])->toBe(app(Meter::class)->usage(LocalUser::get()->getKey(), now()));
    });

    it('agrees with real billed calls, and is not billed itself', function () {
        Ad::factory()->create();
        $search = v1Key([ApiScope::SearchRead]);
        v1('GET', '/api/v1/ads', $search)->assertOk();
        v1('GET', '/api/v1/ads', $search, ['X-Client' => 'mcp'])->assertOk();

        v1('GET', '/api/v1/keys', $this->manager)->assertOk();
        $created = v1('POST', '/api/v1/keys', $this->manager, body: ['name' => 'x'])->assertCreated();
        v1('DELETE', '/api/v1/keys/'.$created->json('data.id'), $this->manager)->assertOk();
        $usage = v1('GET', '/api/v1/usage', $search)->json('data');

        expect($usage['billable_calls'])->toBe(2)
            ->and($usage['by_client'])->toBe(['rest' => 1, 'mcp' => 1])
            ->and(DB::table('billable_calls')->count())->toBe(2);
    });

    it('counts only the calling account\'s calls', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'));
        $other = User::factory()->create();
        [, $theirs] = app(ApiKeys::class)->issue($other, 'theirs', [ApiScope::SearchRead]);
        billCall($theirs, 'mcp', CarbonImmutable::parse('2026-10-31 11:00:00', 'UTC'));
        billCall($this->manager, 'rest', CarbonImmutable::parse('2026-10-31 11:00:00', 'UTC'));

        expect(v1('GET', '/api/v1/usage', $this->manager)->json('data'))->toMatchArray(['billable_calls' => 1, 'by_client' => ['rest' => 1, 'mcp' => 0]])
            ->and(v1('GET', '/api/v1/usage', $theirs)->json('data'))->toMatchArray(['billable_calls' => 1, 'by_client' => ['rest' => 0, 'mcp' => 1]]);
    });

    it('shows no calls left, not a negative number, over the quota', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'));
        $key = keyRow($this->manager);
        DB::table('billable_calls')->insert(array_fill(0, Meter::MONTHLY_QUOTA + 5, [
            'user_id' => $key->tokenable_id, 'token_id' => $key->getKey(), 'endpoint' => 'v1.ads.search', 'client' => 'rest', 'created_at' => now(),
        ]));

        expect(v1('GET', '/api/v1/usage', $this->manager)->json('data'))->toMatchArray(['billable_calls' => Meter::MONTHLY_QUOTA + 5, 'remaining' => 0]);
    });
});

it('counts key management against the key\'s rate limit', function () {
    // Inside one clock minute, so the spent limit can't reset before the request.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:15', 'UTC'));
    spendRateLimit($this->manager);

    assertApiError(v1('GET', '/api/v1/keys', $this->manager), ErrorCode::RateLimited, withRetryAfter: true);
});

it('rejects query parameters on every key endpoint', function (string $method, string $path) {
    $id = keyRow(v1Key())->getKey();

    assertApiError(v1($method, str_replace('{id}', (string) $id, $path).'?all=1', $this->manager), ErrorCode::InvalidParameters, withDetails: true);
})->with([['GET', '/api/v1/keys'], ['POST', '/api/v1/keys'], ['DELETE', '/api/v1/keys/{id}'], ['GET', '/api/v1/usage']]);
