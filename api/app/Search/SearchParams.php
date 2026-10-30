<?php

namespace App\Search;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use Carbon\CarbonImmutable;

/**
 * A validated, normalised v1 search request.
 */
final readonly class SearchParams
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /**
     * @param  list<string>  $niches  slugs, in catalog order, without duplicates
     */
    public function __construct(
        public ?string $q = null,
        public array $niches = [],
        public ?AdFormat $format = null,
        public ?AdStatus $status = null,
        public ?CarbonImmutable $startedAfter = null,
        public SearchSort $sort = SearchSort::Newest,
        public int $limit = self::DEFAULT_LIMIT,
        public ?Cursor $cursor = null,
    ) {}

    /**
     * The filters that were applied, as the caller should send them again. Filters not given are left out.
     *
     * @return array<string, string|list<string>>
     */
    public function appliedFilters(): array
    {
        return array_filter([
            'q' => $this->q,
            'niche' => $this->niches === [] ? null : $this->niches,
            'format' => $this->format?->value,
            'status' => $this->status?->value,
            'started_after' => $this->startedAfter?->toDateString(),
        ], fn ($value) => $value !== null);
    }
}
