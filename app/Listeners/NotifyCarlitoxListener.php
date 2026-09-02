<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\UserRegistered;
use App\Events\UserRequestedPasswordReset;
use App\Jobs\PushNotificationToCarlitox;
use App\Jobs\PushPasswordResetEmailToCarlitox;
use App\Jobs\PushVerificationEmailToCarlitox;
use App\Models\Booking;

class NotifyCarlitoxListener
{
    public function handleUserRegistered(UserRegistered $event): void
    {
        PushVerificationEmailToCarlitox::dispatch(
            $event->user,
            $event->verification,
            $event->plainToken,
        );
    }

    public function handleUserRequestedPasswordReset(UserRequestedPasswordReset $event): void
    {
        PushPasswordResetEmailToCarlitox::dispatch(
            $event->user,
            $event->token,
            $event->plainToken,
        );
    }

    public function handleBookingCreated(BookingCreated $event): void
    {
        $this->dispatchFor($event->booking, PushNotificationToCarlitox::EVENT_BOOKING_CREATED);
    }

    public function handleBookingConfirmed(BookingConfirmed $event): void
    {
        $this->dispatchFor($event->booking, PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED);
    }

    public function handleBookingCancelled(BookingCancelled $event): void
    {
        $this->dispatchFor($event->booking, PushNotificationToCarlitox::EVENT_BOOKING_CANCELLED);
    }

    /**
     * Resolve the per-client flag matrix and dispatch one push job per enabled channel.
     */
    private function dispatchFor(Booking $booking, string $event): void
    {
        $booking->loadMissing('client');

        $client = $booking->client;

        if (! $client || ! $client->notifications_enabled) {
            return;
        }

        foreach ($this->channelsFor($event) as $channel => $flag) {
            if ($client->{$flag}) {
                PushNotificationToCarlitox::dispatch($booking, $event, $channel);
            }
        }
    }

    /**
     * Map an event to its enabled channel/flag combinations.
     *
     * @return array<string, string>
     */
    private function channelsFor(string $event): array
    {
        return match ($event) {
            PushNotificationToCarlitox::EVENT_BOOKING_CREATED => [
                PushNotificationToCarlitox::CHANNEL_EMAIL => 'email_new_booking',
            ],
            PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED => [
                PushNotificationToCarlitox::CHANNEL_EMAIL => 'email_booking_confirmation',
            ],
            PushNotificationToCarlitox::EVENT_BOOKING_CANCELLED => [
                PushNotificationToCarlitox::CHANNEL_EMAIL => 'email_booking_cancellation',
                PushNotificationToCarlitox::CHANNEL_WHATSAPP => 'whatsapp_cancellation_confirmation',
            ],
            default => [],
        };
    }
}
