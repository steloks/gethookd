<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AdResource;
use App\Models\Ad;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;

class AdController extends Controller
{
    /**
     * Ids are positive bigints. Anything else cannot exist, and is rejected before it reaches the
     * query (a non-numeric or out-of-range value would otherwise be a database error, i.e. a 500).
     */
    private const MAX_ID_DIGITS = 18;

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
