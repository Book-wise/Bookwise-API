<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BlockedSlotResource;
use App\Models\BlockedSlot;
use App\Models\Booking;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BlockedSlotController extends Controller
{
    // ── POST /v1/blocked-slots ─────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'in:single,all'],
            'provider_id' => ['required_unless:scope,all', 'prohibited_if:scope,all', 'integer', 'exists:providers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'repeat' => ['nullable', 'array'],
            'repeat.type' => ['required_with:repeat', 'in:daily,weekly,monthly'],
            'repeat.interval' => ['nullable', 'integer', 'min:1'],
            'repeat.days' => ['nullable', 'array'],
            'repeat.days.*' => ['integer', 'between:0,6'],
            'repeat.end_type' => ['required_with:repeat', 'in:after,until,never'],
            'repeat.count' => ['required_if:repeat.end_type,after', 'integer', 'min:1'],
            'repeat.until' => ['required_if:repeat.end_type,until', 'date', 'after:start_time'],
        ]);

        $user = $request->user();
        $start = Carbon::parse($data['start_time']);
        $end = Carbon::parse($data['end_time']);
        $duration = $start->diffInMinutes($end);

        if (($data['scope'] ?? 'single') === 'all') {
            if ($user->isProvider()) {
                return response()->json([
                    'error' => 'forbidden',
                    'detail' => 'Only an administrator can block time slots for an entire location.',
                ], 403);
            }

            $providers = Provider::where('location_id', $data['location_id'])
                ->where('active', true)
                ->get();

            $blocked = [];
            $conflicts = [];

            foreach ($providers as $provider) {
                $result = $this->blockProvider($provider, $data, $start, $end, $duration);

                if ($result['status'] === 'created') {
                    $blocked = array_merge($blocked, $result['slots']->pluck('id')->all());
                } else {
                    $conflicts[] = [
                        'provider' => [
                            'id' => $provider->id,
                            'first_name' => $provider->first_name,
                            'last_name' => $provider->last_name,
                        ],
                        'conflict' => $result['conflict'],
                    ];
                }
            }

            return response()->json(['blocked' => $blocked, 'conflicts' => $conflicts], 201);
        }

        $provider = Provider::findOrFail($data['provider_id']);

        if ($user->isProvider() && (int) $user->provider_id !== (int) $data['provider_id']) {
            return response()->json([
                'error' => 'forbidden',
                'detail' => 'You can only create blocks for your own provider profile.',
            ], 403);
        }

        if ((int) $provider->location_id !== (int) $data['location_id']) {
            return response()->json([
                'error' => 'provider_location_mismatch',
                'detail' => 'The provider does not belong to the selected location.',
            ], 422);
        }

        $result = $this->blockProvider($provider, $data, $start, $end, $duration);

        if ($result['status'] === 'conflict') {
            $detail = $result['conflict']['type'] === 'booking'
                ? 'The provider has a booking at this time.'
                : 'This time slot is already blocked for this provider.';

            return response()->json([
                'error' => 'slot_collision',
                'detail' => $detail,
                'conflicts_with' => $result['conflict'],
            ], 409);
        }

        return response()->json(['data' => BlockedSlotResource::collection($result['slots'])], 201);
    }

    /**
     * Generates occurrences for a single provider, checks collisions and creates the
     * corresponding BlockedSlot rows.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: string, slots?: Collection, conflict?: array<string, mixed>}
     */
    private function blockProvider(Provider $provider, array $data, Carbon $start, Carbon $end, int $duration): array
    {
        $occurrences = isset($data['repeat'])
            ? $this->generateOccurrences($start, $duration, $data['repeat'])
            : [[$start, $end]];

        $groupId = count($occurrences) > 1 ? (string) Str::uuid() : null;

        $slots = collect();
        foreach ($occurrences as [$occStart, $occEnd]) {
            $conflict = $this->findCollision($provider->id, $occStart, $occEnd);

            if ($conflict) {
                return ['status' => 'conflict', 'conflict' => $conflict];
            }

            $slots->push(BlockedSlot::create([
                'start_time' => $occStart,
                'end_time' => $occEnd,
                'reason' => $data['reason'] ?? null,
                'location_id' => $provider->location_id,
                'provider_id' => $provider->id,
                'repeat_group_id' => $groupId,
            ]));
        }

        return ['status' => 'created', 'slots' => $slots];
    }

    /**
     * Checks for blocked-slot or active booking collisions for a provider within a time range.
     *
     * @return array<string, mixed>|null
     */
    private function findCollision(int $providerId, Carbon $start, Carbon $end, ?int $excludeBlockedSlotId = null): ?array
    {
        $blockedCollision = BlockedSlot::where('provider_id', $providerId)
            ->when($excludeBlockedSlotId, fn ($q) => $q->where('id', '!=', $excludeBlockedSlotId))
            ->where(function ($q) use ($start, $end) {
                $q->where('start_time', '<', $end)
                    ->where('end_time', '>', $start);
            })
            ->first();

        if ($blockedCollision) {
            return [
                'id' => $blockedCollision->id,
                'start_time' => $blockedCollision->start_time->toIso8601String(),
                'end_time' => $blockedCollision->end_time->toIso8601String(),
                'reason' => $blockedCollision->reason,
                'type' => 'blocked_slot',
            ];
        }

        $bookingCollision = Booking::where('provider_id', $providerId)
            ->active()
            ->where(function ($q) use ($start, $end) {
                $q->where('start_time', '<', $end)
                    ->where('end_time', '>', $start);
            })
            ->first();

        if ($bookingCollision) {
            return [
                'id' => $bookingCollision->id,
                'start_time' => $bookingCollision->start_time->toIso8601String(),
                'end_time' => $bookingCollision->end_time->toIso8601String(),
                'service' => $bookingCollision->service?->name,
                'client' => $bookingCollision->client?->first_name.' '.$bookingCollision->client?->last_name,
                'type' => 'booking',
            ];
        }

        return null;
    }

    // ── PATCH /v1/blocked-slots/:id ─────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $blockedSlot = BlockedSlot::findOrFail($id);

        $data = $request->validate([
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['sometimes', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'in:single,all'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:providers,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
        ]);

        $start = isset($data['start_time']) ? Carbon::parse($data['start_time']) : $blockedSlot->start_time->copy();
        $end = isset($data['end_time']) ? Carbon::parse($data['end_time']) : $blockedSlot->end_time->copy();

        if ($end->lte($start)) {
            return response()->json([
                'error' => 'validation_error',
                'detail' => 'end_time must be after start_time.',
            ], 422);
        }

        $locationId = $data['location_id'] ?? $blockedSlot->location_id;
        $reason = array_key_exists('reason', $data) ? $data['reason'] : $blockedSlot->reason;
        $user = $request->user();

        if (($data['scope'] ?? 'single') === 'all') {
            if ($user->isProvider()) {
                return response()->json([
                    'error' => 'forbidden',
                    'detail' => 'Only an administrator can block time slots for an entire location.',
                ], 403);
            }

            if ($locationId === null) {
                return response()->json([
                    'error' => 'validation_error',
                    'detail' => 'location_id is required to apply scope=all.',
                ], 422);
            }

            return $this->updateForLocation($blockedSlot, $start, $end, $reason, $locationId);
        }

        $providerId = $data['provider_id'] ?? $blockedSlot->provider_id;
        $provider = Provider::findOrFail($providerId);

        if ($user->isProvider() && (int) $user->provider_id !== (int) $providerId) {
            return response()->json([
                'error' => 'forbidden',
                'detail' => 'You can only edit blocks for your own provider profile.',
            ], 403);
        }

        if ((int) $provider->location_id !== (int) $locationId) {
            return response()->json([
                'error' => 'provider_location_mismatch',
                'detail' => 'The provider does not belong to the selected location.',
            ], 422);
        }

        $conflict = $this->findCollision($providerId, $start, $end, $blockedSlot->id);

        if ($conflict) {
            $detail = $conflict['type'] === 'booking'
                ? 'The provider has a booking at this time.'
                : 'This time slot is already blocked for this provider.';

            return response()->json([
                'error' => 'slot_collision',
                'detail' => $detail,
                'conflicts_with' => $conflict,
            ], 409);
        }

        $blockedSlot->update([
            'start_time' => $start,
            'end_time' => $end,
            'reason' => $reason,
            'provider_id' => $providerId,
            'location_id' => $locationId,
        ]);

        return response()->json(['data' => new BlockedSlotResource($blockedSlot)]);
    }

    /**
     * Promotes a single-provider blocked slot to a location-wide block: updates the
     * edited slot in place and creates matching blocks for every other active provider
     * of the location that doesn't already have a conflict at this time.
     */
    private function updateForLocation(BlockedSlot $blockedSlot, Carbon $start, Carbon $end, ?string $reason, int $locationId): JsonResponse
    {
        $providerId = $blockedSlot->provider_id;

        if ($providerId) {
            $conflict = $this->findCollision($providerId, $start, $end, $blockedSlot->id);

            if ($conflict) {
                $detail = $conflict['type'] === 'booking'
                    ? 'The provider has a booking at this time.'
                    : 'This time slot is already blocked for this provider.';

                return response()->json([
                    'error' => 'slot_collision',
                    'detail' => $detail,
                    'conflicts_with' => $conflict,
                ], 409);
            }
        }

        $blockedSlot->update([
            'start_time' => $start,
            'end_time' => $end,
            'reason' => $reason,
            'location_id' => $locationId,
        ]);

        $created = collect();
        $conflicts = [];

        $providers = Provider::where('location_id', $locationId)
            ->where('active', true)
            ->when($providerId, fn ($q) => $q->where('id', '!=', $providerId))
            ->get();

        foreach ($providers as $provider) {
            $conflict = $this->findCollision($provider->id, $start, $end);

            if ($conflict) {
                $conflicts[] = [
                    'provider' => [
                        'id' => $provider->id,
                        'first_name' => $provider->first_name,
                        'last_name' => $provider->last_name,
                    ],
                    'conflict' => $conflict,
                ];

                continue;
            }

            $created->push(BlockedSlot::create([
                'start_time' => $start,
                'end_time' => $end,
                'reason' => $reason,
                'location_id' => $locationId,
                'provider_id' => $provider->id,
            ]));
        }

        return response()->json([
            'data' => new BlockedSlotResource($blockedSlot),
            'created' => BlockedSlotResource::collection($created),
            'conflicts' => $conflicts,
        ]);
    }

    // ── GET /v1/blocked-slots ──────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'provider_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $slots = BlockedSlot::query()
            ->when($request->date_from, fn ($q) => $q->whereDate('start_time', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('end_time', '<=', $request->date_to))
            ->when($request->provider_id, fn ($q) => $q->where('provider_id', $request->provider_id))
            ->when($request->location_id, fn ($q) => $q->where('location_id', $request->location_id))
            ->orderBy('start_time')
            ->get();

        return response()->json(['data' => BlockedSlotResource::collection($slots)]);
    }

    // ── DELETE /v1/blocked-slots/:id ──────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        BlockedSlot::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    // ── DELETE /v1/blocked-slots/group/:repeat_group_id ───────────
    public function destroyGroup(string $repeatGroupId): JsonResponse
    {
        BlockedSlot::where('repeat_group_id', $repeatGroupId)->delete();

        return response()->json(null, 204);
    }

    // ── Occurrence generator ───────────────────────────────────────
    private function generateOccurrences(Carbon $start, int $durationMinutes, array $repeat): array
    {
        $type = $repeat['type'];
        $interval = $repeat['interval'] ?? 1;
        $endType = $repeat['end_type'];
        $maxCount = match ($endType) {
            'after' => (int) $repeat['count'],
            'until' => 730,
            'never' => 104,
        };
        $until = isset($repeat['until']) ? Carbon::parse($repeat['until'])->endOfDay() : null;

        $dates = [];

        if ($type === 'weekly' && ! empty($repeat['days'])) {
            $targetDays = $repeat['days'];
            $weekStart = $start->copy()->startOfWeek(Carbon::SUNDAY);

            while (count($dates) < $maxCount) {
                foreach ($targetDays as $day) {
                    $candidate = $weekStart->copy()->addDays($day);

                    if ($candidate->lt($start->copy()->startOfDay())) {
                        continue;
                    }

                    if ($until && $candidate->gt($until)) {
                        break 2;
                    }

                    $occ = $candidate->copy()->setTimeFrom($start);
                    $dates[] = [$occ, $occ->copy()->addMinutes($durationMinutes)];

                    if (count($dates) >= $maxCount) {
                        break 2;
                    }
                }
                $weekStart->addWeeks($interval);
            }
        } else {
            $current = $start->copy();

            while (count($dates) < $maxCount) {
                if ($until && $current->gt($until)) {
                    break;
                }

                $dates[] = [$current->copy(), $current->copy()->addMinutes($durationMinutes)];

                match ($type) {
                    'daily' => $current->addDays($interval),
                    'monthly' => $current->addMonths($interval),
                    default => $current->addWeeks($interval),
                };
            }
        }

        return $dates;
    }
}
