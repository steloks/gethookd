<?php

namespace App\Search;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\SafeText;
use App\Support\NicheCatalog;
use Carbon\CarbonImmutable;

/**
 * Validates the v1 search query string strictly: unknown parameters, repeated parameters and
 * invalid values are all rejected, together, in one 422 whose details say what is allowed and
 * what the caller probably meant. When every problem has a likely fix, next_step is the
 * corrected request.
 *
 * Reads the raw query string, because PHP's parser silently keeps only the last of a repeated
 * parameter (niche=a&niche=b would search b alone) and rewrites names ("a.b" becomes "a_b").
 */
final class SearchRequestParser
{
    public const PARAMS = ['q', 'niche', 'format', 'status', 'started_after', 'sort', 'limit', 'cursor'];

    public const MAX_Q_LENGTH = 200;

    /** Other names callers use for a parameter (lowercase). */
    private const PARAM_ALIASES = [
        'query' => 'q',
        'keyword' => 'q',
        'keywords' => 'q',
        'search' => 'q',
        'text' => 'q',
        'category' => 'niche',
        'niches' => 'niche',
        'type' => 'format',
        'ad_format' => 'format',
        'state' => 'status',
        'since' => 'started_after',
        'start_date' => 'started_after',
        'started_since' => 'started_after',
        'order' => 'sort',
        'order_by' => 'sort',
        'sort_by' => 'sort',
        'per_page' => 'limit',
        'page_size' => 'limit',
        'size' => 'limit',
        'count' => 'limit',
        'next_cursor' => 'cursor',
        'page_token' => 'cursor',
        'next_page_token' => 'cursor',
    ];

    private const SORT_ALIASES = [
        'latest' => 'newest',
        'recent' => 'newest',
        'date' => 'newest',
        'start_date' => 'newest',
        'most_impressions' => 'impressions',
        'impression_rank' => 'impressions',
        'popular' => 'impressions',
    ];

    /** @var list<array<string, mixed>> */
    private array $problems = [];

    /** Whether every problem so far has a likely fix. */
    private bool $fixable = true;

    /** @var array<string, string> the request as it should be sent, if every problem is fixed */
    private array $corrected = [];

    public function parse(string $queryString, CarbonImmutable $today): SearchParams
    {
        $this->problems = [];
        $this->fixable = true;
        $this->corrected = [];
        $params = [];

        $grouped = $this->groupByParam(QueryString::pairs($queryString));

        // A cursor carries its search: parameters the caller left out are taken from it, and
        // the ones they sent must match it (checked below, after normalising).
        $cursor = null;
        if (isset($grouped['cursor'])) {
            $cursor = $this->cursor($grouped['cursor']);
            unset($grouped['cursor']);
            $grouped += $cursor?->query() ?? [];
        }

        foreach ($grouped as $name => $raw) {
            if (! self::isText($raw)) {
                $this->problem($name, 'invalid_value', "{$name} is not valid UTF-8 text.");
                $this->fixable = false;

                continue;
            }

            $params[$name] = match ($name) {
                'q' => $this->q($raw),
                'niche' => $this->niches($raw),
                'format' => $this->enum('format', $raw, AdFormat::values()),
                'status' => $this->enum('status', $raw, AdStatus::values()),
                'started_after' => $this->startedAfter($raw, $today),
                'sort' => $this->enum('sort', $raw, SearchSort::values(), self::SORT_ALIASES),
                'limit' => $this->limit($raw),
            };
        }

        if ($this->problems !== []) {
            throw $this->error();
        }

        $search = new SearchParams(
            q: $params['q'] ?? null,
            niches: $params['niche'] ?? [],
            format: isset($params['format']) ? AdFormat::from($params['format']) : null,
            status: isset($params['status']) ? AdStatus::from($params['status']) : null,
            startedAfter: $params['started_after'] ?? null,
            sort: isset($params['sort']) ? SearchSort::from($params['sort']) : SearchSort::Newest,
            limit: $params['limit'] ?? SearchParams::DEFAULT_LIMIT,
            cursor: $cursor,
        );

        if ($cursor !== null) {
            $this->checkCursorMatches($cursor, $search);
        }

        return $search;
    }

    private function cursor(string $raw): ?Cursor
    {
        if (trim($raw) === '') {
            return $this->empty('cursor', 'Leave cursor out to get the first page.');
        }

        $cursor = Cursor::decode($raw);
        if ($cursor === null) {
            // Not echoed: a cursor is long and means nothing to the caller.
            $this->problem('cursor', 'invalid_value',
                'cursor is not one this API issued, or it was changed. Send next_cursor exactly as a search response returned it, or leave cursor out to start again from the first page.');
            $this->fixable = false;

            return null;
        }

        $this->accept('cursor', $raw);

        return $cursor;
    }

