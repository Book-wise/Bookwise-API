<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN       = 'admin';
    case PROVIDER    = 'provider';
    case CLIENT      = 'client';
    case WOOCOMMERCE = 'woocommerce';
    case AGENT       = 'agent';

    /**
     * Sanctum token abilities assigned on login for each role.
     * Admin gets wildcard — full access.
     * Other roles get a minimal scope set.
     */
    public function tokenAbilities(): array
    {
        return match ($this) {
            self::ADMIN       => ['*'],
            self::PROVIDER    => ['bookings:read', 'bookings:write', 'clients:read'],
            self::WOOCOMMERCE => ['clients:read', 'clients:write', 'bookings:read', 'bookings:write'],
            self::CLIENT      => ['clients:read'],
            self::AGENT       => ['bookings:read', 'clients:read', 'clients:write', 'providers:read'],
        };
    }
}
