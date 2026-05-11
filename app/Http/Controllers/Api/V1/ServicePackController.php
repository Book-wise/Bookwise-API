<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServicePack;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ServicePackController extends Controller
{
    public function index(): JsonResponse
    {
        $packs = ServicePack::with('service')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $packs]);
    }

    public function show(int $id): JsonResponse
    {
        $pack = ServicePack::with('service')->findOrFail($id);
        return response()->json(['data' => $pack]);
    }
}
