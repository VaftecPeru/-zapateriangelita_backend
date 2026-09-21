<?php

$certificationMode = filter_var(
    env('OPENPAY_CERTIFICATION_MODE', false),
    FILTER_VALIDATE_BOOLEAN
);

$sandbox = $certificationMode || filter_var(
    env('OPENPAY_SANDBOX', true),
    FILTER_VALIDATE_BOOLEAN
);

return [
    'merchant_id' => env('OPENPAY_MERCHANT_ID'),
    'public_key' => env('OPENPAY_PUBLIC_KEY'),
    'private_key' => env('OPENPAY_PRIVATE_KEY'),

    // Durante la certificación Openpay debe utilizar las credenciales del
    // ambiente de pruebas. Un único indicador gobierna frontend y backend
    // para evitar mezclar llaves sandbox con api.openpay.mx (error 1002).
    'certification_mode' => $certificationMode,
    'sandbox' => $sandbox,
    'environment' => $sandbox ? 'sandbox' : 'production',

    // La URL se deriva del mismo ambiente utilizado por Openpay.js.
    // No se configura de forma independiente para evitar inconsistencias.
    'base_url' => $sandbox
        ? 'https://sandbox-api.openpay.mx'
        : 'https://api.openpay.mx',

    'currency' => env('OPENPAY_CURRENCY', 'MXN'),

    // Recuperación automática compatible con cron de cPanel.
    'cpanel_reconcile_after_minutes' => max(5, (int) env('OPENPAY_RECONCILE_AFTER_MINUTES', 10)),
    'cpanel_reconcile_limit' => max(1, min(100, (int) env('OPENPAY_RECONCILE_LIMIT', 20))),

    'redirect_url' => env(
        'OPENPAY_REDIRECT_URL',
        'https://zapateriangelita.com/checkout'
    ),
];
