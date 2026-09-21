<?php

namespace Tests\Feature;

use Tests\TestCase;

class CheckoutRateLimiterTest extends TestCase
{
    public function test_checkout_rate_limit_returns_a_friendly_message_and_is_isolated_per_endpoint(): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->postJson('/api/checkout/orders', [])
                ->assertStatus(422);
        }

        $this->postJson('/api/checkout/orders', [])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'CHECKOUT_RATE_LIMITED')
            ->assertJsonPath('message', 'Has realizado varios intentos seguidos. Espera unos segundos y vuelve a intentar.');

        // Verificación usa una clave distinta: agotar creación de pedidos no debe
        // bloquear el retorno 3D Secure ni sus consultas de reconciliación.
        $verify = $this->getJson('/api/checkout/payments/openpay/verify');

        $this->assertNotSame(429, $verify->status());
    }
}
