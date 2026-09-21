<?php

return [

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'payment_service' => [
        'enabled' => filter_var(env('PAYMENT_SERVICE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'url' => rtrim((string) env('PAYMENT_SERVICE_URL', 'http://127.0.0.1:8081'), '/'),
        'shared_secret' => env('PAYMENT_SERVICE_SHARED_SECRET'),
        'timeout' => (int) env('PAYMENT_SERVICE_TIMEOUT', 20),
        'callback_ttl' => (int) env('PAYMENT_SERVICE_CALLBACK_TTL', 300),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
