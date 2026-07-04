<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AgentAvailabilitySlotResource;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    // ── GET /v1/agent/check-availability ───────────────────────────
    public function checkAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $service = Service::findOrFail($validated['service_id']);
        $duration = $service->effective_duration_minutes;

        $start = Carbon::parse("{$validated['date']} {$validated['time']}");
        $end = $start->copy()->addMinutes($duration);

        $providers = Provider::with('location')
            ->where('active', true)
            ->whereHas('services', fn ($q) => $q->where('services.id', $service->id))
            ->get();

        $slots = collect();

        foreach ($providers as $provider) {
            $location = $provider->location;

            if (! $location || ! $location->active) {
                continue;
            }

            $opening = Carbon::parse($validated['date'].' '.($location->opening_time ?? '09:00:00'));
            $closing = Carbon::parse($validated['date'].' '.($location->closing_time ?? '19:00:00'));

            if ($start->lt($opening) || $end->gt($closing)) {
                continue;
            }

            $hasCollision = Booking::active()
                ->where('provider_id', $provider->id)
                ->whereDate('start_time', $validated['date'])
                ->overlapping($start, $end)
                ->exists();

            if ($hasCollision) {
                continue;
            }

            $slots->push([
                'provider' => $provider,
                'location' => $location,
                'start_time' => $start->toIso8601String(),
                'end_time' => $end->toIso8601String(),
            ]);
        }

        return response()->json([
            'available' => $slots->isNotEmpty(),
            'slots' => AgentAvailabilitySlotResource::collection($slots),
        ]);
    }
}
