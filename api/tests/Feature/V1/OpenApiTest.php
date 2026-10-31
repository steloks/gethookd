<?php

use App\Auth\KeyManagement;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\Niche;
use App\Models\PersonalAccessToken;
use App\Search\Cursor;
use App\Search\SearchParams;
use App\Search\SearchRequestParser;
use App\Search\SearchSort;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\ParameterIssue;
use App\Support\NicheCatalog;
use Carbon\CarbonImmutable;
use cebe\openapi\Reader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\OpenApi;

/*
 * openapi/v1.yaml is kept true in two ways:
 *  - every response a test gets through the v1() helper is validated against it (tests/OpenApi.php);
 *  - this file checks the parts responses alone can't: that it documents every route, parameter,
 *    allowed value and error code the code has, and nothing else, and that each documented
 *    status really happens.
 */

/**
 * @return array<string, mixed>
 */
function spec(string $pointer = ''): array
{
    $node = OpenApi::document();
    foreach (array_filter(explode('/', $pointer)) as $key) {
        $node = $node[str_replace('~1', '/', $key)];
    }

    return $node;
}

/**
 * @return array<string, array<string, mixed>> the search parameters by name
 */
function searchParameters(): array
{
    $query = array_filter(spec('paths/~1api~1v1~1ads/get')['parameters'], fn (array $param) => ($param['in'] ?? null) === 'query');

    return array_column($query, null, 'name');
}

it('is a valid OpenAPI 3 document', function () {
    $document = Reader::readFromYamlFile(realpath(OpenApi::PATH));

    expect($document->validate())->toBeTrue(implode("\n", $document->getErrors()));
});

it('documents exactly the v1 routes', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn (LaravelRoute $route) => array_map(
            fn (string $method) => strtolower($method).' /'.$route->uri(),
            array_diff($route->methods(), ['HEAD']),
        ))
        ->sort()->values()->all();

    $documented = collect(spec('paths'))
        ->flatMap(fn (array $operations, string $path) => array_map(fn (string $method) => "{$method} {$path}", array_keys($operations)))
        ->sort()->values()->all();

    expect($documented)->toBe($routes);
});

it('documents exactly the search parameters, with the values the API accepts', function () {
    $params = searchParameters();

    expect(array_keys($params))->toBe(SearchRequestParser::PARAMS)
        ->and($params['format']['schema']['enum'])->toBe(AdFormat::values())
        ->and($params['status']['schema']['enum'])->toBe(AdStatus::values())
        ->and($params['sort']['schema']['enum'])->toBe(SearchSort::values())
        ->and($params['sort']['schema']['default'])->toBe(SearchSort::Newest->value)
        ->and($params['limit']['schema'])->toMatchArray(['minimum' => 1, 'maximum' => SearchParams::MAX_LIMIT, 'default' => SearchParams::DEFAULT_LIMIT])
        ->and($params['q']['schema']['minLength'])->toBe(1)
        // The API counts q after trimming it, which a schema can't say; a maxLength would reject
        // padded values the API accepts.
        ->and($params['q']['schema'])->not->toHaveKey('maxLength')
        ->and(preg_replace('/\s+/', ' ', $params['q']['description']))->toContain('after trimming, q must have 1 to '.SearchRequestParser::MAX_Q_LENGTH.' characters')
        ->and($params['cursor']['schema']['maxLength'])->toBe(Cursor::MAX_LENGTH)
        ->and($params['started_after']['schema']['format'])->toBe('date');
});

it('lists every niche slug in the niche parameter', function () {
    $description = preg_replace('/\s+/', ' ', searchParameters()['niche']['description']);

    expect($description)->toContain('The slugs are '.implode(', ', array_map(fn (string $slug) => "`{$slug}`", NicheCatalog::slugs())).';');
});

it('gives the same warning about impressions as the API does', function () {
    $description = preg_replace('/\s+/', ' ', searchParameters()['sort']['description']);

    expect($description)->toContain(SearchSort::Impressions->note());
});

it('documents the same allowed values in responses', function () {
    $ad = spec('components/schemas/Ad/properties');

    expect($ad['niche']['enum'])->toBe(NicheCatalog::slugs())
        ->and(spec('components/schemas/Niche/properties/slug')['enum'])->toBe(NicheCatalog::slugs())
        ->and($ad['format']['enum'])->toBe(AdFormat::values())
        ->and($ad['status']['enum'])->toBe(AdStatus::values())
        ->and(spec('components/schemas/SearchPage/properties/sort')['enum'])->toBe(SearchSort::values())
        ->and(spec('components/schemas/SearchPage/properties/applied_filters/properties/niche/items')['enum'])->toBe(NicheCatalog::slugs());
});

