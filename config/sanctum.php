<?php

use Laravel\Sanctum\Sanctum;

return [


    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'zapateriangelita.com,api.zapateriangelita.com',
        Sanctum::currentApplicationUrlWithPort()
    ))),


    'guard' => ['web'],

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 10080),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),


    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
    ],

];
