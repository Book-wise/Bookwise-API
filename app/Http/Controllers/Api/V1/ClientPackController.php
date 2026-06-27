<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PackSessionResource;
use App\Models\ClientPack;
use App\Models\PackSession;
use App\Models\ServicePack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $packs = ClientPack::with(['servicePack.service', 'sessions.booking'])
            ->where('client_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $packs]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $pack = ClientPack::with([
            'servicePack.service',
            'sessions' => fn ($q) => $q->orderBy('session_number'),
            'sessions.clientPack.servicePack.service',
            'sessions.booking.provider',
            'sessions.booking.location',
            'sessions.booking.status',
            'client',
        ])
            ->where('client_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $pack->id,
                'client_id' => $pack->client_id,
                'service_pack' => [
                    'id' => $pack->servicePack->id,
                    'name' => $pack->servicePack->name,
                    'total_sessions' => $pack->servicePack->total_sessions,
                    'price' => $pack->servicePack->price,
                    'service' => [
                        'id' => $pack->servicePack->service->id,
                        'name' => $pack->servicePack->service->name,
                        'price' => $pack->servicePack->service->price,
                    ],
                ],
                'sessions' => PackSessionResource::collection($pack->sessions),
            ],
            'meta' => [
                'total_sessions' => $pack->total_sessions,
                'used_sessions' => $pack->used_sessions,
                'remaining_sessions' => $pack->remaining_sessions,
                'status' => $pack->status,
                'default_price_per_session' => (float) ($pack->servicePack->service->price ?? 0),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_pack_id' => ['required', 'integer', 'exists:service_packs,id'],
            'wc_order_id' => ['nullable', 'integer'],
        ]);

        $servicePack = ServicePack::findOrFail($validated['service_pack_id']);

        $clientPack = ClientPack::create([
            'client_id' => $validated['client_id'],
            'service_pack_id' => $validated['service_pack_id'],
            'wc_order_id' => $validated['wc_order_id'] ?? null,
            'total_sessions' => $servicePack->total_sessions,
            'used_sessions' => 0,
            'status' => 'active',
        ]);

        // Crear las sesiones individuales
        for ($i = 1; $i <= $servicePack->total_sessions; $i++) {
            PackSession::create([
                'client_pack_id' => $clientPack->id,
                'session_number' => $i,
                'status' => 'pending',
            ]);
        }

        $clientPack->load(['servicePack.service', 'sessions']);

        return response()->json(['data' => $clientPack], 201);
    }

    public function use(int $id, Request $request): JsonResponse
    {
        $pack = ClientPack::when(
            ! $request->user()->isAdmin(),
            fn ($q) => $q->where('client_id', $request->user()->id)
        )->findOrFail($id);

        if ($pack->status !== 'active') {
            return response()->json([
                'error' => 'pack_not_active',
                'detail' => 'This pack is not active.',
            ], 422);
        }

        if ($pack->remaining_sessions <= 0) {
            return response()->json([
                'error' => 'no_sessions_remaining',
                'detail' => 'No sessions remaining in this pack.',
            ], 422);
        }

        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
        ]);

        $session = $pack->sessions()
            ->where('status', 'pending')
            ->orderBy('session_number')
            ->firstOrFail();

        $session->update([
            'booking_id' => $validated['booking_id'],
            'status' => 'scheduled',
        ]);

        $pack->increment('used_sessions');

        if ($pack->fresh()->remaining_sessions === 0) {
            $pack->update(['status' => 'completed']);
        }

        return response()->json([
            'data' => $pack->fresh()->load(['sessions']),
            'meta' => [
                'remaining_sessions' => $pack->fresh()->remaining_sessions,
                'session_used' => $session->session_number,
            ],
        ]);
    }

    public function clientPacks(int $clientId): JsonResponse
    {
        $packs = ClientPack::with(['servicePack.service', 'sessions.booking'])
            ->where('client_id', $clientId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $packs]);
    }
}
