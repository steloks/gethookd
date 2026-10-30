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

it('rejects query parameters instead of ignoring them', function () {
    $response = v1('GET', '/api/v1/niches?niche=skincare&limit=5', v1Key());

    assertApiError($response, ErrorCode::InvalidParameters, withDetails: true);
    expect(array_column($response->json('error.details'), 'param'))->toBe(['niche', 'limit'])
        ->and($response->json('error.details.0'))->toBe([
            'param' => 'niche',
            'issue' => 'unknown_parameter',
            'message' => '"niche" is not a parameter of /api/v1/niches, which takes none.',
            'allowed' => [],
        ])
        ->and($response->json('error.next_step'))->toBe('Send instead: GET /api/v1/niches');
});
