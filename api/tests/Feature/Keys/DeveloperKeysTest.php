<?php

use App\Enums\ApiScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Errors\ErrorCode;
use App\Support\LocalUser;

/*
 * The developer page's JSON endpoints: the local user's keys, without a login (as the spec
 * allows), protected by CSRF and a local-host check instead.
 */

/**
 * Laravel skips CSRF checks while running tests; these tests switch them back on.
 */
function enforceCsrf(): void
{
    app()->detectEnvironment(fn () => 'local');
}

it('lists, creates and revokes the local user\'s keys, without an API key', function () {
    $created = $this->postJson('/developer/keys', ['name' => 'laptop'])->assertCreated();
    $secret = $created->json('secret');

    expect($this->getJson('/developer/keys')->assertOk()->json('data.*.prefix'))->toBe([substr($secret, 0, 12)])
        ->and(PersonalAccessToken::findToken($secret)->tokenable_id)->toBe(LocalUser::get()->getKey());
    $created->assertHeader('Cache-Control', 'no-store, private');

    $this->deleteJson('/developer/keys/'.$created->json('data.id'))->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
    assertApiError(v1('GET', '/api/v1/niches', $secret), ErrorCode::Unauthenticated);
});

it('shows the same keys and usage as the API does for the same account', function () {
    $manager = v1Key([ApiScope::SearchRead, ApiScope::KeysManage]);

    // The API call is made first: it updates its own key's last_used_at.
    $apiKeys = v1('GET', '/api/v1/keys', $manager)->json();
    $apiUsage = v1('GET', '/api/v1/usage', $manager)->json();

    expect($this->getJson('/developer/keys')->json())->toBe($apiKeys)
        ->and($this->getJson('/developer/usage')->json())->toBe($apiUsage);
});

it('never shows or revokes another account\'s keys', function () {
    $other = User::factory()->create();
    $theirs = $other->createToken('theirs', ['search:read'])->accessToken;

    expect($this->getJson('/developer/keys')->json('data'))->toBe([]);
    assertApiError($this->deleteJson('/developer/keys/'.$theirs->getKey()), ErrorCode::NotFound);
    expect($theirs->refresh()->revoked_at)->toBeNull();
});

it('answers errors in the API error shape', function () {
    assertApiError($this->postJson('/developer/keys', ['nme' => 'x']), ErrorCode::InvalidParameters, withDetails: true);
    assertApiError($this->deleteJson('/developer/keys/999999'), ErrorCode::NotFound);
});

describe('cross-site protection', function () {
    it('refuses a write that is neither same-origin nor carries the session\'s CSRF token', function () {
        enforceCsrf();

        $response = $this->postJson('/developer/keys', ['name' => 'planted']);

        assertApiError($response, ErrorCode::Forbidden);
        expect($response->json('error.message'))->toContain('CSRF')
            ->and(PersonalAccessToken::query()->count())->toBe(0);
    });

    it('refuses a revoke from another site', function () {
        $key = v1Key();
        enforceCsrf();

        assertApiError($this->deleteJson('/developer/keys/'.PersonalAccessToken::findToken($key)->getKey(), [], ['Sec-Fetch-Site' => 'cross-site']), ErrorCode::Forbidden);

        expect(PersonalAccessToken::findToken($key)->revoked_at)->toBeNull();
    });

    it('accepts a write the browser marks same-origin', function () {
        enforceCsrf();

        $this->postJson('/developer/keys', ['name' => 'laptop'], ['Sec-Fetch-Site' => 'same-origin'])->assertCreated();
    });

    it('accepts a write with the session\'s CSRF token', function () {
        enforceCsrf();
        $this->get('/developer/keys');
        $token = session()->token();

        $this->postJson('/developer/keys', ['name' => 'laptop'], ['X-CSRF-TOKEN' => $token])->assertCreated();
    });

    it('only answers on this machine\'s host names, so a rebinding site gets nothing', function (string $method, string $path) {
        $response = $this->json($method, 'http://evil.example:8000'.$path, $method === 'POST' ? ['name' => 'x'] : []);

        assertApiError($response, ErrorCode::Forbidden);
        expect($response->getContent())->not->toContain('"secret"');
    })->with([['GET', '/developer/keys'], ['GET', '/developer/usage'], ['POST', '/developer/keys'], ['DELETE', '/developer/keys/1']]);

    it('answers on localhost and 127.0.0.1', function (string $host) {
        $this->getJson("http://{$host}:8000/developer/keys")->assertOk();
    })->with(['localhost', '127.0.0.1']);

    it('sends no CORS headers, so other sites can\'t read the key list', function () {
        $response = $this->getJson('/developer/keys', ['Origin' => 'https://evil.example']);

        $response->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    });

    it('never lets the page\'s session authenticate an API call', function () {
        $this->getJson('/developer/keys')->assertOk();

        assertApiError($this->getJson('/api/v1/keys'), ErrorCode::Unauthenticated);
        assertApiError($this->getJson('/api/v1/usage'), ErrorCode::Unauthenticated);
    });
});
