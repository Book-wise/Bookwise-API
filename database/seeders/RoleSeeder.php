<?php

namespace Database\Seeders;

use App\Enums\BusinessRole;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the 6 business roles (R3.2). Idempotent: updateOrCreate by slug,
 * so re-running never duplicates roles (S29). Only ADDS the new business
 * roles — never touches existing users or the users.role technical enum.
 */
class RoleSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const DISPLAY_NAMES = [
        'admin_general' => 'Administrador General',
        'admin_local' => 'Administrador Local',
        'recepcionista' => 'Recepcionista',
        'recepcionista_readonly' => 'Recepcionista (solo lectura)',
        'staff' => 'Staff',
        'staff_readonly' => 'Staff (solo lectura)',
    ];

    public function run(): void
    {
        foreach (BusinessRole::cases() as $role) {
            Role::updateOrCreate(
                ['slug' => $role->value],
                ['name' => self::DISPLAY_NAMES[$role->value]],
            );
        }
    }
}
