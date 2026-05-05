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

        $start    = Carbon::parse($data['start_time']);
        $end      = Carbon::parse($data['end_time']);
        $duration = $start->diffInMinutes($end);

        $occurrences = isset($data['repeat'])
            ? $this->generateOccurrences($start, $duration, $data['repeat'])
            : [[$start, $end]];

        $groupId = count($occurrences) > 1 ? (string) Str::uuid() : null;

        $base = [
            'provider_id'     => $data['provider_id'] ?? null,
            'location_id'     => $data['location_id'] ?? null,
            'reason'          => $data['reason'] ?? null,
            'repeat_group_id' => $groupId,
        ];

        $slots = collect($occurrences)->map(function (array $pair) use ($base) {
            [$occStart, $occEnd] = $pair;
            return BlockedSlot::create(array_merge($base, [
                'start_time' => $occStart,
                'end_time'   => $occEnd,
            ]));
        });

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
}
