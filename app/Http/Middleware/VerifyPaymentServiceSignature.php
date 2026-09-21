<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyPaymentServiceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.payment_service.shared_secret');
        $timestamp = (string) $request->header('X-Payment-Timestamp', '');
        $nonce = (string) $request->header('X-Payment-Nonce', '');
        $signature = strtolower((string) $request->header('X-Payment-Signature', ''));

        if ($secret === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return response()->json(['message' => 'Firma de servicio requerida.'], 401);
        }

        if (!ctype_digit($timestamp)) {
            return response()->json(['message' => 'Timestamp inválido.'], 401);
        }

        $ttl = max(60, (int) config('services.payment_service.callback_ttl', 300));
        if (abs(now()->timestamp - (int) $timestamp) > $ttl) {
            return response()->json(['message' => 'Firma expirada.'], 401);
        }

        if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/', $nonce)) {
            return response()->json(['message' => 'Nonce inválido.'], 401);
        }

        $base = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($request->method()),
            $request->path(),
            (string) $request->getContent(),
        ]);
        $expected = hash_hmac('sha256', $base, $secret);

        if (!hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        $nonceKey = 'payment-service-nonce:' . hash('sha256', $nonce);
        if (!Cache::add($nonceKey, true, now()->addSeconds($ttl))) {
            return response()->json(['message' => 'Solicitud repetida.'], 409);
        }

        return $next($request);
    }
}
