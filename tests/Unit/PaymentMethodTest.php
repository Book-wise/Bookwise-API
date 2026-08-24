<?php

namespace Tests\Unit;

use App\Enums\PaymentMethod;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    public function test_canonical_cases_match_specification(): void
    {
        $this->assertSame(
            ['efectivo', 'transferencia', 'débito', 'crédito', 'otro', 'online'],
            array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::cases())
        );
    }

    public function test_from_legacy_returns_null_for_null_input(): void
    {
        $this->assertNull(PaymentMethod::fromLegacy(null));
    }

    public function test_from_legacy_maps_tarjeta_to_credito(): void
    {
        $this->assertSame(PaymentMethod::CREDITO, PaymentMethod::fromLegacy('tarjeta'));
    }

    public function test_from_legacy_maps_credit_card_to_online(): void
    {
        $this->assertSame(PaymentMethod::ONLINE, PaymentMethod::fromLegacy('credit_card'));
    }

    public function test_from_legacy_maps_unknown_values_to_otro(): void
    {
        $this->assertSame(PaymentMethod::OTRO, PaymentMethod::fromLegacy('cheque'));
        $this->assertSame(PaymentMethod::OTRO, PaymentMethod::fromLegacy('bitcoin'));
        $this->assertSame(PaymentMethod::OTRO, PaymentMethod::fromLegacy(''));
    }

    public function test_from_legacy_preserves_canonical_values(): void
    {
        $this->assertSame(PaymentMethod::EFECTIVO, PaymentMethod::fromLegacy('efectivo'));
        $this->assertSame(PaymentMethod::TRANSFERENCIA, PaymentMethod::fromLegacy('transferencia'));
        $this->assertSame(PaymentMethod::DEBITO, PaymentMethod::fromLegacy('débito'));
        $this->assertSame(PaymentMethod::CREDITO, PaymentMethod::fromLegacy('crédito'));
        $this->assertSame(PaymentMethod::OTRO, PaymentMethod::fromLegacy('otro'));
        $this->assertSame(PaymentMethod::ONLINE, PaymentMethod::fromLegacy('online'));
    }
}