    /**
     * A cursor continues one search. Different filters or sort would make its position meaningless.
     */
    private function checkCursorMatches(Cursor $cursor, SearchParams $search): void
    {
        $sent = [...$search->appliedFilters(), 'sort' => $search->sort->value];
        $expected = [...$cursor->filters, 'sort' => $cursor->sort->value];
        $differs = array_values(array_filter(
            ['q', 'niche', 'format', 'status', 'started_after', 'sort'],
            fn (string $name) => ($sent[$name] ?? null) !== ($expected[$name] ?? null),
        ));

        if ($differs !== []) {
            $this->problem('cursor', 'cursor_mismatch',
                'cursor continues a search with a different '.implode(', ', $differs).'. '
                .'Send the cursor alone (it remembers the search) or with the same parameters; to change the search, leave cursor out to start from the first page.');
            $this->fixable = false;

            $newSearch = $this->corrected;
            unset($newSearch['cursor']);

            throw $this->error(
                'To continue the earlier search: GET /api/v1/ads?cursor='.rawurlencode($this->corrected['cursor'])
                .'. To run the new search from its first page: GET /api/v1/ads'.self::queryOf($newSearch),
            );
        }
    }

    /**
     * Maps each pair to the parameter it is (or most likely was meant to be), reporting unknown,
     * array-style and repeated names.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, string> parameter => raw value
     */
    private function groupByParam(array $pairs): array
    {
        $values = [];
        $literalCounts = array_count_values(array_column($pairs, 0));

        foreach ($pairs as [$name, $value]) {
            $param = $name;

            if (preg_match('/\A(\w+)\[[^\]]*\]\z/', $name, $match) && in_array($match[1], self::PARAMS, true)) {
                $param = $match[1];
                $this->problem($name, 'array_syntax', "Send {$param} as a plain parameter, not as {$this->show($name)}."
                    .($param === 'niche' ? ' For several niches, separate the slugs with commas.' : ''), didYouMean: $param);
            } elseif (! in_array($name, self::PARAMS, true)) {
                $param = Suggest::closest($name, self::PARAMS, self::PARAM_ALIASES);
                $this->problem($name, 'unknown_parameter', "{$this->show($name)} is not a search parameter.", self::PARAMS, $param);

                if ($param === null) {
                    $this->fixable = false;

                    continue;
                }
            }

            $values[$param][] = $value;
        }

        $grouped = [];
        foreach ($values as $param => $raws) {
            if (count($raws) > 1 && $param === 'niche') {
                $grouped[$param] = implode(',', $raws);
                if (($literalCounts['niche'] ?? 0) > 1) {
                    // Only suggest text that is valid; an encoding problem is reported on its own below.
                    $this->problem(
                        'niche',
                        'repeated_parameter',
                        'niche was sent more than once. Send one niche parameter with the slugs separated by commas.',
                        didYouMean: self::isText($grouped[$param]) ? implode(',', array_unique(self::listItems($grouped[$param]))) : null
                    );
                }
            } elseif (count($raws) > 1) {
                $this->problem($param, 'repeated_parameter', "{$param} was sent more than once. It takes a single value.");
                $this->fixable = false;
            } else {
                $grouped[$param] = $raws[0];
            }
        }

        return $grouped;
    }

    private function q(string $raw): ?string
    {
        $q = trim($raw);

        if ($q === '') {
            return $this->empty('q', 'Leave q out to search without a keyword.');
        }

        if (mb_strlen($q) > self::MAX_Q_LENGTH) {
            $this->problem('q', 'too_long', 'q is longer than '.self::MAX_Q_LENGTH.' characters. Search for a shorter phrase.');
            $this->fixable = false;

            return null;
        }

        return $this->accept('q', $q);
    }

    /**
     * @return list<string>|null slugs in catalog order, without duplicates
     */
    private function niches(string $raw): ?array
    {
        $items = self::listItems($raw);
        if ($items === []) {
            return $this->empty('niche', 'Leave niche out to search every niche.');
        }

        $slugs = NicheCatalog::slugs();
        $unknown = [];
        $fixed = [];
        foreach ($items as $item) {
            $slug = in_array($item, $slugs, true) ? $item : null;
            if ($slug === null) {
                $unknown[] = $this->show($item);
                $slug = $this->suggestNiche($item);
            }
            $fixed[] = $slug;
        }

        if ($unknown !== []) {
            $allFixed = ! in_array(null, $fixed, true);
            $this->problem(
                'niche',
                'invalid_value',
                'Unknown niche '.self::firstFew($unknown).'. niche takes slugs such as "pet-supplies", separated by commas; GET /api/v1/niches lists them.',
                $slugs,
                $allFixed ? implode(',', array_unique($fixed)) : null
            );
            $this->fixable = $this->fixable && $allFixed;

            return null;
        }

        $this->corrected['niche'] = implode(',', array_unique($items));

        return array_values(array_intersect($slugs, $items));
    }