it('documents exactly the filters the API echoes in applied_filters', function () {
    $params = (new SearchRequestParser)->parse('q=a&niche=skincare&format=video&status=active&started_after=2026-01-01', CarbonImmutable::parse('2026-10-31'));

    expect(array_keys(spec('components/schemas/SearchPage/properties/applied_filters/properties')))
        ->toBe(array_keys($params->appliedFilters()));
});

it('documents every parameter issue the API reports, and how many', function () {
    expect(spec('components/schemas/ParameterProblem/properties/issue')['enum'])
        ->toBe(array_map(fn (ParameterIssue $issue) => $issue->value, ParameterIssue::cases()))
        ->and(spec('components/schemas/Error/properties/error/properties/details')['maxItems'])->toBe(ApiError::MAX_DETAILS);
});

/**
 * Every error code a response schema allows (each variant of a oneOf pins its own).
 *
 * @param  array<mixed>  $schema
 * @return list<string>
 */
function pinnedCodes(array $schema): array
{
    $codes = $schema['properties']['error']['properties']['code']['enum'] ?? [];
    foreach ([...($schema['allOf'] ?? []), ...($schema['oneOf'] ?? [])] as $part) {
        $codes = [...$codes, ...pinnedCodes($part)];
    }

    return $codes;
}

it('pins each documented error response to codes for its status', function () {
    foreach (spec('paths') as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                if ($status === 'default' || $status < 400) {
                    continue;
                }
                $name = basename($response['$ref']);
                $codes = pinnedCodes(spec("components/responses/{$name}/content/application~1json/schema"));

                expect($codes)->not->toBeEmpty("{$method} {$path} {$status}");
                foreach ($codes as $code) {
                    expect(ErrorCode::from($code)->status())->toBe($status, "{$method} {$path} {$status} {$code}");
                }
            }
        }
    }
});

it('documents the Idempotency-Key header and the Billed header on exactly the billable operations', function () {
    $billable = [];
    foreach (spec('paths') as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $refs = array_column($operation['parameters'] ?? [], '$ref');
            $success = $operation['responses']['200'] ?? $operation['responses']['201'];
            if (in_array('#/components/parameters/IdempotencyKey', $refs, true)) {
                expect($success['headers']['Billed']['$ref'] ?? null)->toBe('#/components/headers/Billed');
                $billable[] = "{$method} {$path}";
            } else {
                expect($success)->not->toHaveKey('headers');
            }
        }
    }

    expect($billable)->toBe(['get /api/v1/ads', 'get /api/v1/ads/{id}'])
        ->and(spec('components/parameters/IdempotencyKey/schema')['maxLength'])->toBe(255);
});

it('documents the key fields, scopes and limits the API uses', function () {
    expect(spec('components/schemas/ApiKey/properties/scopes/items')['enum'])->toBe(ApiScope::values())
        ->and(spec('components/schemas/NewKey/properties/scopes/items')['enum'])->toBe(ApiScope::values())
        ->and(array_keys(spec('components/schemas/NewKey/properties')))->toBe(KeyManagement::CREATE_FIELDS)
        // The API trims the name first, which a schema can't say, so the limit is in the description.
        ->and(spec('components/schemas/NewKey/properties/name'))->not->toHaveKey('maxLength')
        ->and(preg_replace('/\s+/', ' ', spec('components/schemas/NewKey/properties/name')['description']))->toContain('after trimming, name must have 1 to '.KeyManagement::MAX_NAME_LENGTH.' characters');
});

it('documents every error code in the registry', function () {
    expect(spec('components/schemas/Error/properties/error/properties/code')['enum'])
        ->toBe(array_map(fn (ErrorCode $code) => $code->value, ErrorCode::cases()));
});

/**
 * One request per documented (operation, status). The keys must match the document exactly, so
 * documenting a status without showing that it happens fails. v1() validates each response.
 *
 * @return array<string, Closure(): TestResponse>
 */
