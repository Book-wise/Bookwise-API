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
            'start_time'           => ['required', 'date'],
            'end_time'             => ['required', 'date', 'after:start_time'],
            'reason'               => ['nullable', 'string', 'max:255'],
            'scope'                => ['nullable', 'in:all,location,provider'],
            'provider_id'          => ['nullable', 'integer', 'exists:providers,id'],
            'location_id'          => ['nullable', 'integer', 'exists:locations,id'],
            'repeat'               => ['nullable', 'array'],
            'repeat.type'          => ['required_with:repeat', 'in:daily,weekly,monthly'],
            'repeat.interval'      => ['nullable', 'integer', 'min:1'],
            'repeat.days'          => ['nullable', 'array'],
            'repeat.days.*'        => ['integer', 'between:0,6'],
            'repeat.end_type'      => ['required_with:repeat', 'in:after,until,never'],
            'repeat.count'         => ['required_if:repeat.end_type,after', 'integer', 'min:1'],
            'repeat.until'         => ['required_if:repeat.end_type,until', 'date', 'after:start_time'],
        ]);

        $scope      = $data['scope'] ?? null;
        $providerId = $data['provider_id'] ?? null;
        $locationId = $data['location_id'] ?? null;

        // Resolver location_ids finales según scope
        $targetLocationIds = match (true) {
            // scope=all: bloquear TODAS las ubicaciones activas
            $scope === 'all' => \App\Models\Location::where('active', true)->pluck('id')->toArray(),

            // Solo provider_id (sin location_id): inferir location del provider
            $providerId !== null && $locationId === null => $this->resolveLocationIdsFromProvider($providerId),

            // Solo location_id
            $locationId !== null => [$locationId],

            // Sin filtros y sin scope=all → por defecto bloquear todas
            default => \App\Models\Location::where('active', true)->pluck('id')->toArray(),
        };

        if ($targetLocationIds instanceof JsonResponse) {
            return $targetLocationIds;
        }

        // Si no hay locations targets, abortar
        if (empty($targetLocationIds)) {
            return response()->json([
                'error'  => 'no_locations',
                'detail' => 'No se encontraron ubicaciones activas para bloquear.',
            ], 422);
        }

        // Si se spezificó provider_id con scope='all' o location_id, es conflicto
        if ($providerId !== null && $locationId !== null) {
            return response()->json([
                'error'  => 'conflicting_scope',
                'detail' => 'No puede especificar location_id y provider_id al mismo tiempo.',
            ], 422);
        }

        $start    = Carbon::parse($data['start_time']);
        $end      = Carbon::parse($data['end_time']);
        $duration = $start->diffInMinutes($end);

        $occurrences = isset($data['repeat'])
            ? $this->generateOccurrences($start, $duration, $data['repeat'])
            : [[$start, $end]];

        $groupId = count($occurrences) > 1 || count($targetLocationIds) > 1
            ? (string) Str::uuid()
            : null;

        $reason = $data['reason'] ?? null;

        $slots = collect();
        foreach ($targetLocationIds as $locId) {
            foreach ($occurrences as [$occStart, $occEnd]) {
                // Detectar colisión con bloqueos existentes en la misma ubicación
                $blockedCollision = BlockedSlot::where('location_id', $locId)
                    ->where(function ($q) use ($occStart, $occEnd) {
                        $q->where(function ($q) use ($occStart, $occEnd) {
                            $q->where('start_time', '<=', $occStart)
                              ->where('end_time', '>', $occStart);
                        })->orWhere(function ($q) use ($occStart, $occEnd) {
                            $q->where('start_time', '<', $occEnd)
                              ->where('end_time', '>=', $occEnd);
                        })->orWhere(function ($q) use ($occStart, $occEnd) {
                            $q->where('start_time', '>=', $occStart)
                              ->where('end_time', '<=', $occEnd);
                        });
                    })
                    ->first();

                if ($blockedCollision) {
                    return response()->json([
                        'error'  => 'slot_collision',
                        'detail' => "Horario ya bloqueado en esta ubicación.",
                        'conflicts_with' => [
                            'id'        => $blockedCollision->id,
                            'start_time' => $blockedCollision->start_time->toIso8601String(),
                            'end_time'   => $blockedCollision->end_time->toIso8601String(),
                            'reason'     => $blockedCollision->reason,
                            'type'       => 'blocked_slot',
                        ],
                    ], 409);
                }

                // Detectar colisión con reservas existentes en la misma ubicación
                $bookingCollision = \App\Models\Booking::where('location_id', $locId)
                    ->active()
                    ->where(function ($q) use ($occStart, $occEnd) {
                        $q->where(function ($q) use ($occStart, $occEnd) {
                            $q->where('start_time', '<=', $occStart)
                              ->where('end_time', '>', $occStart);
                        })->orWhere(function ($q) use ($occStart, $occEnd) {
                            $q->where('start_time', '<', $occEnd)
                              ->where('end_time', '>=', $occEnd);
                        })->orWhere(function ($q) use ($occStart, $occEnd) {
                            $q->where('start_time', '>=', $occStart)
                              ->where('end_time', '<=', $occEnd);
                        });
                    })
                    ->first();

                if ($bookingCollision) {
                    return response()->json([
                        'error'  => 'slot_collision',
                        'detail' => "Ya existe una reserva en este horario.",
                        'conflicts_with' => [
                            'id'         => $bookingCollision->id,
                            'start_time'  => $bookingCollision->start_time->toIso8601String(),
                            'end_time'    => $bookingCollision->end_time->toIso8601String(),
                            'service'     => $bookingCollision->service?->name,
                            'client'      => $bookingCollision->client?->first_name . ' ' . $bookingCollision->client?->last_name,
                            'type'        => 'booking',
                        ],
                    ], 409);
                }

                $slots->push(BlockedSlot::create([
                    'start_time'     => $occStart,
                    'end_time'       => $occEnd,
                    'reason'         => $reason,
                    'location_id'    => $locId,
                    'provider_id'    => $providerId,
                    'repeat_group_id' => $groupId,
                ]));
            }
        }

        return response()->json(
            ['data' => BlockedSlotResource::collection($slots)],
            201
        );
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
            ->when($request->date_from, fn($q) => $q->whereDate('start_time', '>=', $request->date_from))
            ->when($request->date_to,   fn($q) => $q->whereDate('end_time',   '<=', $request->date_to))
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
            'until' => 730,   // capped, loop exits on date check
            'never' => 104,   // cap: 2 years of weekly slots
        };
        $until = isset($repeat['until']) ? Carbon::parse($repeat['until'])->endOfDay() : null;

        $dates = [];

        if ($type === 'weekly' && ! empty($repeat['days'])) {
            // For weekly + days: expand each occurrence week by the selected weekdays
            $targetDays = $repeat['days'];    // e.g. [1, 3] = Mon, Wed
            $weekStart  = $start->copy()->startOfWeek(Carbon::SUNDAY);

            while (count($dates) < $maxCount) {
                foreach ($targetDays as $day) {
                    $candidate = $weekStart->copy()->addDays($day);

                    // Skip days before the original start_time
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
                    'daily'   => $current->addDays($interval),
                    'monthly' => $current->addMonths($interval),
                    default   => $current->addWeeks($interval),
                };
            }
        }

return $dates;
        }

    // ── Helper: resolver locations desde provider ───────────────────
    private function resolveLocationIdsFromProvider(int $providerId): array
    {
        $provider = \App\Models\Provider::find($providerId);

        if (! $provider) {
            return response()->json([
                'error'  => 'provider_not_found',
                'detail' => 'Proveedor no encontrado.',
            ], 422)->throwResponse();
        }

        if (! $provider->location_id) {
            return response()->json([
                'error'  => 'provider_no_location',
                'detail' => 'El proveedor no tiene ubicación asignada.',
            ], 422)->throwResponse();
        }

        return [$provider->location_id];
    }
}
