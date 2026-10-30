<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\PersonalAccessToken;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * The one error shape: exactly these fields, with the code's registry values.
 */
function assertApiError(TestResponse $response, ErrorCode $code): void
{
    $response->assertStatus($code->status());
    expect($response->json())->toHaveKey('error')->toHaveCount(1)
        ->and(array_keys($response->json('error')))->toBe(['code', 'message', 'next_step', 'retryable'])
        ->and($response->json('error.code'))->toBe($code->value)
        ->and($response->json('error.retryable'))->toBe($code->retryable())
        ->and($response->json('error.message'))->toBeString()->not->toBeEmpty()
        ->and($response->json('error.next_step'))->toBeString()->not->toBeEmpty();
}

/*
 * No v1 endpoint exists yet, so these tests use a stand-in route with the same middleware v1 will use.
 * Switch them to /api/v1/ads once it exists.
 */
beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'abilities:'.ApiScope::SearchRead->value])
        ->get('api/v1/__protected', fn () => ['ok' => true]);
});

describe('401 unauthenticated', function () {
    it('says no key was sent', function () {
        $response = v1('GET', '/api/v1/__protected');

        assertApiError($response, ErrorCode::Unauthenticated);
        expect($response->json('error.message'))->toBe('No API key was sent.');
        $response->assertHeader('WWW-Authenticate', 'Bearer');
    });

    it('uses the error shape when the client does not ask for JSON', function () {
        $response = $this->get('/api/v1/__protected', ['Accept' => '*/*']);

        assertApiError($response, ErrorCode::Unauthenticated);
    });

    it('says v1 ignores the v0 api_key parameter, without echoing the key', function () {
        $key = v1Key();
        app('auth')->forgetGuards();
        $response = $this->getJson('/api/v1/__protected?api_key='.urlencode($key));

        assertApiError($response, ErrorCode::Unauthenticated);
        expect($response->json('error.message'))->toContain('api_key')
            ->and($response->json('error.next_step'))->toContain('Authorization: Bearer')
            ->and($response->getContent())->not->toContain($key);
    });

    it('says an unknown or malformed key is invalid, without echoing it', function (string $key) {
        $response = v1('GET', '/api/v1/__protected', $key);

        assertApiError($response, ErrorCode::Unauthenticated);
        expect($response->json('error.message'))->toBe('The API key is invalid or has been revoked.')
            ->and($response->getContent())->not->toContain($key);
    })->with([
        'unknown' => ['ghk_'.str_repeat('a', 48)],
        'malformed' => ['abc|x'],
    ]);

    it('rejects a revoked key on the very next call', function () {
        $key = v1Key();
        v1('GET', '/api/v1/__protected', $key)->assertOk();

        app(ApiKeys::class)->revoke(PersonalAccessToken::query()->sole());

        assertApiError(v1('GET', '/api/v1/__protected', $key), ErrorCode::Unauthenticated);
    });

    it('accepts the same key on v0 and v1', function () {
        $key = v1Key();

        v1('GET', '/api/v1/__protected', $key)->assertOk();
        app('auth')->forgetGuards();
        $this->get('/api/v0/ads?api_key='.urlencode($key))->assertOk();
    });
});

it('returns 403 insufficient_scope naming the missing scope', function () {
    $response = v1('GET', '/api/v1/__protected', v1Key([ApiScope::KeysManage]));

    assertApiError($response, ErrorCode::InsufficientScope);
    expect($response->json('error.message'))->toContain('search:read');
});

it('returns 404 not_found for an unknown path', function (string $path) {
    $response = v1('GET', $path, v1Key());

    assertApiError($response, ErrorCode::NotFound);
    expect($response->json('error.message'))->toBe("There is no GET {$path}.");
})->with(['/api/v1/nothing', '/api/v2/ads']);

it('returns 405 method_not_allowed with an Allow header', function () {
    $response = v1('POST', '/api/v1/__protected', v1Key());

    assertApiError($response, ErrorCode::MethodNotAllowed);
    expect($response->headers->get('Allow'))->toContain('GET');
});

describe('500 internal_error', function () {
    beforeEach(function () {
        Route::middleware('api')->get('api/v1/__boom', function () {
            throw new RuntimeException('SQLSTATE[42P01] select * from secret_table where token = ghk_leak');
        });
    });

    it('uses the error shape and leaks nothing, even with debug on', function (bool $debug) {
        config(['app.debug' => $debug]);
        Exceptions::fake();

        $response = v1('GET', '/api/v1/__boom');

        assertApiError($response, ErrorCode::InternalError);
        expect($response->getContent())
            ->not->toContain('SQLSTATE')
            ->not->toContain('secret_table')
            ->not->toContain('ghk_leak')
            ->not->toContain('.php');
    })->with(['debug off' => false, 'debug on' => true]);

    it('still reports the exception', function () {
        Exceptions::fake();

        v1('GET', '/api/v1/__boom');

        Exceptions::assertReported(RuntimeException::class);
    });
});

it('leaves v0 errors untouched', function () {
    $response = $this->getJson('/api/v0/ads');

    $response->assertStatus(400);
    expect($response->getContent())->toBe('{"error":"Invalid request"}');
});

describe('error code registry', function () {
    it('gives every code a client or server status, wording and retry advice', function (ErrorCode $code) {
        expect($code->value)->toMatch('/\A[a-z]+(_[a-z]+)*\z/')
            ->and($code->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(600)
            ->and($code->message())->not->toBeEmpty()
            ->and($code->nextStep())->not->toBeEmpty();
    })->with(ErrorCode::cases());

    // Pinned literally: the other tests read statuses from the registry, so they cannot catch a wrong one.
    it('maps each code to its HTTP status', function () {
        expect(array_combine(
            array_map(fn (ErrorCode $code) => $code->value, ErrorCode::cases()),
            array_map(fn (ErrorCode $code) => $code->status(), ErrorCode::cases()),
        ))->toBe([
            'unauthenticated' => 401,
            'insufficient_scope' => 403,
            'not_found' => 404,
            'method_not_allowed' => 405,
            'internal_error' => 500,
        ]);
    });

    it('only marks server-side failures as retryable', function () {
        $retryable = array_filter(ErrorCode::cases(), fn (ErrorCode $code) => $code->retryable());

        expect(array_map(fn (ErrorCode $code) => $code->value, array_values($retryable)))->toBe(['internal_error']);
    });
});
