<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea o resetea el usuario administrador del backend, asociado al tenant
 * del negocio actual (el que tiene locations, providers y bookings).
 *
 * Uso (idempotente — se puede correr varias veces sin duplicar):
 *   php artisan db:seed --class=AdminUserSeeder
 *
 * Valores por defecto (según convención del equipo):
 *   email    = admin@kinesilk.cl
 *   password = password
 *   tenant   = Bookwise (el negocio con locations, providers y bookings)
 *
 * Overrides opcionales por .env:
 *   ADMIN_EMAIL      = admin@kinesilk.cl
 *   ADMIN_PASSWORD   = password
 *   ADMIN_TENANT_ID  = <id>  (si no se define, busca el tenant Bookwise / el primero)
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@kinesilk.cl');
        $password = env('ADMIN_PASSWORD', 'password');
        $tenantId = env('ADMIN_TENANT_ID');
        $rout = rtrim(env('APP_URL', 'http://127.0.0.1:9999'), '/');

        $this->command->info('══════════════════════════════════════════════════════════');
        $this->command->info(' AdminUserSeeder');
        $this->command->info('══════════════════════════════════════════════════════════');

        // ── Resolver tenant del negocio actual ────────────────────────
        $tenant = $tenantId
            ? Tenant::find($tenantId)
            : Tenant::where('business_name', 'like', '%Bookwise%')->orWhere('id', 1)->first()
                ?? Tenant::first();

        if (! $tenant) {
            $this->command->error('No existe ningún tenant. Creá el negocio primero o definí ADMIN_TENANT_ID.');
            $this->command->info('  Crear tenant: php artisan tinker --execute "App\Models\Tenant::create([\'business_name\' => \'Bookwise SpA\', \'business_rut\' => \'76.123.456-K\']);"');

            return;
        }

        $this->command->info("Tenant destino: #{$tenant->id} {$tenant->business_name}");

        // ── Crear o actualizar admin (idempotente) ────────────────────
        $wasNew = User::where('email', $email)->doesntExist();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin '.$email,
                'password' => $password, // el cast 'hashed' lo cifra solo
                'role' => 'admin',
                'provider_id' => null,
                'tenant_id' => $tenant->id,
            ]
        );

        // (BR7/R8.1) El admin entra sin pasar la gate de verificación.
        // email_verified_at NO es fillable, así que se setea con forceFill
        // en cada corrida (idempotente). Así el admin queda "validado" siempre.
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->command->info($wasNew ? '✓ Admin creado' : '✓ Admin actualizado (password reseteado)');

        // ── Usuario demo SIN email verificado (escenario email_not_verified) ──
        $demoEmail = env('DEMO_UNVERIFIED_EMAIL', 'nuevo@kinesilk.cl');
        $demoPassword = env('DEMO_UNVERIFIED_PASSWORD', 'password');

        $demo = User::updateOrCreate(
            ['email' => $demoEmail],
            [
                'name' => 'Demo sin verificar '.$demoEmail,
                'password' => $demoPassword,
                'role' => 'admin',
                'provider_id' => null,
                'tenant_id' => null,
            ]
        );
        // Forzado a null en cada corrida para que el demo SIEMPRE quede sin verificar.
        $demo->forceFill(['email_verified_at' => null])->save();

        $this->command->info('──────────────────────────────────────────────────────────');
        $this->command->info(' Credenciales — Escenario 1 (verificado → login 200):');
        $this->command->info("   email:    {$user->email}");
        $this->command->info("   password: {$password}");
        $this->command->info('   role:     admin');
        $this->command->info("   tenant:   #{$tenant->id} {$tenant->business_name}");
        $this->command->info('──────────────────────────────────────────────────────────');
        $this->command->info(' Credenciales — Escenario 2 (sin verificar → login 403):');
        $this->command->info("   email:    {$demo->email}");
        $this->command->info("   password: {$demoPassword}");
        $this->command->info('   role:     admin (técnico, igual que register)');
        $this->command->info('   tenant:   null (onboarding_complete=false)');
        $this->command->info('──────────────────────────────────────────────────────────');

        $this->command->info(' Cómo verificar que el seeder se ejecutó:');
        $this->command->info('  1) Esta salida muestra "✓ Admin actualizado" y el tenant destino.');
        $this->command->info('  2) Consultar la base:');
        $this->command->info('     php artisan tinker --execute "');
        $this->command->info("         \$u = App\\Models\\User::where('email', '{$email}')->first();");
        $this->command->info("         echo \$u->role->value, ' | tenant_id: ', \$u->tenant_id;");
        $this->command->info('     "');
        $this->command->info('     Debe imprimir: admin | tenant_id: '.$tenant->id);
        $this->command->info(' Cómo probar los dos escenarios ('.$rout.'):');
        $this->command->info('  1) Login verificado (debe responder 200 con token):');
        $this->command->info("     curl -X POST {$rout}/api/v1/auth/login \\");
        $this->command->info("       -H 'Content-Type: application/json' \\");
        $this->command->info("       -d '{\"email\": \"{$email}\", \"password\": \"{$password}\"}'");
        $this->command->info('  2) Login sin verificar (debe responder 403 email_not_verified):');
        $this->command->info("     curl -X POST {$rout}/api/v1/auth/login \\");
        $this->command->info("       -H 'Content-Type: application/json' \\");
        $this->command->info("       -d '{\"email\": \"{$demoEmail}\", \"password\": \"{$demoPassword}\"}'");
        $this->command->info('══════════════════════════════════════════════════════════');
    }
}
