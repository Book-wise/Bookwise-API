<?php

namespace App\Enums;

enum BookingSource: string
{
    case AdminCalendar = 'admin_calendar';
    case Agent = 'agent';
    case OnlineWebhook = 'online_webhook';
}
