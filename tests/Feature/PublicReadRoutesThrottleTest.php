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
