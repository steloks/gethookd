<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AdResource;
use App\Models\Ad;
use App\Search\AdSearch;
use App\Search\SearchRequestParser;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdController extends Controller
{
    /**
     * Ids are positive bigints. Anything else cannot exist, and is rejected before it reaches the
     * query (a non-numeric or out-of-range value would otherwise be a database error, i.e. a 500).
     */
    private const MAX_ID_DIGITS = 18;

    public function index(Request $request, SearchRequestParser $parser, AdSearch $search): JsonResponse
    {
        $params = $parser->parse((string) $request->server('QUERY_STRING', ''), CarbonImmutable::now('UTC'));

        return response()->json(array_filter([
            'data' => AdResource::collection($search->run($params)),
            'applied_filters' => (object) $params->appliedFilters(),
            'sort' => $params->sort->value,
            'sort_note' => $params->sort->note(),
            'limit' => $params->limit,
        ], fn ($value) => $value !== null));
    }

    public function show(string $id): AdResource
    {
        if (! ctype_digit($id) || strlen($id) > self::MAX_ID_DIGITS) {
            throw new ApiError(
                ErrorCode::NotFound,
                'Ad ids are positive whole numbers.',
                'Use an id from the "id" field of a search result.',
            );
        }

        $ad = Ad::query()->with(['brand', 'niche'])->find((int) $id)
            ?? throw new ApiError(
                ErrorCode::NotFound,
                "There is no ad with id {$id}.",
                'Use an id from the "id" field of a search result.',
            );

        return new AdResource($ad);
    }
}
