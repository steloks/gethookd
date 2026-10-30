<?php

namespace App\Search;

use App\Models\Ad;
use App\Models\Niche;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Runs a validated v1 search. Every sort ends with id, so the order is total and deterministic.
 */
final class AdSearch
{
    /**
     * @return Collection<int, Ad>
     */
    public function run(SearchParams $params): Collection
    {
        $query = Ad::query()->with(['brand', 'niche']);

        if ($params->q !== null) {
            $pattern = '%'.addcslashes($params->q, '\\%_').'%';
            $query->where(fn (Builder $where) => $where
                ->where('headline', 'ilike', $pattern)
                ->orWhere('body_text', 'ilike', $pattern));
        }

        if ($params->niches !== []) {
            $query->whereIn('niche_id', Niche::query()->whereIn('slug', $params->niches)->select('id'));
        }

        if ($params->format !== null) {
            $query->where('format', $params->format->value);
        }

        if ($params->status !== null) {
            $query->where('status', $params->status->value);
        }

        if ($params->startedAfter !== null) {
            // On or after the given day.
            $query->where('start_date', '>=', $params->startedAfter->toDateString());
        }

        match ($params->sort) {
            SearchSort::Newest => $query->orderByDesc('start_date'),
            // Ads without impression data always come last.
            SearchSort::Impressions => $query->orderByRaw('impression_rank ASC NULLS LAST'),
        };

        return $query->orderByDesc('id')->limit($params->limit)->get();
    }
}
