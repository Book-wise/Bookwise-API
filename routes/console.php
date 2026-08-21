<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Enviar recordatorios de reserva cada 15 minutos
// ⚠️ IMPORTANTE: requiere CRON en el servidor para que funcione
//     HostGator cPanel → Cron Jobs:
//     * * * * * /usr/local/bin/php /ruta/completa/proyecto/artisan schedule:run >> /dev/null 2>&1
//     Sin esto, los recordatorios NO se enviarán.
Schedule::command('app:send-booking-reminders')->everyFifteenMinutes();
