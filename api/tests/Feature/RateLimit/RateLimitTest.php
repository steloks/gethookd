<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\PersonalAccessToken;
use App\RateLimit\KeyRateLimiter;
use App\Support\Errors\ErrorCode;
use App\Support\LocalUser;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\TimeoutException;

function v0Call(string $key): TestResponse
{
    app('auth')->forgetGuards();

    return test()->get('/api/v0/ads?api_key='.urlencode($key));
}

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:15');
    $this->key = v1Key();
});

it('allows 60 requests a minute, then refuses with how long to wait until the minute ends', function () {
    foreach (range(1, 60) as $i) {
        v1('GET', '/api/v1/niches', $this->key)->assertOk();
    }

    $response = v1('GET', '/api/v1/niches', $this->key);

    assertApiError($response, ErrorCode::RateLimited, withRetryAfter: true);
    expect($response->json('error.retry_after'))->toBe(45)
        ->and($response->json('error.next_step'))->toBe('Wait 45 seconds (retry_after), then send the same request again.');
    $response->assertHeader('Retry-After', '45');
});

it('rounds a part second up, so a caller that waits Retry-After is let through and one that waits less is not', function () {
    $this->travelTo('2026-10-05 12:00:30.400');
    spendRateLimit($this->key, 60);

    expect(v1('GET', '/api/v1/niches', $this->key)->json('error.retry_after'))->toBe(30);

    $this->travelTo('2026-10-05 12:00:59.400');
    assertApiError(v1('GET', '/api/v1/niches', $this->key), ErrorCode::RateLimited, withRetryAfter: true);

    $this->travelTo('2026-10-05 12:01:00.400');
    v1('GET', '/api/v1/niches', $this->key)->assertOk();
});

it('starts counting again in the next minute', function () {
    spendRateLimit($this->key, 60);
    assertApiError(v1('GET', '/api/v1/niches', $this->key), ErrorCode::RateLimited, withRetryAfter: true);

    $this->travelTo('2026-10-05 12:01:00');

    v1('GET', '/api/v1/niches', $this->key)->assertOk();
});

it('shares one limit between v0 and v1, and v0 keeps its body', function () {
    foreach (range(1, 30) as $_) {
        v0Call($this->key)->assertOk();
        v1('GET', '/api/v1/niches', $this->key)->assertOk();
    }

    $response = v0Call($this->key);

    $response->assertStatus(429)->assertHeader('Retry-After', '45');
    expect($response->getContent())->toBe('{"error":"Invalid request"}');
    assertApiError(v1('GET', '/api/v1/ads', $this->key), ErrorCode::RateLimited, withRetryAfter: true);
});

it('limits each key on its own', function () {
    spendRateLimit($this->key, 60);

    v1('GET', '/api/v1/niches', v1Key())->assertOk();
});

it('counts requests that are then refused for their scope or parameters', function () {
    $noScope = v1Key([ApiScope::KeysManage]);
    foreach (range(1, 60) as $_) {
        assertApiError(v1('GET', '/api/v1/niches', $noScope), ErrorCode::InsufficientScope);
    }
    foreach (range(1, 60) as $_) {
        assertApiError(v1('GET', '/api/v1/niches?x=1', $this->key), ErrorCode::InvalidParameters, withDetails: true);
    }

    assertApiError(v1('GET', '/api/v1/niches', $noScope), ErrorCode::RateLimited, withRetryAfter: true);
    assertApiError(v1('GET', '/api/v1/niches', $this->key), ErrorCode::RateLimited, withRetryAfter: true);
});

it('does not count requests without a valid key', function () {
    foreach (range(1, 61) as $_) {
        assertApiError(v1('GET', '/api/v1/niches', 'ghk_'.str_repeat('a', 48)), ErrorCode::Unauthenticated);
    }

    expect(Redis::connection()->keys('*'))->toBe([]);
});

it('stops a revoked key before it is counted', function () {
    spendRateLimit($this->key, 60);
    app(ApiKeys::class)->revoke(PersonalAccessToken::findToken($this->key));

    assertApiError(v1('GET', '/api/v1/niches', $this->key), ErrorCode::Unauthenticated);
});

// The fixed-window trade-off, pinned so it is a decision and not a surprise: up to 120 requests can
// land within a second or two around the start of a minute.
it('allows a full minute of requests on each side of a minute boundary', function () {
    $this->travelTo('2026-10-05 12:00:59');
    spendRateLimit($this->key, 59);
    v1('GET', '/api/v1/niches', $this->key)->assertOk();

    $this->travelTo('2026-10-05 12:01:00');
    spendRateLimit($this->key, 59);
    v1('GET', '/api/v1/niches', $this->key)->assertOk();
});

it('lets each counter expire on its own', function () {
    spendRateLimit($this->key, 1);

    $id = PersonalAccessToken::findToken($this->key)->getKey();
    $ttl = Redis::connection()->ttl("rate-limit:{$id}:".now()->startOfMinute()->timestamp);

    expect($ttl)->toBeGreaterThan(60)->toBeLessThanOrEqual(120);
});

it('refuses requests instead of letting them through unlimited when Redis is down', function () {
    Exceptions::fake();
    config(['database.redis.default.port' => 1]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstances();

    $response = v1('GET', '/api/v1/niches', $this->key);

    assertApiError($response, ErrorCode::Unavailable, withRetryAfter: true);
    $response->assertHeader('Retry-After', '5');
    expect(v0Call($this->key)->assertStatus(400)->getContent())->toBe('{"error":"Invalid request"}');
    Exceptions::assertReported(StreamInitException::class);
});

it('fails fast, not after a minute, when Redis accepts connections but never answers', function () {
    Exceptions::fake();
    // The operating system accepts connections to this socket; nothing ever reads or replies.
    $silent = stream_socket_server('tcp://127.0.0.1:0');
    config([
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => (int) substr(strrchr(stream_socket_get_name($silent, false), ':'), 1),
    ]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstances();

    $started = microtime(true);
    $response = v1('GET', '/api/v1/niches', $this->key);

    assertApiError($response, ErrorCode::Unavailable, withRetryAfter: true);
    expect(microtime(true) - $started)->toBeLessThan(2);
    Exceptions::assertReported(TimeoutException::class);
    fclose($silent);
});

it('counts HEAD requests and paths with a trailing slash', function () {
    spendRateLimit($this->key, 58);
    $auth = ['Authorization' => "Bearer {$this->key}", 'Accept' => 'application/json'];

    app('auth')->forgetGuards();
    $this->call('HEAD', '/api/v1/niches', server: ['HTTP_AUTHORIZATION' => "Bearer {$this->key}"])->assertOk();
    app('auth')->forgetGuards();
    $this->get('/api/v1/niches/', $auth)->assertOk();

    assertApiError(v1('GET', '/api/v1/niches', $this->key), ErrorCode::RateLimited, withRetryAfter: true);
});

it('issues keys whose ids do not collide with a previous test run', function () {
    // Guard for the flush in TestCase: a fresh key starts at 0 requests.
    [$key] = app(ApiKeys::class)->issue(LocalUser::get(), 'fresh', [ApiScope::SearchRead]);

    expect(app(KeyRateLimiter::class)->hit($key->getKey(), now())->remaining)->toBe(59);
});
