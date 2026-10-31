<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\PersonalAccessToken;
use App\RateLimit\KeyRateLimiter;
use App\Support\Errors\ErrorCode;
use App\Support\LocalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\OpenApi;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Real concurrent requests against a running server: the data must be committed, so these tests
// migrate the test database fresh before and after each test instead of using a transaction.
pest()->extend(TestCase::class)->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * @param  list<ApiScope>  $scopes
 */
function v1Key(array $scopes = [ApiScope::SearchRead]): string
{
    return app(ApiKeys::class)->issue(LocalUser::get(), 'v1 test', $scopes)[1];
}

/**
 * A v1 request with the key as a Bearer header, as a real client sends it. The response is checked
 * against openapi/v1.yaml, so every test that calls v1 also tests the OpenAPI document.
 */
/**
 * @param  array<string, string>  $headers  more request headers, e.g. Idempotency-Key
 */
function v1(string $method, string $uri, ?string $key = null, array $headers = []): TestResponse
{
    // The test client reuses one app; a real request starts with fresh guards.
    app('auth')->forgetGuards();
    $headers = ($key === null ? [] : ['Authorization' => "Bearer {$key}"]) + $headers;

    $response = test()->json($method, $uri, [], $headers);
    OpenApi::assertMatches($response, $method, $uri, $headers);

    return $response;
}

/**
 * Uses up $count of a key's requests for the current minute (all of them by default) without going through HTTP.
 */
function spendRateLimit(string $key, int $count = KeyRateLimiter::LIMIT): void
{
    $id = PersonalAccessToken::findToken($key)->getKey();
    foreach (range(1, $count) as $_) {
        app(KeyRateLimiter::class)->hit($id, now());
    }
}

/**
 * The one error shape: exactly these fields (details and retry_after only where the error uses them), with the code's registry values.
 */
function assertApiError(TestResponse $response, ErrorCode $code, bool $withDetails = false, bool $withRetryAfter = false, bool $withResetsAt = false): void
{
    $response->assertStatus($code->status());
    $fields = ['code', 'message', 'next_step', 'retryable', ...($withDetails ? ['details'] : []), ...($withRetryAfter ? ['retry_after'] : []), ...($withResetsAt ? ['resets_at'] : [])];
    expect($response->json())->toHaveKey('error')->toHaveCount(1)
        ->and(array_keys($response->json('error')))->toBe($fields)
        ->and($response->json('error.code'))->toBe($code->value)
        ->and($response->json('error.retryable'))->toBe($code->retryable())
        ->and($response->json('error.message'))->toBeString()->not->toBeEmpty()
        ->and($response->json('error.next_step'))->toBeString()->not->toBeEmpty();
}
