<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\SaleTransaction;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class HistoricsDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Idempotent cleanup — hard-delete previous demo run ────────
        $existingClient = Client::withTrashed()->where('email', 'demo.historics@mail.com')->first();
        if ($existingClient) {
            Sale::where('client_id', $existingClient->id)->delete();
            $bookings = Booking::withTrashed()->where('client_id', $existingClient->id)->get();
            foreach ($bookings as $b) {
                $b->statusHistory()->delete();
                $b->forceDelete();
            }
            $existingClient->forceDelete();
            $this->command->info('  - Cleaned up previous demo data');
        }

        // ── Get or create demo client ─────────────────────────────────
        $client = Client::firstOrCreate(
            ['email' => 'demo.historics@mail.com'],
            [
                'first_name' => 'Carolina',
                'last_name' => 'Muñoz',
                'phone' => '+56912345678',
                'active' => true,
            ]
        );

        // ── Get existing entities ──────────────────────────────────────
        $location = Location::first() ?? Location::factory()->create(['name' => 'Kinesilk Centro']);
        $service = Service::where('price', '>', 0)->first() ?? Service::factory()->create(['price' => 35000]);
        $serviceDrenaje = Service::where('name', 'like', '%Drenaje%')->first() ?? $service;
        $serviceKinesio = Service::where('name', 'like', '%Kinesio%')->first() ?? $service;
        $provider = Provider::first() ?? Provider::factory()->create();

        $statuses = BookingStatus::all()->keyBy('id');

        $asisteId = 3;
        $confirmadoId = 2;
        $reservadoId = 1;
        $canceladaId = 7;

        $now = Carbon::now();

        // ── Bookings: historial paciente + reserva ─────────────────────
        $bookingsData = [
            // Booking 1: Asiste (status=3), creado online (webhook)
            [
                'service_id' => $service->id,
                'status_id' => $asisteId,
                'start_time' => (clone $now)->subDays(20)->setTime(10, 0),
                'end_time' => (clone $now)->subDays(20)->setTime(11, 0),
                'price' => 35000,
                'created_via' => 'online_webhook',
                'wc_order_id' => 10001,
            ],
            // Booking 2: Asiste (status=3), creado por agente
            [
                'service_id' => $serviceKinesio->id,
                'status_id' => $asisteId,
                'start_time' => (clone $now)->subDays(15)->setTime(15, 30),
                'end_time' => (clone $now)->subDays(15)->setTime(16, 30),
                'price' => 40000,
                'created_via' => 'agent',
                'wc_order_id' => null,
            ],
            // Booking 3: Asiste (status=3), creado por calendario admin
            [
                'service_id' => $serviceDrenaje->id,
                'status_id' => $asisteId,
                'start_time' => (clone $now)->subDays(10)->setTime(11, 0),
                'end_time' => (clone $now)->subDays(10)->setTime(12, 30),
                'price' => 45000,
                'created_via' => 'admin_calendar',
                'wc_order_id' => null,
            ],
            // Booking 4: Confirmado (pendiente), creado por calendario admin
            [
                'service_id' => $service->id,
                'status_id' => $confirmadoId,
                'start_time' => (clone $now)->addDays(3)->setTime(16, 0),
                'end_time' => (clone $now)->addDays(3)->setTime(17, 0),
                'price' => 35000,
                'created_via' => 'admin_calendar',
                'wc_order_id' => null,
            ],
            // Booking 5: Cancelada, creada online y luego cancelada
            [
                'service_id' => $service->id,
                'status_id' => $canceladaId,
                'start_time' => (clone $now)->subDays(5)->setTime(9, 0),
                'end_time' => (clone $now)->subDays(5)->setTime(10, 0),
                'price' => 35000,
                'created_via' => 'online_webhook',
                'wc_order_id' => 10002,
            ],
        ];

        foreach ($bookingsData as $data) {
            $booking = Booking::create([
                'client_id' => $client->id,
                'service_id' => $data['service_id'],
                'provider_id' => $provider->id,
                'location_id' => $location->id,
                'status_id' => $data['status_id'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'price' => $data['price'],
                'wc_order_id' => $data['wc_order_id'],
                'created_via' => $data['created_via'],
                'last_modified_via' => $data['created_via'],
                'notes' => 'Reserva generada para demo de históricos',
            ]);

            // Add status history
            $booking->statusHistory()->create([
                'status_id' => $data['status_id'],
                'notes' => match ($data['created_via']) {
                    'online_webhook' => 'Confirmada via WooCommerce orden #'.$data['wc_order_id'],
                    'agent' => 'Creada por asistente',
                    default => 'Creada desde calendario',
                },
            ]);

            // ── Create sales for attended bookings (Asiste) ────────────
            if ($data['status_id'] === $asisteId) {
                $isPartial = $data['created_via'] === 'agent'; // Agent booking = partial payment

                $sale = Sale::create([
                    'booking_id' => $booking->id,
                    'client_id' => $client->id,
                    'wc_order_id' => $data['wc_order_id'],
                    'total' => $data['price'],
                    'paid_amount' => $isPartial ? $data['price'] * 0.5 : $data['price'],
                    'payment_method' => $data['wc_order_id'] ? 'online' : 'transferencia',
                    'paid_at' => $data['start_time'],
                ]);

                // First transaction (full or partial payment)
                SaleTransaction::create([
                    'sale_id' => $sale->id,
                    'amount' => $isPartial ? $data['price'] * 0.5 : $data['price'],
                    'payment_method' => $data['wc_order_id'] ? 'online' : 'transferencia',
                    'paid_at' => $data['start_time']->copy()->subDays(1),
                    'notes' => $isPartial
                        ? 'Abono de $'.number_format($data['price'] * 0.5, 0, ',', '.')
                        : 'Pago completo de la sesión',
                ]);

                // Note: no second transaction — keeps paid_amount < total so payment_status = "partial"
                $sale->recalculatePaidAmount();
            }
        }

        $this->command->info("✓ Demo data created for client: {$client->first_name} {$client->last_name} (id={$client->id})");
        $this->command->info('  - '.Booking::where('client_id', $client->id)->count().' bookings');
        $this->command->info('  - '.Sale::where('client_id', $client->id)->count().' sales');
    }
}
