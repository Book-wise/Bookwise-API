<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class BookingConfirmed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Booking $booking,
    ) {}
}
