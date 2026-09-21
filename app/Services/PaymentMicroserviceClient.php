<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymentMicroserviceClient
{
    public function enabled(): bool
    {
        return (bool) config('services.payment_service.enabled');
    }

    public function charge(array $payload): array
    {
        return $this->request('post', '/api/v1/payments/charge', $payload);
    }

    public function verify(array $payload): array
    {
        return $this->request('post', '/api/v1/payments/verify', $payload);
    }

    private function request(string $method, string $path, array $payload): array
    {
        $baseUrl = rtrim((string) config('services.payment_service.url'), '/');
        $secret = (string) config('services.payment_service.shared_secret');
        $timeout = max(5, (int) config('services.payment_service.timeout', 20));

        if ($baseUrl === '' || $secret === '') {
            throw new RuntimeException('Payment microservice is enabled but not configured.');
        }

        /** @var Response $response */
        $response = Http::connectTimeout(5)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Internal-Token' => $secret,
                'X-Request-Source' => 'angelita-laravel',
            ])
           ->{$method}($baseUrl . $path, $payload);

        $data = $response->json();
        if (!is_array($data)) {
            $data = [
                'ok' => false,
                'payment_status' => 'processing',
                'message' => 'El servicio de pagos devolvió una respuesta inesperada.',
            ];
        }

        return [
            'status' => $response->status(),
            'data' => $data,
        ];
    }
}
