<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class OpenpayRouteContractTest extends TestCase
{
    public function test_checkout_routes_and_reconciled_webhook_are_registered_in_source(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../routes/api.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString("/checkout/orders", $routes);
        $this->assertStringContainsString("/checkout/payments/openpay/charge", $routes);
        $this->assertStringContainsString("/checkout/payments/openpay/verify", $routes);
        $this->assertStringContainsString("CheckoutController::class", $routes);
        $this->assertStringContainsString("'webhook'", $routes);
    }

    public function test_admin_routes_remain_behind_auth_and_admin_middleware(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../routes/api.php');

        $this->assertStringContainsString("Route::middleware('auth:sanctum')->group", $routes);
        $this->assertStringContainsString("Route::middleware('admin')->group", $routes);
    }
}
