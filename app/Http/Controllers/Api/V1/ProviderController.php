<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $providers = Provider::query()
            ->with(['locations', 'services'])
            ->when($request->location_id, function($q) use ($request) {
                $q->whereHas('locations', function($q) use ($request) {
                    $q->where('locations.id', $request->location_id);
                });
            })
            ->when($request->service_id, function($q) use ($request) {
                $q->whereHas('services', function($q) use ($request) {
                    $q->where('services.id', $request->service_id);
                });
            })
            ->when($request->active !== null, function($q) use ($request) {
                $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('first_name')
            ->paginate($request->per_page ?? 15);

        return response()->json($providers);
    }

    public function show(int $id): JsonResponse
    {
        $provider = Provider::with(['locations', 'services'])->findOrFail($id);
        return response()->json(['data' => $provider]);
    }
}
