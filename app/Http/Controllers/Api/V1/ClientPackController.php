<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PackSessionResource;
use App\Models\ClientPack;
use App\Models\PackSession;
use App\Models\ServicePack;
use App\Services\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientPackController extends Controller
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
    ) {}

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
        $endpoint = 'PATCH /v1/client-packs/'.$id.'/use';
        $requestHash = md5($request->getContent());
        $hasIdempotencyKey = $request->hasHeader('Idempotency-Key');

        if ($hasIdempotencyKey) {
            $cached = $this->idempotency->check($request, $endpoint);
            if ($cached !== null) {
                return $cached;
            }

            $status = $this->idempotency->acquire($request, $endpoint, $requestHash);
            if ($status === 1) {
                return response()->json([
                    'error' => 'conflict',
                    'detail' => 'A request with this idempotency key is already in progress or conflicts.',
                ], 409);
            }
        }

        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
        ]);

        try {
            $result = DB::transaction(function () use ($id, $request, $validated) {
                $pack = ClientPack::lockForUpdate()
                    ->when(
                        ! $request->user()->isAdmin(),
                        fn ($q) => $q->where('client_id', $request->user()->id)
                    )
                    ->findOrFail($id);

                if ($pack->status !== 'active') {
                    return ['error' => 'pack_not_active'];
                }

                if ($pack->remaining_sessions <= 0) {
                    return ['error' => 'no_sessions_remaining'];
                }

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

                $freshPack = $pack->fresh()->load(['sessions']);

                return [
                    'pack' => $freshPack,
                    'session_used' => $session->session_number,
                ];
            });
        } catch (\Throwable $e) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $e;
        }

        if (isset($result['error'])) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            $detail = $result['error'] === 'pack_not_active'
                ? 'This pack is not active.'
                : 'No sessions remaining in this pack.';

            return response()->json([
                'error' => $result['error'],
                'detail' => $detail,
            ], 422);
        }

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 200, [
                'data' => $result['pack'],
                'meta' => [
                    'remaining_sessions' => $result['pack']->remaining_sessions,
                    'session_used' => $result['session_used'],
                ],
            ]);
        }

        return response()->json([
            'data' => $result['pack'],
            'meta' => [
                'remaining_sessions' => $result['pack']->remaining_sessions,
                'session_used' => $result['session_used'],
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
