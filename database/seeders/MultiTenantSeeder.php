<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Multi-tenant de prueba (pertenencia dura):
 *  - Tenant 2 "Kinesilk Cordillera" (empresa distinta a la sucursal "Kinesilk Centro").
 *  - Sucursal "Kinesilk Centro" con tenant_id = tenant 2 (pertenencia dura).
 *  - Sucursal 1 renombrada a "Kinesilk - Klga. Beatriz G." (tenant 1) para limpiar artefactos.
 *  - Profesionales del tenant 2 (Lucas + Valeria) con sus usuarios.
 *  - Reservas para esos profesionales.
 *
 * Idempotente (updateOrCreate). Uso:
 *   php artisan db:seed --class=MultiTenantSeeder
 */
class MultiTenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('══════════════════════════════════════════════════════════');
        $this->command->info(' MultiTenantSeeder (pertenencia dura)');
        $this->command->info('══════════════════════════════════════════════════════════');

        // ── Tenant 2 (empresa distinta a la sucursal) ──────────────────────────
        $tenant2 = Tenant::updateOrCreate(
            ['business_rut' => '77.123.456-7'],
            [
                'business_name' => 'Kinesilk Cordillera',
                'business_address' => 'Av. Cordillera 456',
                'business_phone' => '+56 2 2555 6677',
                'business_email' => 'contacto@cordillera.cl',
                'business_plan' => 'professional',
            ],
        );
        $this->command->info("✓ Tenant #{$tenant2->id} {$tenant2->business_name}");

        // ── Sucursal 1 (tenant 1): nombre de sucursal real de Bookwise ─────────
        $loc1 = Location::find(1);
        if ($loc1) {
            $loc1->name = 'Kinesilk Matriz';
            $loc1->tenant_id = 1;
            $loc1->save();
            $this->command->info('✓ Location #1 → Kinesilk Matriz (tenant 1)');
        }

        // ── Sucursal "Kinesilk Centro" del tenant 2 (pertenencia dura) ─────────
        $loc2 = Location::updateOrCreate(
            ['name' => 'Kinesilk Centro'],
            [
                'address' => 'Av. Centro 123',
                'city' => 'Santiago',
                'timezone' => 'America/Santiago',
                'opening_time' => '09:00:00',
                'closing_time' => '19:00:00',
                'active' => true,
                'tenant_id' => $tenant2->id,
            ],
        );
        $this->command->info("✓ Location #{$loc2->id} {$loc2->name} (tenant {$tenant2->id})");

        // ── Profesional 1 del tenant 2: Lucas ──────────────────────────────────
        $providerA = Provider::updateOrCreate(
            ['email' => 'lucas@kinesilk.cl'],
            [
                'first_name' => 'Lucas',
                'last_name' => 'Torres',
                'phone' => '+56 9 7777 7777',
                'location_id' => $loc2->id,
                'active' => true,
            ],
        );
        $userA = User::updateOrCreate(
            ['email' => 'lucas@kinesilk.cl'],
            [
                'name' => 'Lucas Torres',
                'password' => 'password',
                'role' => 'provider',
                'provider_id' => $providerA->id,
                'tenant_id' => $tenant2->id,
            ],
        );
        $userA->forceFill(['email_verified_at' => now()])->save();
        $this->command->info("✓ Provider #{$providerA->id} Lucas (tenant {$tenant2->id})");

        // ── Profesional 2 del tenant 2: Valeria ────────────────────────────────
        $providerB = Provider::updateOrCreate(
            ['email' => 'valeria@kinesilk.cl'],
            [
                'first_name' => 'Valeria',
                'last_name' => 'Rojas',
                'phone' => '+56 9 8888 7766',
                'location_id' => $loc2->id,
                'active' => true,
            ],
        );
        $userB = User::updateOrCreate(
            ['email' => 'valeria@kinesilk.cl'],
            [
                'name' => 'Valeria Rojas',
                'password' => 'password',
                'role' => 'provider',
                'provider_id' => $providerB->id,
                'tenant_id' => $tenant2->id,
            ],
        );
        $userB->forceFill(['email_verified_at' => now()])->save();
        $this->command->info("✓ Provider #{$providerB->id} Valeria (tenant {$tenant2->id})");

        // ── Reservas de esta semana para los profesionales del tenant 2 ────────
        $this->seedBookings($providerA, $providerB, $loc2, $tenant2);

        // ── Roles de negocio ──────────────────────────────────────────────────
        $roleGeneral = Role::where('slug', 'admin_general')->first();
        $roleLocal = Role::where('slug', 'admin_local')->first();

        if ($roleGeneral) {
            $admin = User::where('email', 'admin@kinesilk.cl')->first();
            if ($admin && ! $admin->hasBusinessRole('admin_general')) {
                $admin->roles()->attach($roleGeneral->id, ['tenant_id' => 1]);
                $this->command->info('✓ admin@kinesilk.cl → admin_general');
            }
        }

        if ($roleLocal) {
            $local = User::updateOrCreate(
                ['email' => 'local.cordillera@kinesilk.cl'],
                [
                    'name' => 'Ana Local',
                    'password' => 'password',
                    'role' => 'admin',
                    'tenant_id' => $tenant2->id,
                ],
            );
            $local->forceFill(['email_verified_at' => now()])->save();
            if (! $local->hasBusinessRole('admin_local')) {
                $local->roles()->attach($roleLocal->id, ['tenant_id' => $tenant2->id]);
            }
            $this->command->info("✓ local.cordillera@kinesilk.cl → admin_local (tenant {$tenant2->id})");
        }

        $this->command->info('──────────────────────────────────────────────────────────');
        $this->command->info(' Tenant 2: lucas@kinesilk.cl / valeria@kinesilk.cl / password');
        $this->command->info(' Admin local (tenant 2): local.cordillera@kinesilk.cl / password');
        $this->command->info(' Admin general: admin@kinesilk.cl / password');
        $this->command->info('══════════════════════════════════════════════════════════');
    }

    private function seedBookings(Provider $providerA, Provider $providerB, Location $loc, Tenant $tenant): void
    {
        $weekStart = now()->startOfWeek(Carbon::MONDAY);
        $client = Client::first();
        if (! $client) {
            $this->command->warn('Sin clientes: no se crearon bookings de prueba.');

            return;
        }
        $serviceId = Service::value('id') ?? 1;
        $statusId = BookingStatus::where('name', 'Confirmado')->value('id') ?? 2;

        $slots = [
            [$providerA, $weekStart->copy()->addDays(1), '10:00', '11:30'],
            [$providerA, $weekStart->copy()->addDays(3), '15:00', '16:30'],
            [$providerB, $weekStart->copy()->addDays(2), '09:00', '10:30'],
        ];

        foreach ($slots as [$provider, $day, $start, $end]) {
            $s = $day->copy()->setTimeFromTimeString($start);
            $e = $day->copy()->setTimeFromTimeString($end);

            $exists = Booking::where('provider_id', $provider->id)
                ->whereDate('start_time', $s->toDateString())
                ->exists();

            if ($exists) {
                continue;
            }

            Booking::create([
                'client_id' => $client->id,
                'service_id' => $serviceId,
                'provider_id' => $provider->id,
                'location_id' => $loc->id,
                'status_id' => $statusId,
                'start_time' => $s,
                'end_time' => $e,
                'price' => 40000,
            ]);
        }

        $this->command->info("✓ Bookings creados para profesionales del tenant #{$tenant->id}");
    }
}