function documentedResponseRequests(): array
{
    $adPath = fn () => '/api/v1/ads/'.Ad::factory()->create()->id;
    $readOnly = fn () => v1Key([ApiScope::KeysManage]);
    // Each limited key is spent inside one clock minute, so the limit can't reset before the request.
    $insideOneMinute = fn () => test()->travelTo(CarbonImmutable::parse('2026-10-05 12:00:15', 'UTC'));
    $limited = function () use ($insideOneMinute) {
        $insideOneMinute();
        $key = v1Key();
        spendRateLimit($key);

        return $key;
    };
    $failingReads = function (string $model) {
        Exceptions::fake();
        $model::retrieved(fn () => throw new RuntimeException('boom'));
    };

    $manager = fn () => v1Key([ApiScope::SearchRead, ApiScope::KeysManage]);
    $searchOnly = fn () => v1Key([ApiScope::SearchRead]);
    $limitedManager = function () use ($insideOneMinute) {
        $insideOneMinute();
        $key = v1Key([ApiScope::KeysManage]);
        spendRateLimit($key);

        return $key;
    };
    $ownKeyPath = fn () => '/api/v1/keys/'.PersonalAccessToken::findToken(v1Key())->getKey();
    $failingKeyReads = function () use ($manager) {
        $key = $manager();
        Exceptions::fake();
        PersonalAccessToken::retrieved(fn () => throw new RuntimeException('boom'));

        return $key;
    };

    return [
        'get /api/v1/keys 200' => fn () => v1('GET', '/api/v1/keys', $manager()),
        'get /api/v1/keys 401' => fn () => v1('GET', '/api/v1/keys'),
        'get /api/v1/keys 403' => fn () => v1('GET', '/api/v1/keys', $searchOnly()),
        'get /api/v1/keys 422' => fn () => v1('GET', '/api/v1/keys?status=active', $manager()),
        'get /api/v1/keys 429' => fn () => v1('GET', '/api/v1/keys', $limitedManager()),
        'get /api/v1/keys 500' => fn () => v1('GET', '/api/v1/keys', $failingKeyReads()),
        'post /api/v1/keys 201' => fn () => v1('POST', '/api/v1/keys', $manager(), body: ['name' => 'laptop', 'scopes' => ['search:read']]),
        'post /api/v1/keys 400' => fn () => v1('POST', '/api/v1/keys', $manager(), body: ['laptop']),
        'post /api/v1/keys 401' => fn () => v1('POST', '/api/v1/keys', body: ['name' => 'laptop']),
        'post /api/v1/keys 403' => fn () => v1('POST', '/api/v1/keys', $searchOnly(), body: ['name' => 'laptop']),
        'post /api/v1/keys 422' => fn () => v1('POST', '/api/v1/keys', $manager(), body: ['name' => 'laptop', 'scope' => ['search:read']]),
        'post /api/v1/keys 429' => fn () => v1('POST', '/api/v1/keys', $limitedManager(), body: ['name' => 'laptop']),
        'post /api/v1/keys 500' => fn () => v1('POST', '/api/v1/keys', $failingKeyReads(), body: ['name' => 'laptop']),
        'delete /api/v1/keys/{id} 200' => function () use ($manager, $ownKeyPath) {
            $key = $manager();

            return v1('DELETE', $ownKeyPath(), $key);
        },
        'delete /api/v1/keys/{id} 401' => fn () => v1('DELETE', $ownKeyPath()),
        'delete /api/v1/keys/{id} 403' => fn () => v1('DELETE', $ownKeyPath(), $searchOnly()),
        'delete /api/v1/keys/{id} 404' => fn () => v1('DELETE', '/api/v1/keys/999999', $manager()),
        'delete /api/v1/keys/{id} 422' => function () use ($manager, $ownKeyPath) {
            $key = $manager();

            return v1('DELETE', $ownKeyPath().'?force=1', $key);
        },
        'delete /api/v1/keys/{id} 429' => function () use ($limitedManager, $ownKeyPath) {
            $key = $limitedManager();

            return v1('DELETE', $ownKeyPath(), $key);
        },
        'delete /api/v1/keys/{id} 500' => function () use ($failingKeyReads, $ownKeyPath) {
            $path = $ownKeyPath();

            return v1('DELETE', $path, $failingKeyReads());
        },
        'get /api/v1/usage 200' => fn () => v1('GET', '/api/v1/usage', $searchOnly()),
        'get /api/v1/usage 401' => fn () => v1('GET', '/api/v1/usage'),
        'get /api/v1/usage 403' => function () {
            // A key with neither scope can't be issued through the API; give a stored one no abilities.
            $key = v1Key();
            PersonalAccessToken::findToken($key)->forceFill(['abilities' => []])->save();

            return v1('GET', '/api/v1/usage', $key);
        },
        'get /api/v1/usage 422' => fn () => v1('GET', '/api/v1/usage?month=10', $searchOnly()),
        'get /api/v1/usage 429' => function () use ($insideOneMinute) {
            $insideOneMinute();
            $key = v1Key();
            spendRateLimit($key);

            return v1('GET', '/api/v1/usage', $key);
        },
        'get /api/v1/usage 500' => function () use ($searchOnly) {
            $key = $searchOnly();
            Exceptions::fake();
            Schema::drop('billable_calls');

            return v1('GET', '/api/v1/usage', $key);
        },
        'get /api/v1/ads 200' => fn () => v1('GET', '/api/v1/ads?niche=skincare&sort=impressions&limit=1', v1Key()),
        'get /api/v1/ads 401' => fn () => v1('GET', '/api/v1/ads'),
        'get /api/v1/ads 403' => fn () => v1('GET', '/api/v1/ads', $readOnly()),
        'get /api/v1/ads 422' => fn () => v1('GET', '/api/v1/ads?nich=Skincare', v1Key()),
        'get /api/v1/ads 429' => fn () => v1('GET', '/api/v1/ads', $limited()),
        'get /api/v1/ads 500' => function () use ($failingReads) {
            $key = v1Key();
            $failingReads(Ad::class);

            return v1('GET', '/api/v1/ads', $key);
        },
        'get /api/v1/ads/{id} 200' => fn () => v1('GET', $adPath(), v1Key()),
        'get /api/v1/ads/{id} 401' => fn () => v1('GET', $adPath()),
        'get /api/v1/ads/{id} 403' => fn () => v1('GET', $adPath(), $readOnly()),
        'get /api/v1/ads/{id} 404' => fn () => v1('GET', '/api/v1/ads/999999', v1Key()),
        'get /api/v1/ads/{id} 422' => fn () => v1('GET', $adPath().'?fields=headline', v1Key()),
        'get /api/v1/ads/{id} 429' => fn () => v1('GET', $adPath(), $limited()),
        'get /api/v1/ads/{id} 500' => function () use ($adPath, $failingReads) {
            [$path, $key] = [$adPath(), v1Key()];
            $failingReads(Ad::class);

            return v1('GET', $path, $key);
        },
        'get /api/v1/niches 200' => fn () => v1('GET', '/api/v1/niches', v1Key()),
        'get /api/v1/niches 401' => fn () => v1('GET', '/api/v1/niches'),
        'get /api/v1/niches 403' => fn () => v1('GET', '/api/v1/niches', $readOnly()),
        'get /api/v1/niches 422' => fn () => v1('GET', '/api/v1/niches?limit=5', v1Key()),
        'get /api/v1/niches 429' => fn () => v1('GET', '/api/v1/niches', $limited()),
        'get /api/v1/niches 500' => function () use ($failingReads) {
            $key = v1Key();
            $failingReads(Niche::class);

            return v1('GET', '/api/v1/niches', $key);
        },
    ];
}