    private function suggestNiche(string $item): ?string
    {
        $lower = mb_strtolower($item);
        foreach (NicheCatalog::NICHES as $slug => $niche) {
            if ($lower === $slug || $lower === mb_strtolower($niche['name'])) {
                return $slug;
            }
        }

        // "Baby & Kids" -> "baby-kids", "pet supplies" -> "pet-supplies"
        $slugged = trim((string) preg_replace('/[^a-z0-9]+/', '-', str_replace('&', '', $lower)), '-');

        return Suggest::closest($slugged, NicheCatalog::slugs());
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, string>  $aliases
     */
    private function enum(string $name, string $raw, array $allowed, array $aliases = []): ?string
    {
        if (in_array($raw, $allowed, true)) {
            return $this->accept($name, $raw);
        }

        if (trim($raw) === '') {
            return $this->empty($name, "Leave {$name} out to not filter by it.");
        }

        $suggestion = Suggest::closest($raw, $allowed, $aliases);
        $this->problem($name, 'invalid_value', "{$this->show($raw)} is not a valid {$name}.", $allowed, $suggestion);
        $this->fixable = $this->fixable && $suggestion !== null;

        return null;
    }

    private function startedAfter(string $raw, CarbonImmutable $today): ?CarbonImmutable
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $this->accept('started_after', $raw);

            return CarbonImmutable::createFromFormat('!Y-m-d', $raw, 'UTC');
        }

        if (trim($raw) === '') {
            return $this->empty('started_after', 'Leave started_after out to not filter by start date.');
        }

        // Unambiguous year-first dates in another layout: 2026/08/01, 2026-8-1, 2026-08-01T00:00:00Z
        $suggestion = preg_match('/\A(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[T ].*)?\z/', trim($raw), $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3])
            : null;

        $this->problem(
            'started_after',
            'invalid_value',
            "{$this->show($raw)} is not a date. started_after takes YYYY-MM-DD and keeps ads that started on or after that day (UTC). Today is {$today->toDateString()}.",
            didYouMean: $suggestion
        );
        $this->fixable = $this->fixable && $suggestion !== null;

        return null;
    }

    private function limit(string $raw): ?int
    {
        if (ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= SearchParams::MAX_LIMIT) {
            $this->accept('limit', (string) (int) $raw);

            return (int) $raw;
        }

        $tooBig = ctype_digit($raw) && (int) $raw > SearchParams::MAX_LIMIT;
        $this->problem(
            'limit',
            $tooBig ? 'out_of_range' : 'invalid_value',
            'limit must be a whole number from 1 to '.SearchParams::MAX_LIMIT.'.',
            didYouMean: $tooBig ? (string) SearchParams::MAX_LIMIT : null
        );
        $this->fixable = $this->fixable && $tooBig;

        return null;
    }

    /**
     * @return list<string> the non-empty, trimmed items of a comma-separated value
     */
    private static function listItems(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $item) => $item !== ''));
    }

    private static function isText(string $raw): bool
    {
        return mb_check_encoding($raw, 'UTF-8') && ! str_contains($raw, "\0");
    }

    /**
     * "a, b, c, d, e and 7 more", so a long list can't make a long message.
     *
     * @param  list<string>  $items
     */
    private static function firstFew(array $items, int $shown = 5): string
    {
        $more = count($items) - $shown;

        return implode(', ', array_slice($items, 0, $shown)).($more > 0 ? " and {$more} more" : '');
    }

    private function accept(string $name, string $value): string
    {
        $this->corrected[$name] = $value;

        return $value;
    }

    /**
     * An empty value is rejected; the fix is to leave the parameter out.
     */
    private function empty(string $name, string $hint): null
    {
        $this->problem($name, 'empty_value', "{$name} is empty. {$hint}");

        return null;
    }

    /**
     * @param  list<string>|null  $allowed
     */
    private function problem(string $param, string $issue, string $message, ?array $allowed = null, ?string $didYouMean = null): void
    {
        $problem = ['param' => $this->show($param, quote: false), 'issue' => $issue, 'message' => $message];
        if ($allowed !== null) {
            $problem['allowed'] = $allowed;
        }
        if ($didYouMean !== null) {
            $problem['did_you_mean'] = $didYouMean;
            if (in_array($param, self::PARAMS, true) && $issue !== 'array_syntax') {
                $this->corrected[$param] = $didYouMean;
            }
        }

        $this->problems[] = $problem;
    }

    private function error(?string $nextStep = null): ApiError
    {
        $names = implode(', ', array_unique(array_column($this->problems, 'param')));
        $count = count($this->problems);

        return new ApiError(
            ErrorCode::InvalidParameters,
            ($count === 1 ? '1 problem' : "{$count} problems")." with the search parameters: {$names}. See details.",
            $nextStep ?? ($this->fixable ? 'Send instead: GET /api/v1/ads'.self::queryOf($this->corrected) : null),
            details: $this->problems,
        );
    }

    /**
     * @param  array<string, string>  $params
     */
    private static function queryOf(array $params): string
    {
        $parts = [];
        foreach (self::PARAMS as $name) {
            if (isset($params[$name])) {
                $parts[] = $name.'='.implode(',', array_map('rawurlencode', explode(',', $params[$name])));
            }
        }

        return $parts === [] ? '' : '?'.implode('&', $parts);
    }

    /**
     * Caller input for a message: valid UTF-8, short, and never an API key.
     */
    private function show(string $input, bool $quote = true): string
    {
        $text = SafeText::of($input);

        return $quote ? "\"{$text}\"" : $text;
    }
}
