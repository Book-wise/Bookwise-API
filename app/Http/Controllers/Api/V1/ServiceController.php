<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $services = Service::query()
            ->when($request->active !== null, fn($q) =>
                $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name')
            ->paginate($request->per_page ?? 15);

        return response()->json($services);
    }

    public function show(int $id): JsonResponse
    {
        $service = Service::with('providers')->findOrFail($id);
        return response()->json(['data' => $service]);
    }
}
