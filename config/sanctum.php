<?php

use Laravel\Sanctum\Sanctum;

return [


    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'zapateriangelita.com,api.zapateriangelita.com',
        Sanctum::currentApplicationUrlWithPort()
    ))),


    'guard' => ['web'],

    'expiration' => (int) env('SANCTUM_EXPIRATION_MINUTES', 10080),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),


    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
