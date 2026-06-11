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
                    'detail' => 'Solo un administrador puede bloquear horarios para toda una ubicación.',
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
                'detail' => 'Solo podés crear bloqueos para tu propio perfil de profesional.',
            ], 403);
        }

        if ((int) $provider->location_id !== (int) $data['location_id']) {
            return response()->json([
                'error' => 'provider_location_mismatch',
                'detail' => 'El profesional no pertenece a la ubicación seleccionada.',
            ], 422);
        }

        $result = $this->blockProvider($provider, $data, $start, $end, $duration);

        if ($result['status'] === 'conflict') {
            $detail = $result['conflict']['type'] === 'booking'
                ? 'El profesional tiene una reserva en este horario.'
                : 'Horario ya bloqueado para este profesional.';

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
            $blockedCollision = BlockedSlot::where('provider_id', $provider->id)
                ->where(function ($q) use ($occStart, $occEnd) {
                    $q->where('start_time', '<', $occEnd)
                        ->where('end_time', '>', $occStart);
                })
                ->first();

            if ($blockedCollision) {
                return [
                    'status' => 'conflict',
                    'conflict' => [
                        'id' => $blockedCollision->id,
                        'start_time' => $blockedCollision->start_time->toIso8601String(),
                        'end_time' => $blockedCollision->end_time->toIso8601String(),
                        'reason' => $blockedCollision->reason,
                        'type' => 'blocked_slot',
                    ],
                ];
            }

            $bookingCollision = Booking::where('provider_id', $provider->id)
                ->active()
                ->where(function ($q) use ($occStart, $occEnd) {
                    $q->where('start_time', '<', $occEnd)
                        ->where('end_time', '>', $occStart);
                })
                ->first();

            if ($bookingCollision) {
                return [
                    'status' => 'conflict',
                    'conflict' => [
                        'id' => $bookingCollision->id,
                        'start_time' => $bookingCollision->start_time->toIso8601String(),
                        'end_time' => $bookingCollision->end_time->toIso8601String(),
                        'service' => $bookingCollision->service?->name,
                        'client' => $bookingCollision->client?->first_name.' '.$bookingCollision->client?->last_name,
                        'type' => 'booking',
                    ],
                ];
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
