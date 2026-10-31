<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\PersonalAccessToken;
use App\Support\Errors\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->adPath = '/api/v1/ads/'.Ad::factory()->create()->id;
});

describe('401 unauthenticated', function () {
    it('says no key was sent', function () {
        $response = v1('GET', $this->adPath);

        assertApiError($response, ErrorCode::Unauthenticated);
        expect($response->json('error.message'))->toBe('No API key was sent.');
        $response->assertHeader('WWW-Authenticate', 'Bearer');
    });

    it('uses the error shape when the client does not ask for JSON', function () {
        $response = $this->get($this->adPath, ['Accept' => '*/*']);

        assertApiError($response, ErrorCode::Unauthenticated);
    });

    it('says v1 ignores the v0 api_key parameter, without echoing the key', function () {
        $key = v1Key();
        app('auth')->forgetGuards();
        $response = $this->getJson($this->adPath.'?api_key='.urlencode($key));

        assertApiError($response, ErrorCode::Unauthenticated);
        expect($response->json('error.message'))->toContain('api_key')
            ->and($response->json('error.next_step'))->toContain('Authorization: Bearer')
            ->and($response->getContent())->not->toContain($key);
    });

    it('says an unknown or malformed key is invalid, without echoing it', function (string $key) {
        $response = v1('GET', $this->adPath, $key);

        assertApiError($response, ErrorCode::Unauthenticated);
        expect($response->json('error.message'))->toBe('The API key is invalid or has been revoked.')
            ->and($response->getContent())->not->toContain($key);
    })->with([
        'unknown' => ['ghk_'.str_repeat('a', 48)],
        'malformed' => ['abc|x'],
    ]);

    it('rejects a revoked key on the very next call', function (string $path) {
        $path = $path === 'ad' ? $this->adPath : $path;
        $key = v1Key();
        v1('GET', $path, $key)->assertOk();

        app(ApiKeys::class)->revoke(PersonalAccessToken::query()->sole());

        assertApiError(v1('GET', $path, $key), ErrorCode::Unauthenticated);
    })->with(['ad', '/api/v1/ads', '/api/v1/niches']);

    it('uses the error shape and leaks nothing when looking up the key fails', function () {
        $key = v1Key();
        Exceptions::fake();
        PersonalAccessToken::retrieved(fn () => throw new RuntimeException('SQLSTATE[08006] connection to secret-db failed'));

        $response = v1('GET', $this->adPath, $key);

        assertApiError($response, ErrorCode::InternalError);
        expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('secret-db')->not->toContain(substr($key, 4, 12));
        Exceptions::assertReported(RuntimeException::class);
    });

    it('accepts the same key on v0 and v1', function () {
        $key = v1Key();

        v1('GET', $this->adPath, $key)->assertOk();
        app('auth')->forgetGuards();
        $this->get('/api/v0/ads?api_key='.urlencode($key))->assertOk();
    });
});

it('returns 403 insufficient_scope naming the missing scope', function () {
    $response = v1('GET', $this->adPath, v1Key([ApiScope::KeysManage]));

    assertApiError($response, ErrorCode::InsufficientScope);
    expect($response->json('error.message'))->toContain('search:read');
});

it('returns 404 not_found for an unknown path', function (string $path) {
    $response = v1('GET', $path, v1Key());

    assertApiError($response, ErrorCode::NotFound);
    expect($response->json('error.message'))->toBe("There is no GET {$path}.");
})->with(['/api/v1/nothing', '/api/v2/ads']);

it('never echoes a long path in full or a key pasted into the path', function (string $key) {
    $response = v1('GET', '/api/v1/'.$key.'/'.str_repeat('x', 3000), v1Key());

    assertApiError($response, ErrorCode::NotFound);
    expect(mb_strlen($response->json('error.message')))->toBeLessThan(130)
        ->and(strtolower(rawurldecode($response->getContent())))->not->toContain('kkkkkkkk')->toContain('ghk_[redacted]');
})->with([
    'as is' => ['ghk_'.str_repeat('k', 48)],
    'upper case' => ['GHK_'.str_repeat('K', 48)],
    'underscore encoded' => ['ghk%5F'.str_repeat('k', 48)],
    'letters encoded' => ['ghk_'.str_repeat('%6B', 48)],
]);

it('returns 405 method_not_allowed with an Allow header', function () {
    $response = v1('POST', $this->adPath, v1Key());

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

describe('other HTTP errors keep their meaning', function () {
    beforeEach(function () {
        Route::middleware('api')->get('api/v1/__abort/{status}', function (int $status) {
            abort($status, 'framework text not meant for callers', request()->has('wait') ? ['Retry-After' => request('wait')] : []);
        });
    });

    it('maps a client error to a non-retryable bad_request', function (int $status) {
        $response = v1('GET', "/api/v1/__abort/{$status}");

        assertApiError($response, ErrorCode::BadRequest);
        expect($response->getContent())->not->toContain('framework text');
    })->with([400, 413, 414, 431]);

    it('maps 429 to rate_limited with how long to wait, in the body and the header', function () {
        $response = v1('GET', '/api/v1/__abort/429?wait=17');

        assertApiError($response, ErrorCode::RateLimited, withRetryAfter: true);
        expect($response->json('error.retry_after'))->toBe(17);
        $response->assertHeader('Retry-After', '17');
    });

    it('always tells a rate-limited caller how long to wait', function () {
        $response = v1('GET', '/api/v1/__abort/429');

        assertApiError($response, ErrorCode::RateLimited, withRetryAfter: true);
        expect($response->json('error.retry_after'))->toBeGreaterThanOrEqual(1);
        $response->assertHeader('Retry-After');
    });

    it('keeps 401 an authentication error, saying what to send', function () {
        $response = v1('GET', '/api/v1/__abort/401');

        assertApiError($response, ErrorCode::Unauthenticated);
        $response->assertHeader('WWW-Authenticate', 'Bearer');
    });

    it('keeps 403 a refusal, whether from abort or a denied authorization', function (string $path) {
        Route::middleware('api')->get('api/v1/__deny', fn () => throw new AuthorizationException('policy text not meant for callers'));

        $response = v1('GET', $path);

        assertApiError($response, ErrorCode::Forbidden);
        expect($response->getContent())->not->toContain('policy text')->not->toContain('framework text');
    })->with(['/api/v1/__abort/403', '/api/v1/__deny']);

    it('maps 503 to a retryable unavailable', function () {
        assertApiError(v1('GET', '/api/v1/__abort/503'), ErrorCode::Unavailable);
    });

    it('maps any other server error to internal_error', function () {
        Exceptions::fake();

        assertApiError(v1('GET', '/api/v1/__abort/502'), ErrorCode::InternalError);
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
            'bad_request' => 400,
            'unauthenticated' => 401,
            'insufficient_scope' => 403,
            'forbidden' => 403,
            'not_found' => 404,
            'method_not_allowed' => 405,
            'invalid_parameters' => 422,
            'idempotency_key_reused' => 422,
            'rate_limited' => 429,
            'quota_exceeded' => 429,
            'internal_error' => 500,
            'unavailable' => 503,
        ]);
    });

    it('only marks waiting out a limit and server-side failures as retryable', function () {
        $retryable = array_filter(ErrorCode::cases(), fn (ErrorCode $code) => $code->retryable());

        expect(array_map(fn (ErrorCode $code) => $code->value, array_values($retryable)))->toBe(['rate_limited', 'internal_error', 'unavailable']);
    });
});
