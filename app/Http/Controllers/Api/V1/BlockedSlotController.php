<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BlockedSlotResource;
use App\Models\BlockedSlot;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlockedSlotController extends Controller
{
    // ── POST /v1/blocked-slots ─────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_time'      => ['required', 'date'],
            'end_time'        => ['required', 'date', 'after:start_time'],
            'reason'          => ['nullable', 'string', 'max:255'],
            'provider_id'     => ['required', 'integer', 'exists:providers,id'],
            'location_id'     => ['required', 'integer', 'exists:locations,id'],
            'repeat'          => ['nullable', 'array'],
            'repeat.type'     => ['required_with:repeat', 'in:daily,weekly,monthly'],
            'repeat.interval' => ['nullable', 'integer', 'min:1'],
            'repeat.days'     => ['nullable', 'array'],
            'repeat.days.*'   => ['integer', 'between:0,6'],
            'repeat.end_type' => ['required_with:repeat', 'in:after,until,never'],
            'repeat.count'    => ['required_if:repeat.end_type,after', 'integer', 'min:1'],
            'repeat.until'    => ['required_if:repeat.end_type,until', 'date', 'after:start_time'],
        ]);

        $provider = \App\Models\Provider::findOrFail($data['provider_id']);

        if ((int) $provider->location_id !== (int) $data['location_id']) {
            return response()->json([
                'error'  => 'provider_location_mismatch',
                'detail' => 'El profesional no pertenece a la ubicación seleccionada.',
            ], 422);
        }

        $start    = Carbon::parse($data['start_time']);
        $end      = Carbon::parse($data['end_time']);
        $duration = $start->diffInMinutes($end);

        $occurrences = isset($data['repeat'])
            ? $this->generateOccurrences($start, $duration, $data['repeat'])
            : [[$start, $end]];

        $groupId = count($occurrences) > 1 ? (string) Str::uuid() : null;

        $slots = collect();
        foreach ($occurrences as [$occStart, $occEnd]) {
            $blockedCollision = BlockedSlot::where('provider_id', $data['provider_id'])
                ->where(function ($q) use ($occStart, $occEnd) {
                    $q->where('start_time', '<', $occEnd)
                      ->where('end_time', '>', $occStart);
                })
                ->first();

            if ($blockedCollision) {
                return response()->json([
                    'error'          => 'slot_collision',
                    'detail'         => 'Horario ya bloqueado para este profesional.',
                    'conflicts_with' => [
                        'id'         => $blockedCollision->id,
                        'start_time' => $blockedCollision->start_time->toIso8601String(),
                        'end_time'   => $blockedCollision->end_time->toIso8601String(),
                        'reason'     => $blockedCollision->reason,
                        'type'       => 'blocked_slot',
                    ],
                ], 409);
            }

            $bookingCollision = \App\Models\Booking::where('provider_id', $data['provider_id'])
                ->active()
                ->where(function ($q) use ($occStart, $occEnd) {
                    $q->where('start_time', '<', $occEnd)
                      ->where('end_time', '>', $occStart);
                })
                ->first();

            if ($bookingCollision) {
                return response()->json([
                    'error'          => 'slot_collision',
                    'detail'         => 'El profesional tiene una reserva en este horario.',
                    'conflicts_with' => [
                        'id'         => $bookingCollision->id,
                        'start_time' => $bookingCollision->start_time->toIso8601String(),
                        'end_time'   => $bookingCollision->end_time->toIso8601String(),
                        'service'    => $bookingCollision->service?->name,
                        'client'     => $bookingCollision->client?->first_name . ' ' . $bookingCollision->client?->last_name,
                        'type'       => 'booking',
                    ],
                ], 409);
            }

            $slots->push(BlockedSlot::create([
                'start_time'      => $occStart,
                'end_time'        => $occEnd,
                'reason'          => $data['reason'] ?? null,
                'location_id'     => $data['location_id'],
                'provider_id'     => $data['provider_id'],
                'repeat_group_id' => $groupId,
            ]));
        }

        return response()->json(['data' => BlockedSlotResource::collection($slots)], 201);
    }

    // ── GET /v1/blocked-slots ──────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date'],
            'provider_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $slots = BlockedSlot::query()
            ->when($request->date_from,   fn($q) => $q->whereDate('start_time', '>=', $request->date_from))
            ->when($request->date_to,     fn($q) => $q->whereDate('end_time',   '<=', $request->date_to))
            ->when($request->provider_id, fn($q) => $q->where('provider_id', $request->provider_id))
            ->when($request->location_id, fn($q) => $q->where('location_id', $request->location_id))
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
        $type     = $repeat['type'];
        $interval = $repeat['interval'] ?? 1;
        $endType  = $repeat['end_type'];
        $maxCount = match ($endType) {
            'after' => (int) $repeat['count'],
            'until' => 730,
            'never' => 104,
        };
        $until = isset($repeat['until']) ? Carbon::parse($repeat['until'])->endOfDay() : null;

        $dates = [];

        if ($type === 'weekly' && ! empty($repeat['days'])) {
            $targetDays = $repeat['days'];
            $weekStart  = $start->copy()->startOfWeek(Carbon::SUNDAY);

            while (count($dates) < $maxCount) {
                foreach ($targetDays as $day) {
                    $candidate = $weekStart->copy()->addDays($day);

                    if ($candidate->lt($start->copy()->startOfDay())) {
                        continue;
                    }

                    if ($until && $candidate->gt($until)) {
                        break 2;
                    }

                    $occ     = $candidate->copy()->setTimeFrom($start);
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
                    'daily'   => $current->addDays($interval),
                    'monthly' => $current->addMonths($interval),
                    default   => $current->addWeeks($interval),
                };
            }
        }

        return $dates;
    }
}
