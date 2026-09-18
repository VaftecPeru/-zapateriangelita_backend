<?php

namespace Tests\Unit;

use Tests\TestCase;

class OpenpayConfigurationTest extends TestCase
{
    public function test_openpay_uses_mexican_pesos_by_default(): void
    {
        $this->assertSame('MXN', strtoupper((string) config('openpay.currency')));
    }

    public function test_checkout_reservation_windows_are_positive(): void
    {
        $this->assertGreaterThanOrEqual(15, (int) config('openpay.reservation_ttl_minutes'));
        $this->assertGreaterThanOrEqual(
            (int) config('openpay.reservation_ttl_minutes'),
            (int) config('openpay.reservation_release_minutes')
        );
    }
}
