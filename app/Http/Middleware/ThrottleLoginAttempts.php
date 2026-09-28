<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleLoginAttempts
{
    private const PAIR_MAX_ATTEMPTS = 8;
    private const EMAIL_MAX_ATTEMPTS = 20;
    private const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $limits = $this->limits($request);

        $retryAfter = 0;
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit['key'], $limit['max'])) {
                $retryAfter = max($retryAfter, $this->limiter->availableIn($limit['key']));
            }
        }

        if ($retryAfter > 0) {
            return response()->json([
                'message' => 'Se realizaron varios intentos de inicio de sesión. Espera unos segundos y vuelve a intentar.',
                'error_code' => 'LOGIN_RATE_LIMITED',
                'retry_after' => $retryAfter,
            ], 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) self::PAIR_MAX_ATTEMPTS,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $response = $next($request);

        // Solo las credenciales rechazadas consumen el contador. Un login exitoso
        // limpia su propio contador para no penalizar al cliente por uso legítimo.
        if ($response->getStatusCode() === 401) {
            foreach ($limits as $limit) {
                $this->limiter->hit($limit['key'], self::DECAY_SECONDS);
            }

            $remaining = max(
                0,
                self::PAIR_MAX_ATTEMPTS - $this->limiter->attempts($limits[0]['key'])
            );

            $response->headers->set('X-RateLimit-Limit', (string) self::PAIR_MAX_ATTEMPTS);
            $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        } elseif ($response->isSuccessful()) {
            foreach ($limits as $limit) {
                $this->limiter->clear($limit['key']);
            }
        }

        return $response;
    }

    private function limits(Request $request): array
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $normalizedEmail = $email !== '' ? $email : 'missing-email';
        $ip = (string) ($request->ip() ?: 'unknown-ip');

        return [
            [
                'key' => 'login:pair:' . hash('sha256', $normalizedEmail . '|' . $ip),
                'max' => self::PAIR_MAX_ATTEMPTS,
            ],
            [
                'key' => 'login:email:' . hash('sha256', $normalizedEmail),
                'max' => self::EMAIL_MAX_ATTEMPTS,
            ],
        ];
    }
}
