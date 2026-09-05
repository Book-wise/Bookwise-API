<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case EFECTIVO = 'efectivo';
    case TRANSFERENCIA = 'transferencia';
    case DEBITO = 'débito';
    case CREDITO = 'crédito';
    case OTRO = 'otro';
    case ONLINE = 'online';

    /**
     * Resolve a legacy or canonical raw value to a canonical enum case.
     *
     * Legacy mapping:
     *   - `tarjeta`     -> CREDITO
     *   - `credit_card` -> ONLINE
     *   - any other non-canonical value -> OTRO
     *   - null          -> null
     *   - canonical     -> itself
     */
    public static function fromLegacy(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return match ($value) {
            'tarjeta' => self::CREDITO,
            'credit_card' => self::ONLINE,
            default => self::tryFrom($value) ?? self::OTRO,
        };
    }
}
