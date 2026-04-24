<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AvailableSlotsController extends Controller
{
    public function __construct(
        private SlotAvailabilityService $slotService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id'      => ['required', 'integer', 'exists:locations,id'],
            'start_date'       => ['required', 'date_format:Y-m-d'],
            'service_id'       => ['nullable', 'integer', 'exists:services,id'],
            'provider_id'      => ['nullable', 'integer', 'exists:providers,id'],
            'duration_minutes' => [
                'nullable', 'integer',
                'min:' . env('BOOKING_MIN_DURATION_MINUTES', 15),
                'max:' . env('BOOKING_MAX_DURATION_MINUTES', 480),
                function ($attribute, $value, $fail) {
                    if ($value !== null && $value % 15 !== 0) {
                        $fail('La duración debe ser múltiplo de 15 minutos.');
                    }
                },
            ],
        ]);

        $slots = $this->slotService->getAvailableSlots(
            locationId:      $validated['location_id'],
            startDate:       $validated['start_date'],
            serviceId:       $validated['service_id']       ?? null,
            providerId:      $validated['provider_id']      ?? null,
            durationMinutes: $validated['duration_minutes'] ?? null,
        );

        return response()->json([
            'data' => $slots,
            'meta' => [
                'date'             => $validated['start_date'],
                'location_id'      => $validated['location_id'],
                'service_id'       => $validated['service_id'] ?? null,
                'duration_minutes' => $validated['duration_minutes'] ?? null,
                'total_slots'      => $slots->count(),
            ],
        ]);
    }
}
