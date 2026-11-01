<?php

namespace App\Search;

use App\Models\Ad;
use App\Models\Niche;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Runs a validated v1 search, one page at a time. Every sort ends with id, so the order is total
 * and deterministic, and the next page starts strictly after the last ad of this one (keyset).
 *
 * Under live ingest: no page ever repeats an ad, and every ad that existed at page 1 and still
 * matches the filters when its page is read is returned once. Neither sort key ever changes
 * (start dates and impression ranks are never rewritten), so a deactivation cannot move an ad
 * across a page boundary; ads with ids above the page-1 snapshot are never returned, so new
 * ads cannot push others onto a page already read. See REFERENCE.md, "Pagination".
 */
final class AdSearch
{
    public function run(SearchParams $params): SearchPage
    {
        $snapshot = $params->cursor?->snapshot ?? (int) Ad::query()->max('id');
        $query = Ad::query()->with(['brand', 'niche'])->where('id', '<=', $snapshot);

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

        // One extra row says whether there is a next page, without a count query.
        $ads = match ($params->sort) {
            SearchSort::Newest => $this->newest($query, $params->limit + 1, $params->cursor?->after),
            SearchSort::Impressions => $this->impressions($query, $params->limit + 1, $params->cursor?->after),
        };
        if ($ads->count() <= $params->limit) {
            return new SearchPage($ads, null);
        }

        $ads = $ads->take($params->limit);
        $last = $ads->last();
        $next = new Cursor($params->sort, $params->appliedFilters(), $params->limit, $snapshot, match ($params->sort) {
            SearchSort::Newest => [$last->start_date->toDateString(), $last->id],
            SearchSort::Impressions => [$last->impression_rank, $last->id],
        });

        return new SearchPage($ads, $next->encode());
    }

    /**
     * start_date DESC, id DESC. Both descending, so one row comparison continues after the cursor,
     * and it is an index range condition.
     *
     * @param  array{0: string|int|null, 1: int}|null  $after
     * @return Collection<int, Ad>
     */
    private function newest(Builder $query, int $take, ?array $after): Collection
    {
        if ($after !== null) {
            $query->whereRaw('(start_date, id) < (CAST(? AS date), ?)', $after);
        }

        return $query->orderByDesc('start_date')->orderByDesc('id')->limit($take)->get();
    }

    /**
     * impression_rank ASC NULLS LAST, id DESC, read in two steps: the ranked ads after the cursor,
     * then, if the page is not full, the ads without impression data (always last). One OR-ed
     * condition would be correct too, but Postgres can only apply it as a filter, so every page
     * would re-read the index from rank 1. Each step here is an index range scan.
     *
     * @param  array{0: string|int|null, 1: int}|null  $after
     * @return Collection<int, Ad>
     */
    private function impressions(Builder $query, int $take, ?array $after): Collection
    {
        [$rank, $id] = $after ?? [null, null];
        $inNullSection = $after !== null && $rank === null;

        $ranked = new Collection;
        if (! $inNullSection) {
            $rankedQuery = $query->clone()->whereNotNull('impression_rank');
            if ($after !== null) {
                // rank > r, or rank = r with a lower id; the first condition is the indexable bound.
                $rankedQuery->where('impression_rank', '>=', $rank)
                    ->where(fn (Builder $where) => $where->where('impression_rank', '>', $rank)->orWhere('id', '<', $id));
            }
            $ranked = $rankedQuery->orderBy('impression_rank')->orderByDesc('id')->limit($take)->get();
        }

        if ($ranked->count() === $take) {
            return $ranked;
        }

        $nulls = $query->clone()->whereNull('impression_rank')
            ->when($inNullSection, fn (Builder $where) => $where->where('id', '<', $id))
            ->orderByDesc('id')->limit($take - $ranked->count())->get();

        return $ranked->concat($nulls);
    }
}
