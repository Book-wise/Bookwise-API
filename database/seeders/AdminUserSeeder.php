<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Crea o resetea el usuario administrador del backend, asociado al tenant
 * del negocio actual (el que tiene locations, providers y bookings).
 *
 * Uso (idempotente — se puede correr varias veces sin duplicar):
 *   php artisan db:seed --class=AdminUserSeeder
 *
 * Configurable por .env (opcional):
 *   ADMIN_EMAIL    = admin@kinesilk.cl   (email del admin)
 *   ADMIN_PASSWORD = <password>          (si no se define, genera uno aleatorio y lo imprime)
 *   ADMIN_TENANT_ID = <id>               (si no se define, usa el primer tenant existente)
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@kinesilk.cl');
        $password = env('ADMIN_PASSWORD', Str::password(16)); // 16 chars, mayúscula+número+símbolo
        $tenantId = env('ADMIN_TENANT_ID');

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

        $this->command->info($wasNew ? '✓ Admin creado' : '✓ Admin actualizado (password reseteado)');
        $this->command->info('──────────────────────────────────────────────────────────');
        $this->command->info(' Credenciales:');
        $this->command->info("   email:    {$user->email}");
        $this->command->info("   password: {$password}");
        $this->command->info('   role:     admin');
        $this->command->info("   tenant:   #{$tenant->id} {$tenant->business_name}");
        $this->command->info('──────────────────────────────────────────────────────────');

        $this->command->info(' Cómo verificar que el seeder se ejecutó:');
        $this->command->info('  1) Esta salida muestra "✓ Admin actualizado" y el tenant destino.');
        $this->command->info('  2) Consultar la base:');
        $this->command->info('     php artisan tinker --execute "');
        $this->command->info("         \$u = App\\Models\\User::where('email', '{$email}')->first();");
        $this->command->info("         echo \$u->role->value, ' | tenant_id: ', \$u->tenant_id;");
        $this->command->info('     "');
        $this->command->info('     Debe imprimir: admin | tenant_id: '.$tenant->id);
        $this->command->info('  3) Probar login real:');
        $this->command->info('     curl -X POST '.rtrim(env('APP_URL', 'http://127.0.0.1:9999'), '/').'/api/v1/login \\');
        $this->command->info("       -H 'Content-Type: application/json' \\");
        $this->command->info("       -d '{\"email\": \"{$email}\", \"password\": \"{$password}\"}'");
        $this->command->info('     Debe responder 200 con {"token": "...", "user": {...}}.');
        $this->command->info('══════════════════════════════════════════════════════════');
    }
}
