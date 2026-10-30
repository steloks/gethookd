<?php

namespace App\Search;

enum SearchSort: string
{
    /** start_date descending, then id descending. */
    case Newest = 'newest';

    /** impression_rank ascending with nulls last, then id descending. Ranks compare ads within one brand only. */
    case Impressions = 'impressions';

    /**
     * What the order means, where a caller could misread it. Sent with the results, and the
     * same wording goes in the OpenAPI spec and the MCP tool descriptions.
     */
    public function note(): ?string
    {
        return match ($this) {
            self::Newest => null,
            self::Impressions => 'impression_rank compares ads within one brand only (1 = that brand\'s most-shown ad). '
                .'Results list every brand\'s rank-1 ads, then rank 2, and so on, with ads without impression data last. '
                .'This is not a ranking of impressions across brands.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
