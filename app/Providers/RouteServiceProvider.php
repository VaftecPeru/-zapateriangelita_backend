<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * @var string
     */
    public const HOME = '/home';

    public function boot(): void
    {
        // El checkout usa límites independientes por endpoint. De esta forma
        // los reintentos de verificación 3D Secure no consumen el mismo contador
        // que crear el pedido o iniciar el cobro.
        RateLimiter::for('checkout', function (Request $request) {
            $path = $request->path();
            $identity = (string) ($request->user()?->id ?: $request->ip());

            $attempts = str_ends_with($path, 'checkout/payments/openpay/verify')
                ? 120
                : 30;

            return Limit::perMinute($attempts)
                ->by('checkout|' . $path . '|' . $identity)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Has realizado varios intentos seguidos. Espera unos segundos y vuelve a intentar.',
                        'error_code' => 'CHECKOUT_RATE_LIMITED',
                        'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                    ], 429, $headers);
                });
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/catalog.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
