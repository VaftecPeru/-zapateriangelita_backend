<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenpayReconciliationService
{
    public function __construct(
        private readonly CheckoutPaymentService $payments
    ) {
    }

    public function reconcileStaleReservations(int $limit = 5): void
    {
        if ($this->configurationErrors()) {
            return;
        }

        $ttl = max(15, (int) config('openpay.reservation_ttl_minutes', 45));

        Order::query()
            ->where('payment_status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes($ttl))
            ->orderBy('updated_at')
            ->limit(max(1, min($limit, 20)))
            ->get()
            ->each(function (Order $order) {
                try {
                    $this->reconcileOrder($order, true);
                } catch (\Throwable $e) {
                    Log::warning('OPENPAY_STALE_RECONCILIATION_FAILED', [
                        'order_id' => $order->id,
                        'exception' => get_class($e),
                    ]);
                }
            });
    }

    public function reconcileWebhook(?string $transactionId, ?string $reference): ?string
    {
        $transactionId = trim((string) $transactionId);
        $reference = trim((string) $reference);

        $order = null;
        if ($transactionId !== '') {
            $order = Order::where('payment_transaction_id', $transactionId)->first();
        }
        if (!$order && $reference !== '') {
            $order = Order::where('payment_reference', $reference)->first();
        }

        if (!$order) {
            return null;
        }

        return $this->reconcileOrder($order);
    }

    public function reconcileOrder(Order $order, bool $allowExpiredRelease = false): string
    {
        if ($order->payment_status === 'paid') {
            return 'paid';
        }

        $charge = $this->fetchAuthoritativeCharge($order);

        if ($charge === null) {
            if (
                $allowExpiredRelease &&
                $order->payment_status === 'processing' &&
                $order->updated_at &&
                $order->updated_at->lte(now()->subMinutes(max(90, (int) config('openpay.reservation_release_minutes', 90))))
            ) {
                $this->payments->releaseStockReservation(
                    $order,
                    'Reserva liberada: Openpay no reportó un cargo asociado dentro del tiempo de seguridad.'
                );

                return 'failed';
            }

            return 'not_found';
        }

        if (!$this->matchesOrder($order, $charge)) {
            Log::warning('OPENPAY_RECONCILIATION_MISMATCH', [
                'order_id' => $order->id,
                'transaction_id' => $charge['id'] ?? null,
            ]);
            return 'mismatch';
        }

        if (empty($order->payment_transaction_id) && !empty($charge['id'])) {
            $order->payment_transaction_id = (string) $charge['id'];
            $order->save();
        }

        $status = strtolower((string) ($charge['status'] ?? ''));

        if ($status === 'completed') {
            $this->payments->completePaidOrder($order, empty($order->user_id));
            return 'paid';
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $this->payments->releaseStockReservation(
                $order,
                (string) ($charge['error_message'] ?? 'El pago no fue aprobado por Openpay.')
            );
            return 'failed';
        }

        if (in_array($status, ['in_progress', 'charge_pending'], true)) {
            $order->payment_status = 'processing';
            $order->payment_error = null;
            $order->save();
            return 'processing';
        }

        return $status !== '' ? $status : 'unknown';
    }

    private function fetchAuthoritativeCharge(Order $order): ?array
    {
        if ($this->configurationErrors()) {
            return null;
        }

        $merchantId = (string) config('openpay.merchant_id');
        $privateKey = (string) config('openpay.private_key');
        $baseUrl = rtrim((string) config('openpay.base_url'), '/');

        if (!empty($order->payment_transaction_id)) {
            $transactionId = rawurlencode((string) $order->payment_transaction_id);
            $response = Http::connectTimeout(8)->timeout(20)->withoutRedirecting()
                ->withBasicAuth($privateKey, '')
                ->acceptJson()
                ->get("{$baseUrl}/v1/{$merchantId}/charges/{$transactionId}");

            if ($response->successful() && is_array($response->json())) {
                return $response->json();
            }

            if ($response->status() !== 404) {
                throw new \RuntimeException('Openpay charge lookup failed with HTTP ' . $response->status());
            }
        }

        if (empty($order->payment_reference)) {
            return null;
        }

        $response = Http::connectTimeout(8)->timeout(20)->withoutRedirecting()
            ->withBasicAuth($privateKey, '')
            ->acceptJson()
            ->get("{$baseUrl}/v1/{$merchantId}/charges", [
                'order_id' => (string) $order->payment_reference,
                'limit' => 2,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Openpay charge search failed with HTTP ' . $response->status());
        }

        $items = $response->json();
        if (!is_array($items) || $items === []) {
            return null;
        }

        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['order_id'] ?? '') === (string) $order->payment_reference) {
                return $item;
            }
        }

        return null;
    }

    private function matchesOrder(Order $order, array $charge): bool
    {
        $amount = isset($charge['amount']) && is_numeric($charge['amount'])
            ? (int) round((float) $charge['amount'] * 100)
            : null;

        return
            (string) ($charge['order_id'] ?? '') === (string) $order->payment_reference &&
            $amount === (int) round((float) $order->total * 100) &&
            strtoupper((string) ($charge['currency'] ?? '')) === strtoupper((string) config('openpay.currency', 'MXN'));
    }

    private function configurationErrors(): bool
    {
        foreach (['merchant_id', 'private_key', 'base_url', 'currency'] as $key) {
            $value = config('openpay.' . $key);
            if (!is_string($value) || trim($value) === '') {
                return true;
            }
        }

        return false;
    }
}
