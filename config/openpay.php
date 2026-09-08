<?php

return [
    'merchant_id' => env('OPENPAY_MERCHANT_ID'),
    'public_key' => env('OPENPAY_PUBLIC_KEY'),
    'private_key' => env('OPENPAY_PRIVATE_KEY'),

    'sandbox' => filter_var(
        env('OPENPAY_SANDBOX', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    'base_url' => env(
        'OPENPAY_BASE_URL',
        'https://sandbox-api.openpay.mx'
    ),

    'currency' => env(
        'OPENPAY_CURRENCY',
        'MXN'
    ),
];