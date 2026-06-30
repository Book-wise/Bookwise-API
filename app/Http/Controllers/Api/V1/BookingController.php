<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BookingResource;
use App\Mail\BookingConfirmation;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServicePack;
use App\Services\IdempotencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class BookingController extends Controller
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
    ) {}

    // ── GET /v1/bookings ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::with(['client', 'service', 'provider', 'location', 'status', 'sale', 'packSession.clientPack'])
            // Provider filter: only show bookings for their locations
            ->when($user?->role?->value === 'provider', function ($query) use ($user) {
                $provider = $user->provider;
                if ($provider?->location_id) {
                    $query->where('location_id', $provider->location_id);
                }
            })
            ->when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->service_id, fn ($q) => $q->where('service_id', $request->service_id))
            ->when($request->provider_id, fn ($q) => $q->where('provider_id', $request->provider_id))
            ->when($request->location_id, fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->status_id, fn ($q) => $q->where('status_id', $request->status_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('start_time', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('start_time', '<=', $request->date_to))
            ->when($request->wc_order_id, fn ($q) => $q->where('wc_order_id', $request->wc_order_id))
            ->orderBy('start_time', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json(BookingResource::collection($bookings));
    }

    // ── GET /v1/bookings/{id} ──────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $booking = Booking::with([
            'client', 'service', 'provider', 'location', 'status',
            'statusHistory.status', 'sale',
            'packSession.clientPack.servicePack.service',
            'packSession.clientPack.sessions' => fn ($q) => $q->orderBy('session_number'),
            'packSession.clientPack.sessions.clientPack.servicePack.service',
            'packSession.clientPack.sessions.booking.provider',
            'packSession.clientPack.sessions.booking.location',
            'packSession.clientPack.sessions.booking.status',
        ])->findOrFail($id);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── POST /v1/bookings ──────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_time' => ['required', 'date'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_pack_id' => ['nullable', 'integer', 'exists:service_packs,id'],
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'status_id' => ['required', 'integer', 'exists:booking_statuses,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'wc_order_id' => ['nullable', 'integer'],
        ]);

        // Exactly one of service_id / service_pack_id is required
        $hasService = filled($validated['service_id'] ?? null);
        $hasPack = filled($validated['service_pack_id'] ?? null);

        if ($hasService && $hasPack) {
            return response()->json([
                'error' => 'invalid_input',
                'detail' => 'Provide either service_id or service_pack_id, not both.',
            ], 422);
        }

        if (! $hasService && ! $hasPack) {
            return response()->json([
                'error' => 'invalid_input',
                'detail' => 'Either service_id or service_pack_id is required.',
            ], 422);
        }

        // Resolve service — from pack or directly
        if ($hasPack) {
            $servicePack = ServicePack::with('service')->findOrFail($validated['service_pack_id']);
            $validated['service_id'] = $servicePack->service_id;
            $service = $servicePack->service;
            unset($validated['service_pack_id']);
        } else {
            $service = Service::findOrFail($validated['service_id']);
        }

        $endpoint = 'POST /v1/bookings';
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

        $user = $request->user();
        $provider = Provider::findOrFail($validated['provider_id']);

        if ($user->isProvider() && (int) $user->provider_id !== (int) $validated['provider_id']) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => 'forbidden',
                'detail' => 'You can only create bookings for your own provider profile.',
            ], 403);
        }

        if ((int) $provider->location_id !== (int) $validated['location_id']) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => 'provider_location_mismatch',
                'detail' => 'The provider does not belong to the selected location.',
            ], 422);
        }

        $startTime = Carbon::parse($validated['start_time']);

        if ($startTime->isPast()) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => 'past_booking',
                'detail' => 'Cannot create a booking in the past.',
            ], 422);
        }

        // Duración efectiva
        $effectiveDuration = $validated['duration_minutes']
            ?? $service->duration_minutes
            ?? config('booking.default_duration_minutes', 30);

        $endTime = isset($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $startTime->copy()->addMinutes($effectiveDuration);

        $conflictData = null;

        try {
            $booking = DB::transaction(function () use ($validated, $startTime, $endTime, $service, &$conflictData) {
                // AD-1: Lock Provider row BEFORE overlap check to serialize
                // all booking attempts for this provider
                Provider::lockForUpdate()->findOrFail($validated['provider_id']);

                $conflict = $this->checkBookingOverlap(
                    $validated['provider_id'],
                    $validated['location_id'],
                    $validated['client_id'],
                    $startTime,
                    $endTime
                );

                if ($conflict) {
                    $conflict->load(['client', 'provider']);

                    $conflictType = match (true) {
                        $conflict->provider_id === (int) $validated['provider_id'] => 'Provider overlap with existing booking',
                        default => 'Client overlap with existing booking',
                    };

                    $conflictData = [
                        'error' => 'conflict',
                        'detail' => $conflictType,
                        'conflicts_with' => [
                            'id' => $conflict->id,
                            'start_time' => $conflict->start_time->toIso8601String(),
                            'end_time' => $conflict->end_time->toIso8601String(),
                            'client' => [
                                'id' => $conflict->client->id,
                                'first_name' => $conflict->client->first_name,
                                'last_name' => $conflict->client->last_name,
                            ],
                            'provider' => [
                                'id' => $conflict->provider->id,
                                'first_name' => $conflict->provider->first_name,
                                'last_name' => $conflict->provider->last_name,
                            ],
                        ],
                    ];

                    return null;
                }

                $booking = Booking::create([
                    ...$validated,
                    'end_time' => $endTime,
                    'custom_duration_minutes' => $validated['duration_minutes'] ?? null,
                    'price' => $validated['price'] ?? $service->price,
                ]);

                $booking->load(['client', 'service', 'provider', 'location', 'status']);

                return $booking;
            });
        } catch (\Throwable $e) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $e;
        }

        if ($conflictData !== null) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json($conflictData, 409);
        }

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 201, ['data' => new BookingResource($booking)]);
        }

        // Notificación inmediata de cita (solo si el cliente tiene habilitadas las notificaciones)
        if ($booking->client->notifications_enabled && $booking->client->email) {
            Mail::to($booking->client->email)->send(new BookingConfirmation($booking));
        }

        return response()->json(['data' => new BookingResource($booking)], 201);
    }

    // ── PATCH /v1/bookings/{id} ────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        $validated = $request->validate([
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['sometimes', 'date', 'after:start_time'],
            'status_id' => ['sometimes', 'integer', 'exists:booking_statuses,id'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'provider_id' => ['sometimes', 'integer', 'exists:providers,id'],
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'service_pack_id' => ['sometimes', 'integer', 'exists:service_packs,id'],
        ]);

        // Mutual exclusivity for service changes
        if (isset($validated['service_id']) && isset($validated['service_pack_id'])) {
            return response()->json([
                'error' => 'invalid_input',
                'detail' => 'Provide either service_id or service_pack_id, not both.',
            ], 422);
        }

        // Resolve service_id from pack
        if (isset($validated['service_pack_id'])) {
            $servicePack = ServicePack::findOrFail($validated['service_pack_id']);
            $validated['service_id'] = $servicePack->service_id;
            unset($validated['service_pack_id']);
        }

        // Check for booking overlaps if time or provider is being changed
        if (isset($validated['start_time']) || isset($validated['end_time'])
            || isset($validated['provider_id'])) {

            $startTime = isset($validated['start_time'])
                ? Carbon::parse($validated['start_time'])
                : $booking->start_time;

            if (isset($validated['start_time']) && $startTime->isPast()) {
                return response()->json([
                    'error' => 'past_booking',
                    'detail' => 'Cannot move a booking to the past.',
                ], 422);
            }

            $endTime = isset($validated['end_time'])
                ? Carbon::parse($validated['end_time'])
                : ($booking->end_time ?? $startTime->copy()->addMinutes($booking->effective_duration_minutes));

            $providerId = $validated['provider_id'] ?? $booking->provider_id;
            $locationId = $validated['location_id'] ?? $booking->location_id;
            $clientId = $validated['client_id'] ?? $booking->client_id;

            $conflict = $this->checkBookingOverlap(
                $providerId,
                $locationId,
                $clientId,
                $startTime,
                $endTime,
                $booking->id
            );

            if ($conflict) {
                $conflict->load(['client', 'provider']);

                $conflictType = match (true) {
                    $conflict->provider_id === (int) $providerId => 'Provider overlap with existing booking',
                    default => 'Client overlap with existing booking',
                };

                return response()->json([
                    'error' => 'conflict',
                    'detail' => $conflictType,
                    'conflicts_with' => [
                        'id' => $conflict->id,
                        'start_time' => $conflict->start_time->toIso8601String(),
                        'end_time' => $conflict->end_time->toIso8601String(),
                        'client' => [
                            'id' => $conflict->client->id,
                            'first_name' => $conflict->client->first_name,
                            'last_name' => $conflict->client->last_name,
                        ],
                        'provider' => [
                            'id' => $conflict->provider->id,
                            'first_name' => $conflict->provider->first_name,
                            'last_name' => $conflict->provider->last_name,
                        ],
                    ],
                ], 409);
            }
        }

        $booking->update($validated);
        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── PATCH /v1/bookings/{id}/cancel ────────────────────────────
    public function cancel(Request $request, int $id): JsonResponse
    {
        $endpoint = 'PATCH /v1/bookings/'.$id.'/cancel';
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

        try {
            $result = DB::transaction(function () use ($id, $request) {
                // Lock the Booking row to serialize concurrent cancel requests
                $booking = Booking::lockForUpdate()->findOrFail($id);

                $cancelStatus = BookingStatus::where('is_cancellation', true)->firstOrFail();

                // Ya está cancelada — second concurrent reader now sees this
                if ($booking->status_id === $cancelStatus->id) {
                    return [
                        'already_cancelled' => true,
                    ];
                }

                $booking->update(['status_id' => $cancelStatus->id]);

                // Registrar en historial
                $booking->statusHistory()->create([
                    'status_id' => $cancelStatus->id,
                    'notes' => $request->input('notes', 'Cancelled via API'),
                ]);

                $booking->load(['client', 'service', 'provider', 'location', 'status']);

                return [
                    'already_cancelled' => false,
                    'booking' => $booking,
                ];
            });
        } catch (\Throwable $e) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $e;
        }

        if ($result['already_cancelled']) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => 'already_cancelled',
                'detail' => 'This booking is already cancelled.',
            ], 422);
        }

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 200, ['data' => new BookingResource($result['booking'])]);
        }

        return response()->json(['data' => new BookingResource($result['booking'])]);
    }

    // ── Private: Check for booking overlaps ───────────────────────────
    private function checkBookingOverlap(
        int $providerId,
        int $locationId,
        int $clientId,
        Carbon $startTime,
        Carbon $endTime,
        ?int $excludeBookingId = null
    ): ?Booking {
        $base = fn ($column, $value) => Booking::where($column, $value)
            ->active()
            ->overlapping($startTime, $endTime, $excludeBookingId)
            ->first();

        return $base('provider_id', $providerId)
            ?? $base('client_id', $clientId);
    }
}
