<?php

namespace Tests;

use cebe\openapi\Reader;
use cebe\openapi\spec\Schema;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Yaml\Yaml;

/**
 * The v1 OpenAPI document, for tests. The v1() test helper passes every response through
 * assertMatches(), so the whole suite checks the document against what the API really does.
 */
final class OpenApi
{
    public const PATH = __DIR__.'/../openapi/v1.yaml';

    private static ?ValidatorInterface $validator = null;

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    private static ?Schema $errorSchema = null;

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return self::$document ??= Yaml::parseFile(self::PATH);
    }

    /**
     * The documented path template ("/api/v1/ads/{id}") a request goes to, or null if the
     * document has no such operation.
     */
    public static function operationPath(string $method, string $uri): ?string
    {
        $path = '/'.ltrim((string) parse_url($uri, PHP_URL_PATH), '/');

        foreach (self::document()['paths'] as $template => $operations) {
            $pattern = '#\A'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($template, '#')).'\z#';
            if (preg_match($pattern, $path) && isset($operations[strtolower($method)])) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Checks a response against the documented operation. When the API accepted the request, the
     * document must accept it too, or it describes the parameters more strictly than the API
     * checks them. A v1 request the document has no operation for (an unknown path, another
     * method, a test-only route) must have failed, with a body in the Error schema.
     *
     * @param  array<string, string>  $headers
     * @param  array<mixed>|null  $body  the JSON body the request was sent with
     */
    public static function assertMatches(TestResponse $response, string $method, string $uri, array $headers = [], ?array $body = null): void
    {
        $template = self::operationPath($method, $uri);
        if ($template === null) {
            if (str_starts_with('/'.ltrim((string) parse_url($uri, PHP_URL_PATH), '/'), '/api/v1/')) {
                self::assertUndocumentedIsError($response, $method, $uri);
            }

            return;
        }

        $validator = self::$validator ??= ValidatorBuilder::fromYamlFile(self::PATH)->getValidator();

        try {
            $validator->validate($response->baseResponse, $template, $method);
            if ($response->isSuccessful()) {
                $request = $body === null
                    ? Request::create($uri, $method)
                    : Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
                $request->headers->add($headers);
                $validator->validate($request, $template, $method);
            }
        } catch (ValidationException $e) {
            self::fail($response, $method, $uri, $e->getMessage().($e->getPrevious() ? ' '.$e->getPrevious()->getMessage() : ''));
        }
    }

    private static function assertUndocumentedIsError(TestResponse $response, string $method, string $uri): void
    {
        if ($response->getStatusCode() < 400) {
            self::fail($response, $method, $uri, 'the request succeeded, but the document has no such operation');
        }
        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'application/json')) {
            self::fail($response, $method, $uri, 'an error must be JSON');
        }

        self::$errorSchema ??= Reader::readFromYamlFile(realpath(self::PATH))->components->schemas['Error'];

        try {
            (new SchemaValidator)->validate(json_decode((string) $response->getContent(), true), self::$errorSchema);
        } catch (SchemaMismatch $e) {
            $at = $e->dataBreadCrumb() ? implode('.', $e->dataBreadCrumb()->buildChain()) : '';
            self::fail($response, $method, $uri, "not in the Error schema: {$at} {$e->getMessage()}");
        }
    }

    private static function fail(TestResponse $response, string $method, string $uri, string $problem): never
    {
        throw new AssertionFailedError(
            "{$method} {$uri} (status {$response->getStatusCode()}) does not match openapi/v1.yaml: {$problem}"
            ."\nBody: ".mb_substr((string) $response->getContent(), 0, 1000),
        );
    }
}
