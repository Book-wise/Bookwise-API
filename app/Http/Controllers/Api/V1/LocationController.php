<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locations = Location::query()
            ->when($request->active !== null, fn ($q) => $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name')
            ->paginate($request->per_page ?? 15);

        return response()->json(LocationResource::collection($locations));
    }

    public function show(int $id): JsonResponse
    {
        $location = Location::with('providers')->findOrFail($id);

        return response()->json(['data' => new LocationResource($location)]);
    }
}
