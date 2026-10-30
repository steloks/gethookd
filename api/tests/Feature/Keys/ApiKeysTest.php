<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\PersonalAccessToken;
use App\Support\LocalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

/**
 * @param  list<ApiScope>  $scopes
 * @return array{0: PersonalAccessToken, 1: string}
 */
function issueKey(array $scopes = [ApiScope::SearchRead]): array
{
    return app(ApiKeys::class)->issue(LocalUser::get(), 'keys test', $scopes);
}

function callV0(string $key): TestResponse
{
    // The test client reuses one app; a real request starts with fresh guards.
    app('auth')->forgetGuards();

    return test()->get('/api/v0/ads?'.http_build_query(['api_key' => $key]));
}

describe('issue', function () {
    it('returns a ghk_ key with a CRC32 checksum and no Sanctum id part', function () {
        [, $plaintext] = issueKey();

        expect($plaintext)->toMatch('/\Aghk_[A-Za-z0-9]{40}[0-9a-f]{8}\z/')
            ->not->toContain('|')
            ->and(substr($plaintext, 44))->toBe(hash('crc32b', substr($plaintext, 4, 40)));
    });

    it('stores only the SHA-256 of the key, never the plaintext', function () {
        [$key, $plaintext] = issueKey();

        $row = (array) DB::table('personal_access_tokens')->where('id', $key->id)->sole();

        expect($row['token'])->toBe(hash('sha256', $plaintext));
        foreach ($row as $column => $value) {
            expect((string) $value)->not->toContain($plaintext, "column {$column} holds the plaintext");
        }
    });

    it('stores a visible 12-character prefix that is the start of the key', function () {
        [$key, $plaintext] = issueKey();

        expect($key->fresh()->prefix)
            ->toHaveLength(12)
            ->toStartWith('ghk_')
            ->toBe(substr($plaintext, 0, 12));
    });

    it('never exposes the hash when the key is serialised', function () {
        [$key] = issueKey();

        expect($key->fresh()->toArray())->not->toHaveKey('token');
    });

    it('can be found by Sanctum without the id part', function () {
        [$key, $plaintext] = issueKey();

        expect(PersonalAccessToken::findToken($plaintext)?->id)->toBe($key->id);
    });

    it('stores the requested scopes as abilities, without duplicates', function () {
        [$key] = issueKey([ApiScope::SearchRead, ApiScope::KeysManage, ApiScope::SearchRead]);

        expect($key->fresh()->abilities)->toBe(['search:read', 'keys:manage']);
    });

    it('refuses to issue a key without scopes', function () {
        expect(fn () => issueKey([]))->toThrow(InvalidArgumentException::class);
        expect(PersonalAccessToken::query()->count())->toBe(0);
    });
});

describe('revoke', function () {
    it('keeps the key listed and marks it revoked at the current time', function () {
        $this->travelTo('2026-10-05 12:00:00');
        [$key] = issueKey();

        app(ApiKeys::class)->revoke($key);

        $stored = PersonalAccessToken::query()->sole();
        expect($stored->id)->toBe($key->id)
            ->and($stored->isRevoked())->toBeTrue()
            ->and($stored->revoked_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');
    });

    it('keeps the first revocation time when revoked again', function () {
        $this->travelTo('2026-10-05 12:00:00');
        [$key] = issueKey();
        app(ApiKeys::class)->revoke($key);

        $this->travelTo('2026-10-07 09:00:00');
        app(ApiKeys::class)->revoke(PersonalAccessToken::query()->sole());

        expect(PersonalAccessToken::query()->sole()->revoked_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');
    });
});

describe('authentication', function () {
    it('records when a key was last used', function () {
        $this->travelTo('2026-10-05 12:00:00');
        [$key, $plaintext] = issueKey();
        expect($key->fresh()->last_used_at)->toBeNull();

        $this->travelTo('2026-10-06 08:30:00');
        callV0($plaintext)->assertOk();

        expect($key->fresh()->last_used_at->toIso8601String())->toBe('2026-10-06T08:30:00+00:00');
    });

    it('does not record use of a revoked key', function () {
        [$key, $plaintext] = issueKey();
        app(ApiKeys::class)->revoke($key);

        callV0($plaintext)->assertStatus(400);

        expect($key->fresh()->last_used_at)->toBeNull();
    });

    it('rejects malformed keys before they reach the database', function (string $presented) {
        Exceptions::fake();

        callV0($presented)->assertStatus(400);

        Exceptions::assertNothingReported();
    })->with([
        'non-numeric sanctum id' => ['abc|x'],
        'sanctum id form' => ['1|ghk_'.str_repeat('a', 48)],
        'wrong prefix' => ['xyz_'.str_repeat('a', 48)],
    ]);

    it('rejects a real key presented in the Sanctum id|key form', function () {
        [$key, $plaintext] = issueKey();

        callV0("{$key->id}|{$plaintext}")->assertStatus(400);
    });
});
