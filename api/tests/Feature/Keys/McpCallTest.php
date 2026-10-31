<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\PersonalAccessToken;
use App\Support\Errors\ErrorCode;
use Carbon\CarbonImmutable;

/*
 * last_mcp_call_at: when a key's requests last came through the MCP server, so the developer
 * page can confirm "your AI tool is connected".
 */

function mcpCallOf(string $key): ?string
{
    return PersonalAccessToken::findToken($key)->last_mcp_call_at?->utc()->toIso8601ZuluString();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'));
    $this->key = v1Key();
});

it('records a request that comes through the MCP server, free ones included', function () {
    v1('GET', '/api/v1/niches', $this->key, ['X-Client' => 'mcp'])->assertOk();

    expect(mcpCallOf($this->key))->toBe('2026-11-01T10:00:00Z');
});

it('records a refused MCP request too: the call arrived', function () {
    assertApiError(v1('GET', '/api/v1/ads?nich=x', $this->key, ['X-Client' => 'mcp']), ErrorCode::InvalidParameters, withDetails: true);

    expect(mcpCallOf($this->key))->toBe('2026-11-01T10:00:00Z');
});

it('ignores requests that don\'t say they come from MCP', function (array $headers) {
    v1('GET', '/api/v1/niches', $this->key, $headers)->assertOk();

    expect(mcpCallOf($this->key))->toBeNull();
})->with([
    'no header' => [[]],
    'another client' => [['X-Client' => 'rest']],
    'different case' => [['X-Client' => 'MCP']],
]);

it('records nothing for a request without a valid key', function () {
    $other = v1Key();
    app(ApiKeys::class)->revoke(PersonalAccessToken::findToken($other));

    assertApiError(v1('GET', '/api/v1/niches', $other, ['X-Client' => 'mcp']), ErrorCode::Unauthenticated);
    assertApiError(v1('GET', '/api/v1/niches', null, ['X-Client' => 'mcp']), ErrorCode::Unauthenticated);

    expect(PersonalAccessToken::query()->whereNotNull('last_mcp_call_at')->count())->toBe(0);
});

it('writes at most once a minute per key', function () {
    v1('GET', '/api/v1/niches', $this->key, ['X-Client' => 'mcp'])->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:59', 'UTC'));
    v1('GET', '/api/v1/niches', $this->key, ['X-Client' => 'mcp'])->assertOk();

    expect(mcpCallOf($this->key))->toBe('2026-11-01T10:00:00Z');

    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:01:00', 'UTC'));
    v1('GET', '/api/v1/niches', $this->key, ['X-Client' => 'mcp'])->assertOk();

    expect(mcpCallOf($this->key))->toBe('2026-11-01T10:01:00Z');
});

it('shows it in the key list, for the page\'s connect check', function () {
    Ad::factory()->create();
    v1('GET', '/api/v1/ads', $this->key, ['X-Client' => 'mcp'])->assertOk();

    $listed = collect($this->getJson('/developer/keys')->json('data'))->firstWhere('prefix', substr($this->key, 0, 12));

    expect($listed['last_mcp_call_at'])->toBe('2026-11-01T10:00:00Z');
});

it('records a rate-limited MCP request: it arrived', function () {
    spendRateLimit($this->key);

    assertApiError(v1('GET', '/api/v1/niches', $this->key, ['X-Client' => 'mcp']), ErrorCode::RateLimited, withRetryAfter: true);

    expect(mcpCallOf($this->key))->toBe('2026-11-01T10:00:00Z');
});

it('records a key without search:read too (any authenticated MCP call)', function () {
    $manager = v1Key([ApiScope::KeysManage]);

    assertApiError(v1('GET', '/api/v1/niches', $manager, ['X-Client' => 'mcp']), ErrorCode::InsufficientScope);

    expect(mcpCallOf($manager))->toBe('2026-11-01T10:00:00Z');
});
