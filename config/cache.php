<?php

use Illuminate\Support\Str;

$cpanelFileCachePath = storage_path('framework/cache/data');
$configuredCacheStore = env('CACHE_STORE', env('CACHE_DRIVER', 'file'));

// En hosting compartido un deploy ZIP puede dejar storage/framework sin permisos
// o sin la carpeta data. El throttle de Laravel usa el cache y, si el store file
// no puede escribir, todas las rutas públicas pueden responder 500. En ese caso
// degradamos temporalmente a memoria para mantener la API disponible.
if (
    $configuredCacheStore === 'file'
    && (!is_dir($cpanelFileCachePath) || !is_writable($cpanelFileCachePath))
) {
    $configuredCacheStore = 'array';
}

return [

    'default' => $configuredCacheStore,

    'stores' => [

        'apc' => [
            'driver' => 'apc',
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
            'lock_connection' => null,
        ],

        'file' => [
            'driver' => 'file',
            'path' => $cpanelFileCachePath,
            'lock_path' => $cpanelFileCachePath,
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

    ],

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_cache_'),

];
