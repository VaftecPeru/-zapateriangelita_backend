<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CompressResponse
{
    /**
     * Comprimir respuestas para reducir tamaño de transferencia
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // ✅ Gzip compresión automática si el cliente lo soporta
        if (
            stripos($request->headers->get('Accept-Encoding'), 'gzip') !== false &&
            extension_loaded('zlib')
        ) {
            $response->header('Content-Encoding', 'gzip');
        }

        return $response;
    }
}
