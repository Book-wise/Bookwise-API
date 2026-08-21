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
        $this->call(RegionSeeder::class);
        $this->call(ComunaSeeder::class);

        $this->call(TestDataSeeder::class);
        $this->call(ThisWeekBookingsSeeder::class);
        $this->call(WeeklyScenariosSeeder::class);
        $this->call(JuneBookingsSeeder::class);
        $this->call(BookingNotesSeeder::class);
        $this->call(PackBookingsThisWeekSeeder::class);
        $this->call(SalesTransactionsSeeder::class);
        $this->call(PackSessionsScheduleSeeder::class);
        $this->call(EvaluacionInicialSeeder::class);
        $this->call(HistoricsDemoSeeder::class);
        $this->call(PuntaArenasSeeder::class);
        $this->call(WeekOfAug17BookingsSeeder::class);
    }
}
