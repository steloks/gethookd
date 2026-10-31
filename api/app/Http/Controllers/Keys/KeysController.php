<?php

namespace App\Http\Controllers\Keys;

use App\Auth\KeyManagement;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Key management and usage, for one account. The /api/v1 endpoints and the developer page differ
 * only in whose keys they manage (owner()); everything else is KeyManagement.
 */
abstract class KeysController extends Controller
{
    public function __construct(protected readonly KeyManagement $keys) {}

    abstract protected function owner(Request $request): User;

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->keys->list($this->owner($request), CarbonImmutable::now())]);
    }

    public function store(Request $request): JsonResponse
    {
        $content = (string) $request->getContent();
        $body = $content === '' ? null : json_decode($content, true, 16);

        [$key, $secret] = $this->keys->create($this->owner($request), $body);

        // The only response that ever contains the key: nothing on the way may keep a copy.
        return response()->json([
            'data' => $key,
            'secret' => $secret,
            'note' => 'This is the only time the full key is shown. Store it now; only its prefix can be seen later.',
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->keys->revoke($this->owner($request), $id, CarbonImmutable::now())]);
    }

    public function usage(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->keys->usage($this->owner($request), CarbonImmutable::now())]);
    }
}
