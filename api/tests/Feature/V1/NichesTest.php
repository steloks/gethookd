<?php

use App\Enums\ApiScope;
use App\Support\Errors\ErrorCode;
use App\Support\NicheCatalog;

it('lists the 12 niches with slug, display name and keywords, in catalog order', function () {
    $response = v1('GET', '/api/v1/niches', v1Key())->assertOk();

    expect($response->json('data'))->toHaveCount(12)
        ->and(array_column($response->json('data'), 'slug'))->toBe(NicheCatalog::slugs())
        ->and($response->json('data.4'))->toBe([
            'slug' => 'pet-supplies',
            'name' => 'Pet Supplies',
            'keywords' => ['collar', 'kibble'],
        ]);
});

it('requires a key with search:read', function () {
    assertApiError(v1('GET', '/api/v1/niches'), ErrorCode::Unauthenticated);
    assertApiError(v1('GET', '/api/v1/niches', v1Key([ApiScope::KeysManage])), ErrorCode::InsufficientScope);
});
