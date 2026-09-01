<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Polling contract for carlitox: due WhatsApp reminders.
     *
     * Never marks reminders as sent — only the ack endpoint does (D2).
     */
    public function pending(Request $request): JsonResponse
    {
        $request->validate([
            'channel' => ['required', 'string', 'in:whatsapp'],
            'type' => ['required', 'string', 'in:reminder'],
        ]);

        $now = Carbon::now();

        $win24hStart = $now->copy()->addHours(24)->subMinutes(15);
        $win24hEnd = $now->copy()->addHours(24);
        $win30mStart = $now->copy()->addMinutes(30)->subMinutes(5);
        $win30mEnd = $now->copy()->addMinutes(30);

        $bookings = Booking::query()
            ->where(fn ($q) => $q
                ->where(fn ($q2) => $q2
                    ->whereBetween('start_time', [$win24hStart, $win24hEnd])
                    ->whereNull('reminder_24h_sent_at'))
                ->orWhere(fn ($q3) => $q3
                    ->whereBetween('start_time', [$win30mStart, $win30mEnd])
                    ->whereNull('reminder_30m_sent_at')))
            ->active()
            ->whereHas('client', fn ($q) => $q
                ->where('notifications_enabled', true)
                ->where('whatsapp_reminder', true))
            ->with(['client'])
            ->orderBy('start_time', 'asc')
            ->get();

        $data = $bookings->map(function (Booking $booking) use ($win24hStart, $win24hEnd) {
            $reminderType = $booking->start_time->between($win24hStart, $win24hEnd) ? '24h' : '30m';

            return [
                'booking_id' => $booking->id,
                'start_time' => $booking->start_time->toIso8601String(),
                'reminder_type' => $reminderType,
                'client' => [
                    'id' => $booking->client->id,
                    'first_name' => $booking->client->first_name,
                    'last_name' => $booking->client->last_name,
                    'phone' => $booking->client->phone,
                ],
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /**
     * Explicit ack from carlitox: mark a reminder as sent (idempotent).
     */
    public function ack(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer'],
            'reminder_type' => ['required', 'string', 'in:24h,30m'],
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);

        $column = $validated['reminder_type'] === '24h'
            ? 'reminder_24h_sent_at'
            : 'reminder_30m_sent_at';

        if ($booking->{$column} === null) {
            $booking->update([$column => now()]);
        }

        return response()->json([
            'data' => [
                'booking_id' => $booking->id,
                'reminder_type' => $validated['reminder_type'],
                'sent_at' => $booking->{$column}->toIso8601String(),
            ],
        ]);
    }
}
