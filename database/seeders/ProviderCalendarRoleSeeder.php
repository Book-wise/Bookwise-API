<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Asigna los roles de asistencia a calendario (staff / staff_readonly) a los
 * profesionales reales del tenant de negocio (Bookwise SpA, tenant 1), usando
 * un mapa determinista email → rol.
 *
 * Uso (idempotente — se puede correr varias veces sin duplicar):
 *   php artisan db:seed --class=ProviderCalendarRoleSeeder
 *
 * Precondición: RoleSeeder debe haberse ejecutado antes (los roles staff y
 * staff_readonly deben existir). No crea ni modifica usuarios ni profesionales:
 * cada email se resuelve contra un provider cuyo linked user tiene el MISMO
 * email; cualquier cuenta sin esa coincidencia (junk) se omite y se reporta.
 * NO está registrado en DatabaseSeeder: se ejecuta explícitamente sobre el
 * tenant del negocio (ver AdminUserSeeder para el override ADMIN_TENANT_ID).
 */
class ProviderCalendarRoleSeeder extends Seeder
{
    /**
     * Mapa email del profesional real → slug del rol de asistencia (tenant del negocio).
     *
     * Providers 1-9 (María…Claudia) atienden y gestionan su agenda → staff;
     * provider 10 (Francisco) aparece en el calendario en solo lectura →
     * staff_readonly. Los providers junk 11/12 (P A / P B) y el usuario
     * duplicado de factory (royce.langosh@example.org) NO están en el mapa.
     *
     * @var array<string, string>
     */
    public const EMAIL_ROLE_MAP = [
        'maria@kinesilk.cl' => 'staff',
        'carmen@kinesilk.cl' => 'staff',
        'jorge@kinesilk.cl' => 'staff',
        'carlos@kinesilk.cl' => 'staff',
        'pilar@kinesilk.cl' => 'staff',
        'sebastian@kinesilk.cl' => 'staff',
        'ana@kinesilk.cl' => 'staff',
        'diego@kinesilk.cl' => 'staff',
        'claudia@kinesilk.cl' => 'staff',
        'francisco@kinesilk.cl' => 'staff_readonly',
    ];

    public function run(): void
    {
        $this->command->info('══════════════════════════════════════════════════════════');
        $this->command->info(' ProviderCalendarRoleSeeder');
        $this->command->info('══════════════════════════════════════════════════════════');

        // ── Resolver tenant del negocio (paridad AdminUserSeeder) ────────
        $tenant = $this->resolveTenant();

        if (! $tenant) {
            $this->command->error('No existe ningún tenant. Defina ADMIN_TENANT_ID o cree primero el negocio.');

            return;
        }

        $this->command->info("Tenant destino: #{$tenant->id} {$tenant->business_name}");

        // ── Precondición RoleSeeder: todos los roles del mapa deben existir ──
        $missingRoles = $this->missingRoles();

        if ($missingRoles !== []) {
            $this->command->error('Faltan roles del RoleSeeder: '.implode(', ', $missingRoles).'.');
            $this->command->error('Ejecute primero: php artisan db:seed --class=RoleSeeder');

            return;
        }

        // ── Asignación idempotente por email (replace por user + tenant) ──
        $assigned = 0;
        $skipped = [];

        foreach (self::EMAIL_ROLE_MAP as $email => $roleSlug) {
            $provider = Provider::where('email', $email)->first();

            if (! $provider) {
                $skipped[] = "{$email}: no existe provider con ese email";

                continue;
            }

            // Solo el usuario real cuyo email coincide con el del provider
            // (nunca el usuario duplicado de factory del mismo provider).
            $user = User::where('provider_id', $provider->id)
                ->where('email', $provider->email)
                ->first();

            if (! $user) {
                $skipped[] = "{$email}: no hay usuario real con email coincidente";

                continue;
            }

            $role = Role::where('slug', $roleSlug)->first();

            // Replace semantics per (user, tenant): detach pivots del tenant y
            // attach del rol mapeado — re-correr no duplica ni acumula.
            $user->roles()->wherePivot('tenant_id', $tenant->id)->detach();
            $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

            $assigned++;

            $this->command->info("  ✓ {$email} → {$roleSlug} (user #{$user->id})");
        }

        $this->command->info('──────────────────────────────────────────────────────────');

        if ($skipped !== []) {
            $this->command->info(' Omitidos (sin cambios):');
            foreach ($skipped as $reason) {
                $this->command->info("  ⏭ {$reason}");
            }
        }

        $this->command->info(" Resumen: {$assigned} asignado(s), ".count($skipped).' omitido(s) bajo tenant #'.$tenant->id);
        $this->command->info('══════════════════════════════════════════════════════════');
    }

    /**
     * Resolver el tenant del negocio igual que AdminUserSeeder: override por
     * env ADMIN_TENANT_ID; si no, busca el tenant Bookwise (o el id 1).
     */
    private function resolveTenant(): ?Tenant
    {
        $tenantId = env('ADMIN_TENANT_ID');

        return $tenantId
            ? Tenant::find($tenantId)
            : Tenant::where('business_name', 'like', '%Bookwise%')->orWhere('id', 1)->first()
                ?? Tenant::first();
    }

    /**
     * Roles del mapa que no existen en la tabla roles (precondición RoleSeeder).
     *
     * @return list<string>
     */
    private function missingRoles(): array
    {
        $slugs = array_values(array_unique(self::EMAIL_ROLE_MAP));
        $existing = Role::whereIn('slug', $slugs)->pluck('slug')->all();

        return array_values(array_diff($slugs, $existing));
    }
}
