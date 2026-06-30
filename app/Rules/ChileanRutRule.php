<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ChileanRutRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $value = strtoupper(str_replace(['.', '-', ' '], '', trim($value)));

        if (!preg_match('/^\d{1,8}[K0-9]$/', $value)) {
            $fail('El RUT debe tener formato válido (ej: 12345678-9).');
            return;
        }

        $rut = substr($value, 0, -1);
        $dv  = substr($value, -1);

        if (!is_numeric($rut)) {
            $fail('El RUT debe tener formato válido (ej: 12345678-9).');
            return;
        }

        $calculatedDv = $this->calculateDv($rut);

        if ($dv !== $calculatedDv) {
            $fail('El RUT no es válido.');
        }
    }

    private function calculateDv(string $rut): string
    {
        $rut = (int) $rut;

        $sum = 0;
        $multiplier = 2;

        while ($rut > 0) {
            $digit = $rut % 10;
            $sum += $digit * $multiplier;
            $rut = (int) ($rut / 10);
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        $remainder = $sum % 11;

        if ($remainder === 0) {
            return '0';
        }

        $dv = 11 - $remainder;

        return $dv === 10 ? 'K' : (string) $dv;
    }
}