it('has a request for every documented response', function () {
    $documented = collect(spec('paths'))
        ->flatMap(fn (array $operations, string $path) => collect($operations)->flatMap(
            fn (array $operation, string $method) => array_map(
                fn (string|int $status) => "{$method} {$path} {$status}",
                array_values(array_diff(array_keys($operation['responses']), ['default'])),
            ),
        ))
        ->sort()->values()->all();

    expect(collect(array_keys(documentedResponseRequests()))->sort()->values()->all())->toBe($documented);
});

it('really returns each documented response, in the documented shape', function (string $name) {
    Ad::factory()->inNiche('skincare')->ranked(1)->count(2)->create();

    $response = documentedResponseRequests()[$name]();

    $response->assertStatus((int) substr($name, -3));
})->with(fn () => array_keys(documentedResponseRequests()));

/**
 * Responses the document must reject, each next to one it accepts that differs only in the
 * broken part, so a rejection is for that reason and not another.
 *
 * @return array<string, array{0: string, 1: string, 2: Closure(bool): Response}>
 */
function brokenResponses(): array
{
    $error = fn (string $code, array $extra = []) => ['error' => ['code' => $code, 'message' => 'm', 'next_step' => 'n', 'retryable' => false, ...$extra]];
    $details = ['details' => [['param' => 'x', 'issue' => 'unknown_parameter', 'message' => 'm']]];
    $page = fn (string $niche) => ['data' => [], 'applied_filters' => ['niche' => [$niche]], 'sort' => 'newest', 'limit' => 20, 'next_cursor' => null];

    return [
        '401 without WWW-Authenticate' => ['GET', '/api/v1/niches', fn (bool $broken) => new JsonResponse($error('unauthenticated'), 401, $broken ? [] : ['WWW-Authenticate' => 'Bearer'])],
        '401 with another code' => ['GET', '/api/v1/niches', fn (bool $broken) => new JsonResponse($error($broken ? 'not_found' : 'unauthenticated'), 401, ['WWW-Authenticate' => 'Bearer'])],
        '404 with another code' => ['GET', '/api/v1/ads/1', fn (bool $broken) => new JsonResponse($error($broken ? 'internal_error' : 'not_found'), 404)],
        '422 without details' => ['GET', '/api/v1/ads', fn (bool $broken) => new JsonResponse($error('invalid_parameters', $broken ? [] : $details), 422)],
        '422 with no problems' => ['GET', '/api/v1/ads', fn (bool $broken) => new JsonResponse($error('invalid_parameters', $broken ? ['details' => []] : $details), 422)],
        '429 without Retry-After' => ['GET', '/api/v1/niches', fn (bool $broken) => new JsonResponse($error('rate_limited', ['retry_after' => 5]), 429, $broken ? [] : ['Retry-After' => '5'])],
        '429 without retry_after' => ['GET', '/api/v1/niches', fn (bool $broken) => new JsonResponse($error('rate_limited', $broken ? [] : ['retry_after' => 5]), 429, ['Retry-After' => '5'])],
        'applied niche not a slug' => ['GET', '/api/v1/ads', fn (bool $broken) => new JsonResponse($page($broken ? 'Skincare' : 'skincare'), 200, ['Billed' => 'true'])],
        'billable 200 without Billed' => ['GET', '/api/v1/ads', fn (bool $broken) => new JsonResponse($page('skincare'), 200, $broken ? [] : ['Billed' => 'false'])],
        'quota_exceeded without resets_at' => ['GET', '/api/v1/ads', fn (bool $broken) => new JsonResponse($error('quota_exceeded', $broken ? [] : ['resets_at' => '2026-11-01T00:00:00Z']), 429, ['Retry-After' => '100'])],
        'quota_exceeded said to be retryable' => ['GET', '/api/v1/ads', fn (bool $broken) => new JsonResponse(['error' => ['code' => 'quota_exceeded', 'message' => 'm', 'next_step' => 'n', 'retryable' => $broken, 'resets_at' => '2026-11-01T00:00:00Z']], 429, ['Retry-After' => '100'])],
        'idempotency_key_reused on niches' => ['GET', '/api/v1/niches', fn (bool $broken) => new JsonResponse($error($broken ? 'idempotency_key_reused' : 'invalid_parameters', $details), 422)],
        'success on an undocumented v1 path' => ['GET', '/api/v1/other', fn (bool $broken) => new JsonResponse($error('not_found'), $broken ? 200 : 404)],
        'error on an undocumented v1 path, with an extra field' => ['GET', '/api/v1/other', fn (bool $broken) => new JsonResponse($error('not_found', $broken ? ['trace' => 'x'] : []), 404)],
        'error on an undocumented method, with a bad retry_after' => ['POST', '/api/v1/ads', fn (bool $broken) => new JsonResponse($error('rate_limited', ['retry_after' => $broken ? 0 : 5]), 429)],
        'error on an undocumented v1 path, not JSON' => ['GET', '/api/v1/other', fn (bool $broken) => $broken ? new Response(json_encode($error('not_found')), 404, ['Content-Type' => 'text/html']) : new JsonResponse($error('not_found'), 404)],
    ];
}

it('rejects responses that break the document', function (string $method, string $uri, Closure $response) {
    $headers = ['Authorization' => 'Bearer ghk_test'];
    OpenApi::assertMatches(TestResponse::fromBaseResponse($response(false)), $method, $uri, $headers);

    expect(fn () => OpenApi::assertMatches(TestResponse::fromBaseResponse($response(true)), $method, $uri, $headers))
        ->toThrow(AssertionFailedError::class);
})->with(brokenResponses());
