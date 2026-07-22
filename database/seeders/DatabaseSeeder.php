<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Demo seeders are restricted to local and testing environments.');

            return;
        }

        $this->call(TestDataSeeder::class);
        $this->call(ThisWeekBookingsSeeder::class);
        $this->call(JuneBookingsSeeder::class);
        $this->call(BookingNotesSeeder::class);
        $this->call(PackBookingsThisWeekSeeder::class);
        $this->call(SalesTransactionsSeeder::class);
        $this->call(PackSessionsScheduleSeeder::class);
        $this->call(EvaluacionInicialSeeder::class);
        $this->call(HistoricsDemoSeeder::class);
        $this->call(PuntaArenasSeeder::class);
    }
}
