<?php

return [
    // Lista separada por comas de IP/CIDR de reverse proxies confiables.
    // Se deja vacío por defecto para no aceptar X-Forwarded-* de clientes directos.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
];
