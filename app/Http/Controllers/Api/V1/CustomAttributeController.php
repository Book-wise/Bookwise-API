<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientCustomAttribute;
use App\Models\CustomAttributeTemplate;
use Illuminate\Http\JsonResponse;

class CustomAttributeController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = CustomAttributeTemplate::orderBy('name')->get();

        return response()->json(['data' => $templates]);
    }

    public function clientAttributes(int $clientId): JsonResponse
    {
        $attributes = ClientCustomAttribute::with('template')
            ->where('client_id', $clientId)
            ->get();

        return response()->json(['data' => $attributes]);
    }
}
