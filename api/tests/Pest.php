<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Support\Errors\ErrorCode;
use App\Support\LocalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
 * A v1 request with the key as a Bearer header, as a real client sends it.
 */
function v1(string $method, string $uri, ?string $key = null): TestResponse
{
    // The test client reuses one app; a real request starts with fresh guards.
    app('auth')->forgetGuards();
    $headers = $key === null ? [] : ['Authorization' => "Bearer {$key}"];

    return test()->json($method, $uri, [], $headers);
}

/**
 * The one error shape: exactly these fields (details and retry_after only where the error uses them), with the code's registry values.
 */
function assertApiError(TestResponse $response, ErrorCode $code, bool $withDetails = false, bool $withRetryAfter = false): void
{
    $response->assertStatus($code->status());
    $fields = ['code', 'message', 'next_step', 'retryable', ...($withDetails ? ['details'] : []), ...($withRetryAfter ? ['retry_after'] : [])];
    expect($response->json())->toHaveKey('error')->toHaveCount(1)
        ->and(array_keys($response->json('error')))->toBe($fields)
        ->and($response->json('error.code'))->toBe($code->value)
        ->and($response->json('error.retryable'))->toBe($code->retryable())
        ->and($response->json('error.message'))->toBeString()->not->toBeEmpty()
        ->and($response->json('error.next_step'))->toBeString()->not->toBeEmpty();
}
