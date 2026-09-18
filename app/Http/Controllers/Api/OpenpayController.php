<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OpenpayReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenpayController extends Controller
{
    public function config()
    {
        $missing = $this->configurationErrors();

        return response()->json([
            'configured' => empty($missing),
            'configuration_errors' => $missing,
            'merchant_id' => config('openpay.merchant_id'),
            'public_key' => config('openpay.public_key'),
            'sandbox' => config('openpay.sandbox'),
        ]);
    }

    public function charge(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'token_id' => ['required', 'string', 'max:100'],
            'device_session_id' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Inicia sesión.'], 401);
        }

        $order = Order::where('id', $validated['order_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($order->payment_status === 'paid') {
            return response()->json([
                'ok' => false,
                'message' => 'Este pedido ya fue pagado.',
            ], 409);
        }

        // Evitar un nuevo intento mientras el anterior está en proceso.
        if ($order->payment_status === 'processing') {
            return response()->json([
                'ok' => false,
                'message' => 'El pedido tiene un pago en proceso. Verifica su estado.',
                'payment_status' => 'processing',
                'transaction_id' => $order->payment_transaction_id,
            ], 409);
        }

        $amount = round((float) $order->total, 2);

        if (!is_finite($amount) || $amount <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'El total del pedido no es válido.',
            ], 422);
        }

        $merchantId = config('openpay.merchant_id');
        $privateKey = config('openpay.private_key');
        $baseUrl = rtrim((string) config('openpay.base_url'), '/');
        $redirectBase = (string) config('openpay.redirect_url');

        if ($this->configurationErrors()) {
            return response()->json([
                'ok' => false,
                'message' => 'Openpay no está configurado correctamente.',
            ], 500);
        }

        $redirectParts = explode('#', $redirectBase, 2);
        $redirectBase = $redirectParts[0];
        $fragment = isset($redirectParts[1]) ? '#' . $redirectParts[1] : '';
        $separator = str_contains($redirectBase, '?') ? '&' : '?';

        $redirectUrl = $redirectBase . $separator . http_build_query([
            'openpay_return' => 1,
            'order_id' => $order->id,
        ]) . $fragment;

        $fullName = trim((string) ($order->customer_name ?: $user->name));
        $nameParts = preg_split('/\s+/', $fullName, 2);

        $firstName = !empty($nameParts[0]) ? $nameParts[0] : 'Cliente';
        $lastName = !empty($nameParts[1]) ? $nameParts[1] : 'Angelita';

        $openpayReference = $order->code . '-' . strtoupper(Str::random(8));

        // Laravel determina la IP según la configuración de proxies confiables.
        $clientIp = $request->ip();

        if ($clientIp === '::1') {
            $clientIp = '127.0.0.1';
        }

        $payload = [
            'method' => 'card',
            'source_id' => $validated['token_id'],
            'amount' => $amount,
            'currency' => config('openpay.currency', 'MXN'),
            'description' => 'Compra Zapatería Angelita ' . $order->code,
            'order_id' => $openpayReference,
            'device_session_id' => $validated['device_session_id'],
            'use_3d_secure' => true,
            'redirect_url' => $redirectUrl,
            'customer' => [
                'name' => $firstName,
                'last_name' => $lastName,
                'phone_number' => $order->shipping_phone ?: '0000000000',
                'email' => $user->email,
            ],
        ]; 

        try {
            // Reserva atómica antes de enviar el cargo. Un timeout conserva
            // processing y la referencia para conciliarlo en Openpay.
            $reserved = Order::where('id', $order->id)
                ->where('user_id', $user->id)
                ->where('total', $order->total)
                ->where(function ($query) {
                    $query->whereNull('payment_status')
                        ->orWhereNotIn('payment_status', ['paid', 'processing']);
                })
                ->update([
                    'payment_method' => 'openpay',
                    'payment_status' => 'processing',
                    'payment_reference' => $openpayReference,
                    'payment_transaction_id' => null,
                    'payment_error' => null,
                ]);

            if ($reserved !== 1) {
                return response()->json([
                    'ok' => false,
                    'message' => 'El pedido cambió o ya tiene un pago en curso. Actualiza su estado.',
                ], 409);
            }
            $order->refresh();

            $response = Http::connectTimeout(10)->timeout(30)->withoutRedirecting()
                ->withBasicAuth($privateKey, '')
                ->withHeaders([
                    'X-Forwarded-For' => $clientIp,
                ])
                ->acceptJson()
                ->asJson()
                ->post("{$baseUrl}/v1/{$merchantId}/charges", $payload);

            $data = $response->json();

            if (!is_array($data)) {
                $this->safeLog('error', 'Respuesta no JSON al crear cargo Openpay', [
                    'order_id' => $order->id,
                    'http_status' => $response->status(),
                ]);

                return response()->json([
                    'ok' => false,
                    'message' => 'Respuesta inesperada de Openpay. Revisa el cargo antes de reintentar.',
                ], 502);
            }

            if (!$response->successful()) {
                $message = $data['description']
                    ?? $data['error_message']
                    ?? 'Openpay rechazó la operación.';

                // Un error del servidor no confirma que el cargo haya fallado.
                if ($response->serverError() || $response->status() === 408 || $response->status() === 409 || $response->status() === 429 || $response->status() < 400) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'No se pudo confirmar el resultado. Revisa el cargo en Openpay antes de reintentar.',
                    ], 502);
                }

                $order->payment_method = 'openpay';
                $order->payment_status = 'failed';
                $order->payment_error = $message;
                $order->save();

                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'error_code' => $data['error_code'] ?? null,
                    'request_id' => $data['request_id'] ?? null,
                ], 422);
            }

            if (
                !is_string($data['id'] ?? null) || $data['id'] === '' ||
                !is_string($data['status'] ?? null) ||
                (string) ($data['order_id'] ?? '') !== $openpayReference ||
                !is_numeric($data['amount'] ?? null) ||
                (int) round((float) $data['amount'] * 100) !== (int) round($amount * 100) ||
                strtoupper((string) ($data['currency'] ?? '')) !== strtoupper((string) config('openpay.currency', 'MXN'))
            ) {
                $this->safeLog('warning', 'OPENPAY_CHARGE_RESPONSE_MISMATCH', ['order_id' => $order->id]);
                return response()->json([
                    'ok' => false,
                    'message' => 'La respuesta requiere conciliación en Openpay. No repitas el cobro.',
                ], 502);
            }

            $openpayStatus = strtolower((string) ($data['status'] ?? ''));

            $order->payment_method = 'openpay';
            $order->payment_transaction_id = $data['id'] ?? null;
            $order->payment_reference = $openpayReference;

            if ($openpayStatus === 'completed') {
                $order->payment_status = 'paid';
                $order->payment_error = null;
                $order->paid_at = now();
                $order->status = 'confirmed';
                $order->save();

                return response()->json([
                    'ok' => true,
                    'message' => 'Pago aprobado correctamente.',
                    'order_id' => $order->id,
                    'order_code' => $order->code,
                    'payment_status' => 'paid',
                    'transaction_id' => $order->payment_transaction_id,
                    'openpay_status' => $openpayStatus,
                ]);
            }

            if (in_array($openpayStatus, ['in_progress', 'charge_pending'], true)) {
                $paymentRedirectUrl = $data['payment_method']['url'] ?? null;

                $order->payment_status = 'processing';
                $order->payment_error = null;
                $order->save();

                return response()->json([
                    'ok' => true,
                    'message' => $paymentRedirectUrl
                        ? 'Continúa con la verificación de seguridad.'
                        : 'El pago está siendo procesado por Openpay.',
                    'order_id' => $order->id,
                    'order_code' => $order->code,
                    'payment_status' => 'processing',
                    'transaction_id' => $order->payment_transaction_id,
                    'openpay_status' => $openpayStatus,
                    'requires_redirect' => !empty($paymentRedirectUrl),
                    'redirect_url' => $paymentRedirectUrl,
                ], 202);
            }

            // No clasificar estados desconocidos como rechazo definitivo.
            $order->payment_status = 'processing';
            $order->payment_error = 'El estado recibido requiere revisión.';
            $order->save();

            return response()->json([
                'ok' => false,
                'message' => 'Revisa el estado del cargo en Openpay antes de realizar otro intento.',
                'payment_status' => 'processing',
                'transaction_id' => $order->payment_transaction_id,
                'openpay_status' => $openpayStatus,
            ], 202);
        } catch (\Throwable $e) {
            $this->safeLog('error', 'Error comunicando con Openpay', [
                'order_id' => $order->id,
                'exception' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'No fue posible confirmar el resultado. Revisa el cargo en Openpay antes de reintentar.',
            ], 502);
        }
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'transaction_id' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Inicia sesión.'], 401);
        }

        $order = Order::where('id', $validated['order_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Usar únicamente la transacción previamente asociada al pedido.
        if (
            !$order->payment_transaction_id ||
            !$order->payment_reference ||
            (string) $order->payment_transaction_id !== $validated['transaction_id']
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'La transacción no corresponde al pedido.',
            ], 409);
        }

        if ($order->payment_status === 'paid') {
            return response()->json([
                'ok' => true,
                'payment_status' => 'paid',
                'order_id' => $order->id,
                'order_code' => $order->code,
                'transaction_id' => $order->payment_transaction_id,
                'message' => 'El pago ya había sido confirmado.',
            ]);
        }

        $merchantId = config('openpay.merchant_id');
        $privateKey = config('openpay.private_key');
        $baseUrl = rtrim((string) config('openpay.base_url'), '/');

        if ($this->configurationErrors()) {
            return response()->json([
                'ok' => false,
                'message' => 'Openpay no está configurado correctamente.',
            ], 500);
        }

        try {
            return DB::transaction(function () use ($order, $merchantId, $privateKey, $baseUrl, $validated) {
                $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
                if ((string) $order->payment_transaction_id !== $validated['transaction_id']) {
                    return response()->json(['ok' => false, 'message' => 'El intento de pago cambió.'], 409);
                }
                if ($order->payment_status === 'paid') {
                    return response()->json([
                        'ok' => true, 'payment_status' => 'paid',
                        'order_id' => $order->id, 'order_code' => $order->code,
                        'transaction_id' => $order->payment_transaction_id,
                    ]);
                }
                $transactionId = rawurlencode(
                    (string) $order->payment_transaction_id
                );

                $response = Http::connectTimeout(10)->timeout(30)->withoutRedirecting()
                    ->withBasicAuth($privateKey, '')
                    ->acceptJson()
                    ->get("{$baseUrl}/v1/{$merchantId}/charges/{$transactionId}");

                $data = $response->json();

                if (!$response->successful() || !is_array($data)) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'No fue posible consultar el pago en Openpay.',
                    ], 502);
                }

                $expectedCurrency = strtoupper(
                    (string) config('openpay.currency', 'MXN')
                );

                $expectedAmount = (int) round((float) $order->total * 100);

                $receivedAmount = isset($data['amount']) && is_numeric($data['amount'])
                    ? (int) round((float) $data['amount'] * 100)
                    : null;

                // Verificación estricta: ID, referencia, importe y moneda.
                if (
                    (string) ($data['id'] ?? '') !== (string) $order->payment_transaction_id ||
                    (string) ($data['order_id'] ?? '') !== (string) $order->payment_reference ||
                    $receivedAmount !== $expectedAmount ||
                    strtoupper((string) ($data['currency'] ?? '')) !== $expectedCurrency
                ) {
                    $this->safeLog('warning', 'Datos de transacción Openpay no coinciden', [
                        'order_id' => $order->id,
                    ]);

                    return response()->json([
                        'ok' => false,
                        'message' => 'Los datos de la transacción no coinciden con el pedido.',
                    ], 409);
                }

                $openpayStatus = strtolower((string) ($data['status'] ?? ''));

                if ($openpayStatus === 'completed') {
                    $order->payment_method = 'openpay';
                    $order->payment_status = 'paid';
                    $order->payment_error = null;
                    $order->paid_at = $order->paid_at ?: now();
                    $order->status = 'confirmed';
                    $order->save();

                    return response()->json([
                        'ok' => true,
                        'payment_status' => 'paid',
                        'openpay_status' => $openpayStatus,
                        'order_id' => $order->id,
                        'order_code' => $order->code,
                        'transaction_id' => $order->payment_transaction_id,
                        'message' => 'Pago aprobado correctamente.',
                    ]);
                }

                if (in_array($openpayStatus, ['in_progress', 'charge_pending'], true)) {
                    $order->payment_status = 'processing';
                    $order->payment_error = null;
                    $order->save();

                    return response()->json([
                        'ok' => true,
                        'payment_status' => 'processing',
                        'openpay_status' => $openpayStatus,
                        'order_id' => $order->id,
                        'order_code' => $order->code,
                        'transaction_id' => $order->payment_transaction_id,
                        'message' => 'El pago continúa siendo procesado.',
                    ], 202);
                }

                if (in_array($openpayStatus, ['failed', 'cancelled'], true)) {
                    $order->payment_status = 'failed';
                    $order->payment_error = $data['error_message']
                        ?? 'El pago no fue aprobado.';
                    $order->save();

                    return response()->json([
                        'ok' => false,
                        'payment_status' => 'failed',
                        'openpay_status' => $openpayStatus,
                        'order_id' => $order->id,
                        'message' => $order->payment_error,
                    ], 422);
                }

                // Otros estados requieren tratamiento específico.
                return response()->json([
                    'ok' => false,
                    'payment_status' => $order->payment_status,
                    'openpay_status' => $openpayStatus,
                    'order_id' => $order->id,
                    'message' => 'El estado de la transacción requiere revisión en Openpay.',
                ], 409);
            });
        } catch (\Throwable $e) {
            $this->safeLog('error', 'Error verificando pago Openpay', [
                'order_id' => $order->id,
                'exception' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'No fue posible verificar el pago con Openpay.',
            ], 502);
        }
    }


    public function webhook(Request $request)
    {
        /*
         * Openpay envía el webhook como JSON. No dependemos de isJson()
         * porque algunos proxies/WAF pueden modificar el Content-Type.
         */
        $rawBody = trim((string) $request->getContent());
        $payload = $rawBody !== '' ? json_decode($rawBody, true) : null;

        if (!is_array($payload)) {
            $payload = $request->all();
        }

        if (!is_array($payload) || empty($payload)) {
            $this->safeLog('warning', 'OPENPAY_WEBHOOK_EMPTY_OR_INVALID');

            return response()->json([
                'received' => false,
                'message' => 'Notificación inválida.',
            ], 400);
        }

        $type = isset($payload['type']) && is_scalar($payload['type'])
            ? trim((string) $payload['type'])
            : '';

        if ($type === '') {
            $this->safeLog('warning', 'OPENPAY_WEBHOOK_WITHOUT_TYPE');

            return response()->json([
                'received' => false,
                'message' => 'Falta el tipo de notificación.',
            ], 422);
        }

        try {
            $logger = Log::channel('openpay');

            if ($type === 'verification') {
                $verificationCode = $payload['verification_code'] ?? null;

                if (!is_scalar($verificationCode) || trim((string) $verificationCode) === '') {
                    $logger->warning('OPENPAY_VERIFICATION_WITHOUT_CODE');

                    return response()->json([
                        'received' => false,
                        'message' => 'Falta el código de verificación.',
                    ], 422);
                }

                $verificationCode = trim((string) $verificationCode);

                $logger->info('OPENPAY_VERIFICATION_RECEIVED', [
                    'verification_code' => $verificationCode,
                    'event_date' => isset($payload['event_date']) && is_scalar($payload['event_date'])
                        ? (string) $payload['event_date']
                        : null,
                ]);

                return response()->json([
                    'received' => true,
                    'type' => 'verification',
                ], 200);
            }

            $transaction = isset($payload['transaction']) && is_array($payload['transaction'])
                ? $payload['transaction']
                : [];

            $transactionId = isset($transaction['id']) && is_scalar($transaction['id'])
                ? trim((string) $transaction['id'])
                : null;
            $reference = isset($transaction['order_id']) && is_scalar($transaction['order_id'])
                ? trim((string) $transaction['order_id'])
                : null;

            $logger->info('OPENPAY_WEBHOOK_RECEIVED', [
                'type' => $type,
                'event_date' => isset($payload['event_date']) && is_scalar($payload['event_date'])
                    ? (string) $payload['event_date']
                    : null,
                'transaction_id' => $transactionId,
                'order_id' => $reference,
                'status' => isset($transaction['status']) && is_scalar($transaction['status'])
                    ? (string) $transaction['status']
                    : null,
            ]);

            $reconciledStatus = null;
            if (str_starts_with($type, 'charge.')) {
                // No confiamos ciegamente en el payload: el servicio vuelve a
                // consultar el cargo a Openpay y valida referencia, importe y moneda.
                $reconciledStatus = app(OpenpayReconciliationService::class)
                    ->reconcileWebhook($transactionId, $reference);

                $logger->info('OPENPAY_WEBHOOK_RECONCILED', [
                    'type' => $type,
                    'transaction_id' => $transactionId,
                    'reconciled_status' => $reconciledStatus,
                ]);
            }

            return response()->json([
                'received' => true,
                'type' => $type,
                'reconciled_status' => $reconciledStatus,
            ], 200);
        } catch (\Throwable $e) {
            $this->safeLog('error', 'OPENPAY_WEBHOOK_WRITE_FAILED', [
                'exception' => get_class($e),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'received' => false,
                'message' => 'No fue posible conciliar la notificación. Openpay puede reintentarlo.',
            ], 503);
        }
    }

    /** Solo informa nombres de parámetros; nunca valores privados. */
    private function configurationErrors(): array
    {
        $errors = [];
        foreach (['merchant_id', 'public_key', 'private_key', 'base_url', 'redirect_url', 'currency'] as $key) {
            $value = config('openpay.' . $key);
            if (!is_string($value) || trim($value) === '') {
                $errors[] = 'openpay.' . $key;
            }
        }
        $sandbox = filter_var(config('openpay.sandbox'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (config('openpay.sandbox') === null || $sandbox === null) {
            $errors[] = 'openpay.sandbox';
        }
        $base = rtrim((string) config('openpay.base_url'), '/');
        $expected = $sandbox === false ? 'https://api.openpay.mx' : 'https://sandbox-api.openpay.mx';
        if ($base !== $expected) {
            $errors[] = 'openpay.base_url debe coincidir con el entorno México';
        }
        $redirect = (string) config('openpay.redirect_url');
        $redirectValid = filter_var( $redirect,    FILTER_VALIDATE_URL);
        $scheme = parse_url( $redirect, PHP_URL_SCHEME);
        $host = parse_url( $redirect, PHP_URL_HOST);
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1'], true);

        if (
            !$redirectValid || ($scheme !== 'https' && !($sandbox === true && $isLocalhost))
        ) { $errors[] = 'openpay.redirect_url no es válida para el entorno actual'; }
        return array_values(array_unique($errors));
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable $e) {
            // Respaldo mínimo en error_log de PHP, sin payload ni credenciales.
            error_log('Openpay: fallo de registro; evento=' . $message);
        }
    }
}
