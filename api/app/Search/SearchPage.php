<?php

namespace App\Search;

use App\Models\Ad;
use Illuminate\Database\Eloquent\Collection;

/**
 * One page of search results, and the cursor for the next page (null on the last page).
 */
final readonly class SearchPage
{
    /**
     * @param  Collection<int, Ad>  $ads
     */
    public function __construct(
        public Collection $ads,
        public ?string $nextCursor,
    ) {}
}
