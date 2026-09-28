<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Proxies confiables. Por seguridad no se confía en cabeceras reenviadas
     * salvo que el hosting las declare explícitamente mediante TRUSTED_PROXIES.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $configured = trim((string) env('TRUSTED_PROXIES', ''));

        if ($configured === '') {
            $this->proxies = null;
            return;
        }

        if ($configured === '*') {
            $this->proxies = '*';
            return;
        }

        $this->proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', $configured)
        )));
    }
}
