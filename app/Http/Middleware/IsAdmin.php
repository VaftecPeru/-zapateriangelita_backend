<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth('sanctum')->check()) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $user = auth('sanctum')->user();
        
        if (!$user || !in_array($user->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'No tienes permisos para acceder a este recurso'], 403);
        }

        return $next($request);
    }
}
