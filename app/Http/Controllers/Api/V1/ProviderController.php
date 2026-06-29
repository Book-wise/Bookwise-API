<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProviderResource;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $providers = Provider::query()
            ->with(['location', 'services'])
            ->when($request->location_id, fn ($q) => $q->where('location_id', $request->location_id)
            )
            ->when($request->service_id, function ($q) use ($request) {
                $q->whereHas('services', function ($q) use ($request) {
                    $q->where('services.id', $request->service_id);
                });
            })
            ->when($request->active !== null, function ($q) use ($request) {
                $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('first_name')
            ->paginate($request->per_page ?? 15);

        return response()->json(ProviderResource::collection($providers));
    }

    public function show(int $id): JsonResponse
    {
        $provider = Provider::with(['location', 'services'])->findOrFail($id);

        return response()->json(['data' => new ProviderResource($provider)]);
    }
}
