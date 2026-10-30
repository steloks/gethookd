<?php

namespace App\Http\Controllers\Api\V0;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Niche;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v0 ad search. FROZEN: real integrations depend on this behaviour, quirks included.
 * Do not fix anything here; fixes belong in v1. Pinned by tests/Feature/V0/AdsContractTest.php.
 *
 * Quirks kept on purpose:
 * - unknown parameters are ignored;
 * - an invalid filter value is ignored, so that filter is not applied (the response looks filtered but is not);
 * - per_page above 100 is silently clamped to 100;
 * - sorted by start_date descending with no tiebreaker, so ties can reorder between pages;
 * - every error is 400 {"error":"Invalid request"} (see bootstrap/app.php).
 */
class AdController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $query = Ad::query();

        if (($q = $this->stringParam($request, 'q')) !== null) {
            $pattern = '%'.addcslashes($q, '\\%_').'%';
            $query->where(fn (Builder $where) => $where
                ->where('headline', 'ilike', $pattern)
                ->orWhere('body_text', 'ilike', $pattern));
        }

        if (($nicheId = $this->nicheId($this->stringParam($request, 'niche'))) !== null) {
            $query->where('niche_id', $nicheId);
        }

        if (in_array($format = $this->stringParam($request, 'format'), AdFormat::values(), true)) {
            $query->where('format', $format);
        }

        if (in_array($status = $this->stringParam($request, 'status'), AdStatus::values(), true)) {
            $query->where('status', $status);
        }

        // Invalid page values are ignored like invalid filters: the default applies.
        $page = $this->positiveInt($request, 'page') ?? 1;
        $perPage = min($this->positiveInt($request, 'per_page') ?? self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE);

        $total = $query->count();
        $ads = $query->with(['brand:id,name', 'niche:id,name'])
            ->orderByDesc('start_date')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $ads->map(fn (Ad $ad) => [
                'id' => $ad->id,
                'brand' => $ad->brand->name,
                'headline' => $ad->headline,
                'niche' => $ad->niche->name,
                'format' => $ad->format->value,
                'status' => $ad->status->value,
                'start_date' => $ad->start_date->toDateString(),
                'days_active' => $ad->days_active,
            ])->all(),
            'page' => $page,
            'total' => $total,
        ]);
    }

    /**
     * A non-empty string value, or null when the parameter is missing, empty, or not a string (e.g. niche[]=x).
     */
    private function stringParam(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function positiveInt(Request $request, string $name): ?int
    {
        $value = filter_var($this->stringParam($request, $name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    /**
     * Matches a slug or a display name, case-insensitively ("Pet Supplies", "pet-supplies", "PET SUPPLIES").
     */
    private function nicheId(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return Niche::query()
            ->whereRaw('lower(slug) = lower(?) OR lower(name) = lower(?)', [$value, $value])
            ->value('id');
    }
}
