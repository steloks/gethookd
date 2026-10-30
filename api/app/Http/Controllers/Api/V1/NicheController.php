<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Niche;
use Illuminate\Http\JsonResponse;

class NicheController extends Controller
{
    /**
     * The 12 niches. The slug is what the niche filter takes; the keywords are the words the
     * seeded headlines use, useful as search terms. Source for the MCP list_niches tool.
     */
    public function index(): JsonResponse
    {
        $niches = Niche::query()->orderBy('id')->get()->map(fn (Niche $niche) => [
            'slug' => $niche->slug,
            'name' => $niche->name,
            'keywords' => $niche->keywords(),
        ]);

        return response()->json(['data' => $niches]);
    }
}
