<?php

use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Artisan;

it('creates a search:read key by default and prints it once', function () {
    $this->artisan('keys:create', ['name' => 'cli key'])
        ->expectsOutputToContain('with scopes: search:read')
        ->expectsOutputToContain('only time the full key is shown')
        ->assertExitCode(0);

    $key = PersonalAccessToken::query()->sole();
    expect($key->name)->toBe('cli key')
        ->and($key->abilities)->toBe(['search:read'])
        ->and($key->prefix)->toStartWith('ghk_');
});

it('prints the plaintext key exactly once, and it authenticates', function () {
    $output = Artisan::call('keys:create', ['name' => 'cli key']) === 0
        ? Artisan::output()
        : '';

    preg_match_all('/ghk_[A-Za-z0-9]{40}[0-9a-f]{8}/', $output, $matches);
    expect($matches[0])->toHaveCount(1);

    $this->get('/api/v0/ads?'.http_build_query(['api_key' => $matches[0][0]]))->assertOk();
});

it('creates a key with every requested scope', function () {
    $this->artisan('keys:create', ['name' => 'admin', '--scope' => ['search:read', 'keys:manage']])
        ->assertExitCode(0);

    expect(PersonalAccessToken::query()->sole()->abilities)->toBe(['search:read', 'keys:manage']);
});

it('rejects an unknown scope, lists the allowed ones, and creates nothing', function () {
    $this->artisan('keys:create', ['name' => 'bad', '--scope' => ['search:write']])
        ->expectsOutputToContain('Allowed: search:read, keys:manage')
        ->assertExitCode(2);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
