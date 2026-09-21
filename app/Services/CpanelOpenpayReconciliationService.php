<?php

namespace App\Services;

use App\Http\Controllers\CheckoutController;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CpanelOpenpayReconciliationService
{
    public function reconcile(Order $order): string
    {
        $order->refresh();

        if ($order->payment_status !== 'processing') {
            return 'skipped';
        }

        if (app(PaymentMicroserviceClient::class)->enabled()) {
            return 'skipped_microservice';
        }

        $merchantId = trim((string) config('openpay.merchant_id'));
        $privateKey = trim((string) config('openpay.private_key'));
        $baseUrl = rtrim((string) config('openpay.base_url'), '/');
        $reference = trim((string) $order->payment_reference);

        if ($merchantId === '' || $privateKey === '' || $baseUrl === '') {
            Log::error('CPANEL_OPENPAY_RECONCILIATION_CONFIG_INVALID', ['order_id' => $order->id]);
            return 'config_error';
        }

        if ($reference === '') {
            app(CheckoutStockService::class)->release(
                $order,
                'Intento de pago incompleto sin referencia Openpay.'
            );
            return 'released_without_reference';
        }

        try {
            $charge = $order->payment_transaction_id
                ? $this->fetchByTransaction($merchantId, $privateKey, $baseUrl, (string) $order->payment_transaction_id)
                : $this->fetchByReference($merchantId, $privateKey, $baseUrl, $reference, $order);

            if ($charge === false) {
                return 'provider_unavailable';
            }

            if ($charge === null) {
                // Openpay respondió correctamente y confirmó que no existe cargo.
                app(CheckoutStockService::class)->release(
                    $order,
                    'Openpay confirmó que el intento pendiente no generó un cargo.'
                );
                return 'released_no_charge';
            }

            if (!$this->matchesOrder($charge, $order, $reference)) {
                Log::warning('CPANEL_OPENPAY_RECONCILIATION_MISMATCH', [
                    'order_id' => $order->id,
                    'transaction_id' => $charge['id'] ?? null,
                ]);
                return 'mismatch';
            }

            if (!$order->payment_transaction_id) {
                $order->payment_transaction_id = (string) $charge['id'];
                $order->save();
            }

            $status = strtolower((string) ($charge['status'] ?? ''));

            if ($status === 'completed') {
                app(CheckoutController::class)->completePaidOrder(
                    $order,
                    empty($order->user_id),
                    true,
                );

                Log::info('CPANEL_OPENPAY_RECONCILED_PAID', [
                    'order_id' => $order->id,
                    'transaction_id' => $charge['id'],
                ]);
                return 'paid';
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                app(CheckoutStockService::class)->release(
                    $order,
                    (string) (
                        $charge['error_message']
                        ?? $charge['description']
                        ?? 'Openpay confirmó que el pago no fue aprobado.'
                    )
                );
                return 'failed';
            }

            if (in_array($status, ['in_progress', 'charge_pending'], true)) {
                Order::whereKey($order->id)->update([
                    'payment_error' => null,
                ]);
                return 'processing';
            }

            Order::whereKey($order->id)->update([
                'payment_error' => 'Estado Openpay pendiente de revisión: ' . ($status ?: 'desconocido'),
            ]);
            return 'unknown';
        } catch (\Throwable $e) {
            Log::error('CPANEL_OPENPAY_RECONCILIATION_FAILED', [
                'order_id' => $order->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return 'error';
        }
    }

    private function fetchByTransaction(
        string $merchantId,
        string $privateKey,
        string $baseUrl,
        string $transactionId
    ): array|false {
        $response = Http::connectTimeout(5)->timeout(12)->withoutRedirecting()
            ->withBasicAuth($privateKey, '')
            ->acceptJson()
            ->get("{$baseUrl}/v1/" . rawurlencode($merchantId) . '/charges/' . rawurlencode($transactionId));

        $payload = $response->json();

        return $response->successful() && is_array($payload) ? $payload : false;
    }

    private function fetchByReference(
        string $merchantId,
        string $privateKey,
        string $baseUrl,
        string $reference,
        Order $order
    ): array|null|false {
        $response = Http::connectTimeout(5)->timeout(12)->withoutRedirecting()
            ->withBasicAuth($privateKey, '')
            ->acceptJson()
            ->get("{$baseUrl}/v1/" . rawurlencode($merchantId) . '/charges', [
                'order_id' => $reference,
                'limit' => 10,
            ]);

        $payload = $response->json();
        if (!$response->successful() || !is_array($payload)) {
            return false;
        }

        $charges = isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : (array_is_list($payload) ? $payload : []);

        foreach ($charges as $charge) {
            if (is_array($charge) && $this->matchesOrder($charge, $order, $reference)) {
                return $charge;
            }
        }

        return null;
    }

    private function matchesOrder(array $charge, Order $order, string $reference): bool
    {
        $amount = isset($charge['amount']) && is_numeric($charge['amount'])
            ? (int) round((float) $charge['amount'] * 100)
            : null;

        return !empty($charge['id'])
            && (string) ($charge['order_id'] ?? '') === $reference
            && $amount === (int) round((float) $order->total * 100)
            && strtoupper((string) ($charge['currency'] ?? ''))
                === strtoupper((string) config('openpay.currency', 'MXN'));
    }
}
