<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PackSessionResource;
use App\Models\PackSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackSessionController extends Controller
{
    // ── PATCH /v1/pack-sessions/{id} ──────────────────────────────
    public function update(int $id, Request $request): JsonResponse
    {
        $session = PackSession::with([
            'clientPack.servicePack.service',
            'booking.provider',
            'booking.location',
            'booking.status',
        ])->findOrFail($id);

        $validated = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $session->update($validated);

        return response()->json(['data' => new PackSessionResource($session)]);
    }
}
