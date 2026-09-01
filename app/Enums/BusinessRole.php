<?php

namespace App\Enums;

/**
 * Business-level roles assigned per tenant via the user_role pivot.
 *
 * These are NOT the technical account type (users.role / UserRole enum) —
 * they are the roles a user holds inside a business (tenant).
 */
enum BusinessRole: string
{
    case ADMIN_GENERAL = 'admin_general';
    case ADMIN_LOCAL = 'admin_local';
    case RECEPCIONISTA = 'recepcionista';
    case RECEPCIONISTA_READONLY = 'recepcionista_readonly';
    case STAFF = 'staff';
    case STAFF_READONLY = 'staff_readonly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
