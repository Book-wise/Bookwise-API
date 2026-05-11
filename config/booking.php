<?php

return [
    'default_duration_minutes' => (int) env('BOOKING_DEFAULT_DURATION_MINUTES', 30),
    'slot_interval_minutes'    => (int) env('BOOKING_SLOT_INTERVAL_MINUTES', 30),
    'min_duration_minutes'     => (int) env('BOOKING_MIN_DURATION_MINUTES', 15),
    'max_duration_minutes'     => (int) env('BOOKING_MAX_DURATION_MINUTES', 480),
];
