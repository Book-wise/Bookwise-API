<?php

namespace App\Console\Commands;

use App\Mail\BookingReminder;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('app:send-booking-reminders')]
#[Description('Send email reminders 24h and 30min before bookings')]
class SendBookingReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        $this->send24hReminders($now);
        $this->send30mReminders($now);

        return Command::SUCCESS;
    }

    private function send24hReminders(Carbon $now): void
    {
        $target = $now->copy()->addHours(24);
        $windowStart = $target->copy()->subMinutes(15);

        $bookings = Booking::with(['client', 'provider', 'location', 'service'])
            ->whereBetween('start_time', [$windowStart, $target])
            ->whereNull('reminder_24h_sent_at')
            ->active()
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($bookings as $booking) {
            if (! $this->canNotify($booking)) {
                $skipped++;

                continue;
            }

            Mail::to($booking->client->email)->send(new BookingReminder($booking, '24h'));
            $booking->update(['reminder_24h_sent_at' => $now]);
            $sent++;
        }

        $this->line("Recordatorios 24h: {$sent} enviados, {$skipped} saltados");
    }

    private function send30mReminders(Carbon $now): void
    {
        $target = $now->copy()->addMinutes(30);
        $windowStart = $target->copy()->subMinutes(5);

        $bookings = Booking::with(['client', 'provider', 'location', 'service'])
            ->whereBetween('start_time', [$windowStart, $target])
            ->whereNull('reminder_30m_sent_at')
            ->active()
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($bookings as $booking) {
            if (! $this->canNotify($booking)) {
                $skipped++;

                continue;
            }

            Mail::to($booking->client->email)->send(new BookingReminder($booking, '30m'));
            $booking->update(['reminder_30m_sent_at' => $now]);
            $sent++;
        }

        $this->line("Recordatorios 30min: {$sent} enviados, {$skipped} saltados");
    }

    private function canNotify(Booking $booking): bool
    {
        return $booking->client
            && $booking->client->notifications_enabled
            && $booking->client->email;
    }
}
