<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicReadRoutesThrottleTest extends TestCase
{
    /**
     * @dataProvider publicReadRoutes
     */
    public function test_public_read_routes_do_not_use_ip_throttle(string $method, string $uri): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($candidate) =>
                in_array($method, $candidate->methods(), true)
                && $candidate->uri() === $uri
            );

        $this->assertNotNull($route, "No se encontró la ruta {$method} {$uri}");

        $middleware = $route->gatherMiddleware();

        $this->assertFalse(
            collect($middleware)->contains(function ($item) {
                $value = (string) $item;

                return str_starts_with($value, 'throttle:')
                    || str_contains($value, 'ThrottleRequests');
            }),
            "{$method} {$uri} no debe depender de throttle por IP compartida."
        );
    }

    /**
     * @dataProvider protectedWriteRoutes
     */
    public function test_sensitive_public_writes_keep_rate_limiting(string $method, string $uri): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($candidate) =>
                in_array($method, $candidate->methods(), true)
                && $candidate->uri() === $uri
            );

        $this->assertNotNull($route, "No se encontró la ruta {$method} {$uri}");

        $middleware = $route->gatherMiddleware();

        $this->assertTrue(
            collect($middleware)->contains(function ($item) {
                $value = (string) $item;

                return str_starts_with($value, 'throttle:')
                    || str_contains($value, 'ThrottleRequests')
                    || $value === 'login.throttle';
            }),
            "{$method} {$uri} debe conservar protección contra abuso."
        );
    }

    public static function protectedWriteRoutes(): array
    {
        return [
            ['POST', 'api/register'],
            ['POST', 'api/login'],
            ['POST', 'api/auth/google/login'],
            ['POST', 'api/forgot-password'],
            ['POST', 'api/reset-password'],
            ['POST', 'api/leads'],
            ['POST', 'api/newsletter/subscribe'],
            ['POST', 'api/interactions'],
            ['POST', 'api/checkout/orders'],
            ['POST', 'api/checkout/payments/openpay/charge'],
        ];
    }

    public static function publicReadRoutes(): array
    {
        return [
            ['GET', 'api/products'],
            ['GET', 'api/products/{product}'],
            ['GET', 'api/services'],
            ['GET', 'api/checkout/delivery-cost'],
            ['GET', 'api/settings'],
            ['GET', 'api/public/categories'],
            ['GET', 'api/public/subcategories'],
            ['GET', 'api/auth/google/config'],
            ['GET', 'api/openpay/config'],
            ['GET', 'api/media/{path}'],
            ['GET', 'api/products/{product}/reviews'],
        ];
    }
}
