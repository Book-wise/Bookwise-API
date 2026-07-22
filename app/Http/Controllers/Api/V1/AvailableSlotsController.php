<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailableSlotsRequest;
use App\Http\Resources\V1\AvailableSlotResource;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\JsonResponse;

class AvailableSlotsController extends Controller
{
    public function __construct(
        private SlotAvailabilityService $slotService
    ) {}

    public function index(AvailableSlotsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slots = $this->slotService->getAvailableSlots(
            locationId: $validated['location_id'],
            startDate: $validated['start_date'],
            serviceId: $validated['service_id'] ?? null,
            providerId: $validated['provider_id'] ?? null,
            durationMinutes: $validated['duration_minutes'] ?? null,
            slotInterval: $validated['slot_interval'] ?? null,
        );

        return response()->json([
            'data' => AvailableSlotResource::collection($slots),
            'meta' => [
                'date' => $validated['start_date'],
                'location_id' => $validated['location_id'],
                'service_id' => $validated['service_id'] ?? null,
                'duration_minutes' => $validated['duration_minutes'] ?? null,
                'slot_interval' => $validated['slot_interval'] ?? null,
                'total_slots' => $slots->count(),
            ],
        ]);
    }
}
