<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $storageChecks = [
            'logs' => $this->directoryState(storage_path('logs')),
            'cache' => $this->directoryState(storage_path('framework/cache/data')),
            'sessions' => $this->directoryState(storage_path('framework/sessions')),
            'views' => $this->directoryState(storage_path('framework/views')),
            'bootstrap_cache' => $this->directoryState(base_path('bootstrap/cache')),
        ];

        $database = [
            'ok' => false,
            'driver' => config('database.default'),
        ];

        try {
            DB::connection()->getPdo();
            $database['ok'] = true;
        } catch (Throwable) {
            // No exponer host, usuario, contraseña ni mensajes PDO en un endpoint público.
        }

        $filesystemReady = collect($storageChecks)
            ->every(fn (array $state) => $state['exists'] && $state['writable']);

        $ok = $database['ok'] && $filesystemReady;

        return response()->json([
            'ok' => $ok,
            'app' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
            ],
            'database' => $database,
            'filesystem' => $storageChecks,
            'cache' => [
                'default_store' => config('cache.default'),
            ],
            'logging' => [
                'default_channel' => config('logging.default'),
            ],
        ], $ok ? 200 : 503);
    }

    private function directoryState(string $path): array
    {
        return [
            'exists' => is_dir($path),
            'writable' => is_dir($path) && is_writable($path),
        ];
    }
}
