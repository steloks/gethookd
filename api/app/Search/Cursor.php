<?php

namespace App\Search;

use JsonException;

/**
 * Where a search walk is: the search itself (sort, normalised filters, limit), the snapshot (the
 * highest ad id when page 1 was read; later pages never go past it, so new ads cannot shift
 * pages), and the sort key of the last ad returned (keyset, so deactivations cannot shift pages).
 *
 * Opaque to callers: base64url JSON plus an HMAC, so a cursor that was not issued by this API,
 * or was changed, is rejected instead of running an arbitrary query.
 */
final readonly class Cursor
{
    private const VERSION = 1;

    /** A worst-case search (200 escaped characters of q, all 12 niches) encodes to about 2,000. */
    public const MAX_LENGTH = 4096;

    /**
     * @param  array<string, string|list<string>>  $filters  as SearchParams::appliedFilters()
     * @param  array{0: string|int|null, 1: int}  $after  the last ad's sort value (start date, or impression rank or null) and id
     */
    public function __construct(
        public SearchSort $sort,
        public array $filters,
        public int $limit,
        public int $snapshot,
        public array $after,
    ) {}

    public function encode(): string
    {
        return self::seal([
            'v' => self::VERSION,
            's' => $this->sort->value,
            'f' => $this->filters,
            'l' => $this->limit,
            'x' => $this->snapshot,
            'k' => $this->after,
        ]);
    }

    /**
     * The cursor, or null if it was not issued by this API (or by this version of it), or was changed.
     */
    public static function decode(string $raw): ?self
    {
        if (strlen($raw) > self::MAX_LENGTH || ! preg_match('/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/', $raw, $m)) {
            return null;
        }

        if (! hash_equals(self::signature($m[1]), $m[2])) {
            return null;
        }

        try {
            $data = json_decode(self::base64UrlDecode($m[1]), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? self::fromPayload($data) : null;
    }

    /**
     * The search as query parameters, for a request that sends the cursor alone.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return [
            ...array_map(fn (string|array $value) => is_array($value) ? implode(',', $value) : $value, $this->filters),
            'sort' => $this->sort->value,
            'limit' => (string) $this->limit,
        ];
    }

    /**
     * Encodes and signs a payload. Public so tests can build signed cursors with bad contents.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function seal(array $payload): string
    {
        $body = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $body.'.'.self::signature($body);
    }

    /**
     * Signed data is still checked field by field, so a cursor from an older version of this
     * code can never reach the query in a shape it doesn't expect.
     *
     * @param  array<mixed>  $data
     */
    private static function fromPayload(array $data): ?self
    {
        if (array_keys($data) !== ['v', 's', 'f', 'l', 'x', 'k'] || $data['v'] !== self::VERSION) {
            return null;
        }

        $sort = is_string($data['s']) ? SearchSort::tryFrom($data['s']) : null;
        $valid = $sort !== null
            && is_array($data['f']) && self::validFilters($data['f'])
            && is_int($data['l']) && $data['l'] >= 1 && $data['l'] <= SearchParams::MAX_LIMIT
            && is_int($data['x']) && $data['x'] >= 0
            && is_array($data['k']) && array_is_list($data['k']) && count($data['k']) === 2
            && is_int($data['k'][1]) && $data['k'][1] >= 1
            && match ($sort) {
                SearchSort::Newest => is_string($data['k'][0]) && self::isDate($data['k'][0]),
                SearchSort::Impressions => $data['k'][0] === null || (is_int($data['k'][0]) && $data['k'][0] >= 1),
            };

        return $valid ? new self($sort, $data['f'], $data['l'], $data['x'], $data['k']) : null;
    }

    /**
     * @param  array<mixed>  $filters
     */
    private static function validFilters(array $filters): bool
    {
        foreach ($filters as $name => $value) {
            $known = in_array($name, ['q', 'niche', 'format', 'status', 'started_after'], true);
            $shape = $name === 'niche'
                ? is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, 'is_string') === $value
                : is_string($value);
            if (! $known || ! $shape) {
                return false;
            }
        }

        return true;
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function signature(string $body): string
    {
        return self::base64UrlEncode(substr(hash_hmac('sha256', 'search-cursor:'.$body, (string) config('app.key'), true), 0, 16));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }
}
