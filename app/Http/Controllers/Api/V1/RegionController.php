<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Comuna;
use App\Models\Region;
use Illuminate\Http\JsonResponse;

class RegionController extends Controller
{
    public function index(): JsonResponse
    {
        $regions = Region::query()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'timezone']);

        return response()->json(['data' => $regions]);
    }

    /**
     * Get comunas for a given region.
     */
    public function showComunas(int $id): JsonResponse
    {
        $comunas = Comuna::where('region_id', $id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $comunas]);
    }

    /**
     * Get all comunas across all regions in a single request.
     */
    public function indexComunas(): JsonResponse
    {
        $comunas = Comuna::query()
            ->select(['id', 'name', 'region_id'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $comunas]);
    }
}
