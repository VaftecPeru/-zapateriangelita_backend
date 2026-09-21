<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use App\Models\User;
use App\Services\AccessTokenService;
use App\Services\CheckoutStockService;
use App\Services\DeliveryCostService;
use App\Services\DeferredOrderNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class CheckoutController extends Controller
{
    private const CHECKOUT_TOKEN_TTL_SECONDS = 14400; // 4 horas
    private const CHECKOUT_FLOW = 'angelita_checkout_v2';

    /**
     * Crea un pedido antes del pago. El cliente puede comprar como invitado.
     * La cuenta se crea únicamente después de que Openpay confirma el pago.
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.size' => 'nullable|string|max:10',
            'items.*.color' => 'nullable|string|max:50',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'customer_name' => 'required|string|max:150',
            'customer_email' => 'required|email|max:255',
            'shipping_address' => 'required|string|max:255',
            'shipping_city' => 'required|string|max:100',
            'shipping_country' => 'required|string|max:100',
            'shipping_state' => 'required|string|max:100',
            'shipping_municipality' => 'required|string|max:100',
            'shipping_postal_code' => 'required|string|max:20',
            'shipping_phone' => ['required', 'regex:/^\\+?[0-9]{7,20}$/'],
            'payment_method' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);

        $email = strtolower(trim($validated['customer_email']));
        $authUser = $this->resolveAuthenticatedUser($request);
        $existingUser = User::where('email', $email)->first();

        if (!$authUser && $existingUser) {
            return response()->json([
                'message' => 'Ya existe una cuenta con este correo. Inicia sesión para continuar con tu compra.',
                'error_code' => 'ACCOUNT_EXISTS',
                'requires_login' => true,
            ], 409);
        }

        if ($authUser && strcasecmp((string) $authUser->email, $email) !== 0) {
            return response()->json([
                'message' => 'Para continuar, utiliza el correo de la cuenta con la que has iniciado sesión.',
                'error_code' => 'EMAIL_ACCOUNT_MISMATCH',
            ], 422);
        }

        try {
            $shippingCost = app(DeliveryCostService::class)->current();

            $order = DB::transaction(function () use ($validated, $authUser, $email, $shippingCost) {
                $subtotal = 0.0;
                $orderItems = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::with('sizes')->find($item['product_id']);
                    if (!$product) {
                        abort(404, 'Producto no encontrado.');
                    }

                    $quantity = (int) $item['quantity'];
                    $masterStock = (int) ($product->stock ?? 0);

                    if ($quantity > $masterStock) {
                        abort(422, "Stock insuficiente para '{$product->name}'. Disponible: {$masterStock}.");
                    }

                    if (!empty($item['size'])) {
                        $colorSizes = $product->color_sizes ?? [];
                        if (
                            !empty($item['color']) &&
                            !empty($colorSizes[$item['color']]) &&
                            !in_array($item['size'], $colorSizes[$item['color']], true)
                        ) {
                            abort(422, "La talla '{$item['size']}' no está disponible para el color '{$item['color']}'.");
                        }

                        $sizeRow = $product->sizes->firstWhere('size', $item['size']);
                        if (!$sizeRow) {
                            abort(422, "El producto '{$product->name}' no tiene la talla '{$item['size']}'.");
                        }

                        if ($quantity > (int) $sizeRow->stock) {
                            abort(422, "Stock insuficiente para '{$product->name}' talla {$item['size']}. Disponible: {$sizeRow->stock}.");
                        }

                        if (!empty($item['color']) && Schema::hasTable('product_variant_stocks')) {
                            $variantRow = ProductVariantStock::where('product_id', $product->id)
                                ->where('color', $item['color'])
                                ->where('size', $item['size'])
                                ->first();

                            if ($variantRow && $quantity > (int) $variantRow->stock) {
                                abort(422, "Stock insuficiente para '{$product->name}', color {$item['color']}, talla {$item['size']}. Disponible: {$variantRow->stock}.");
                            }
                        }
                    }

                    $price = (float) ($product->discounted_price ?? $product->price ?? 0);
                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'size' => $item['size'] ?? null,
                        'color' => $item['color'] ?? null,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                    ];
                    $subtotal += $price * $quantity;
                }

                $order = Order::create([
                    'user_id' => $authUser?->id,
                    'code' => $this->generateOrderCode(),
                    'status' => 'sin_preparar',
                    'subtotal' => round($subtotal, 2),
                    'discount' => 0,
                    'shipping_cost' => round($shippingCost, 2),
                    'total' => round($subtotal + $shippingCost, 2),
                    'payment_method' => $validated['payment_method'] ?? 'openpay',
                    'shipping_address' => trim($validated['shipping_address']),
                    'shipping_city' => trim($validated['shipping_city']),
                    'shipping_country' => trim($validated['shipping_country']),
                    'shipping_state' => trim($validated['shipping_state']),
                    'shipping_municipality' => trim($validated['shipping_municipality']),
                    'shipping_postal_code' => trim($validated['shipping_postal_code']),
                    'shipping_phone' => trim($validated['shipping_phone']),
                    'customer_name' => trim($validated['customer_name']),
                    'customer_email' => $email,
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($orderItems as $item) {
                    OrderItem::create(array_merge($item, ['order_id' => $order->id]));
                }

                return $order->load('items');
            });

            $guestCheckout = !$authUser;
            $checkoutToken = $this->issueCheckoutToken($order, $guestCheckout);

            return response()->json([
                'id' => $order->id,
                'order_id' => $order->id,
                'order_code' => $order->code,
                'subtotal' => (float) $order->subtotal,
                'shipping_cost' => (float) $order->shipping_cost,
                'total' => (float) $order->total,
                'checkout_token' => $checkoutToken,
                'guest_checkout' => $guestCheckout,
                'message' => 'Pedido listo para pago.',
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'No fue posible preparar el pedido.',
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('CHECKOUT_CREATE_ORDER_FAILED', [
                'exception' => get_class($e),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'No fue posible preparar el pedido. Intenta nuevamente.',
            ], 500);
        }
    }

    /**
     * Tokeniza en frontend y crea el cargo en Openpay. El stock se reserva
     * únicamente al iniciar el pago, no cuando se abre el checkout.
     */
    public function charge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'checkout_token' => ['required', 'string', 'max:5000'],
            'token_id' => ['required', 'string', 'max:100'],
            'device_session_id' => ['required', 'string', 'max:255'],
        ]);

        $context = $this->resolveCheckoutContext($validated['checkout_token'], (int) $validated['order_id']);
        if (!$context) {
            return response()->json([
                'message' => 'La sesión de compra no es válida o ha vencido. Vuelve al checkout.',
                'error_code' => 'CHECKOUT_TOKEN_INVALID',
            ], 403);
        }

        /** @var Order $order */
        $order = $context['order'];
        $guestCheckout = $context['guest'];

        if ($order->payment_status === 'paid') {
            return response()->json([
                'ok' => true,
                'payment_status' => 'paid',
                'order_id' => $order->id,
                'order_code' => $order->code,
                'message' => 'Este pedido ya fue pagado.',
            ]);
        }

        if ($order->payment_status === 'processing') {
            return response()->json([
                'ok' => false,
                'payment_status' => 'processing',
                'transaction_id' => $order->payment_transaction_id,
                'message' => 'El pedido tiene un pago en proceso. No vuelvas a realizarlo.',
            ], 409);
        }

        $amount = round((float) $order->total, 2);
        if (!is_finite($amount) || $amount <= 0) {
            return response()->json(['message' => 'El total del pedido no es válido.'], 422);
        }

        $configurationErrors = $this->configurationErrors();
        if ($configurationErrors) {
            return response()->json([
                'message' => 'Openpay no está configurado correctamente.',
                'configuration_errors' => $configurationErrors,
            ], 500);
        }

        $openpayReference = $order->code . '-' . strtoupper(Str::random(8));
        $reservationError = $this->reserveStockForPayment($order, $openpayReference);
        if ($reservationError) {
            return response()->json([
                'message' => $reservationError,
                'error_code' => 'STOCK_UNAVAILABLE',
            ], 422);
        }

        $order->refresh();
        $merchantId = (string) config('openpay.merchant_id');
        $privateKey = (string) config('openpay.private_key');
        $baseUrl = rtrim((string) config('openpay.base_url'), '/');
        $redirectBase = (string) config('openpay.redirect_url');

        $redirectParts = explode('#', $redirectBase, 2);
        $redirectBase = $redirectParts[0];
        $fragment = isset($redirectParts[1]) ? '#' . $redirectParts[1] : '';
        $separator = str_contains($redirectBase, '?') ? '&' : '?';
        $redirectUrl = $redirectBase . $separator . http_build_query([
            'openpay_return' => 1,
            'order_id' => $order->id,
        ]) . $fragment;

        $fullName = trim((string) ($order->customer_name ?: 'Cliente Angelita'));
        $nameParts = preg_split('/\s+/', $fullName, 2);
        $firstName = !empty($nameParts[0]) ? $nameParts[0] : 'Cliente';
        $lastName = !empty($nameParts[1]) ? $nameParts[1] : 'Angelita';
        $clientIp = $request->ip() === '::1' ? '127.0.0.1' : $request->ip();

        $payload = [
            'method' => 'card',
            'source_id' => $validated['token_id'],
            'amount' => $amount,
            'currency' => config('openpay.currency', 'MXN'),
            'description' => 'Compra Zapatería Angelita ' . $order->code,
            'order_id' => $openpayReference,
            'device_session_id' => $validated['device_session_id'],
            'use_3d_secure' => true,
            'capture' => true,
            'redirect_url' => $redirectUrl,
            'customer' => [
                'name' => $firstName,
                'last_name' => $lastName,
                'phone_number' => $order->shipping_phone ?: '0000000000',
                'email' => $order->customer_email,
            ],
        ];

        $chargeUrl = "{$baseUrl}/v1/{$merchantId}/charges";
        $merchantFingerprint = strlen($merchantId) > 8
            ? substr($merchantId, 0, 4) . '…' . substr($merchantId, -4)
            : $merchantId;

        try {
            Log::channel('openpay')->info('OPENPAY_CHARGE_REQUEST', [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'reference' => $openpayReference,
                'environment' => (string) config('openpay.environment', config('openpay.sandbox') ? 'sandbox' : 'production'),
                'merchant' => $merchantFingerprint,
                'resource' => '/v1/{merchant_id}/charges',
                'amount' => $amount,
                'currency' => strtoupper((string) config('openpay.currency', 'MXN')),
                'has_source_id' => $validated['token_id'] !== '',
                'has_device_session_id' => $validated['device_session_id'] !== '',
                'use_3d_secure' => true,
            ]);

            $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->withBasicAuth($privateKey, '')
                ->withHeaders(['X-Forwarded-For' => $clientIp])
                ->acceptJson()
                ->asJson()
                ->post($chargeUrl, $payload);

            $data = $response->json();

            Log::channel('openpay')->info('OPENPAY_CHARGE_RESPONSE', [
                'order_id' => $order->id,
                'reference' => $openpayReference,
                'http_status' => $response->status(),
                'request_id' => is_array($data) ? ($data['request_id'] ?? null) : null,
                'charge_id' => is_array($data) ? ($data['id'] ?? null) : null,
                'openpay_status' => is_array($data) ? ($data['status'] ?? null) : null,
                'error_code' => is_array($data) ? ($data['error_code'] ?? null) : null,
            ]);

            if (!is_array($data)) {
                return response()->json([
                    'ok' => false,
                    'payment_status' => 'processing',
                    'message' => 'Openpay devolvió una respuesta inesperada. No repitas el cobro hasta verificarlo.',
                ], 502);
            }

            if (!$response->successful()) {
                $providerMessage = $data['description'] ?? $data['error_message'] ?? 'Openpay rechazó la operación.';
                $uncertain = $response->serverError()
                    || in_array($response->status(), [408, 409, 429], true);

                if ($uncertain) {
                    return response()->json([
                        'ok' => false,
                        'payment_status' => 'processing',
                        'stage' => 'charge',
                        'message' => 'No se pudo confirmar el resultado. Revisa el cargo antes de reintentar.',
                    ], 502);
                }

                $error = $this->normalizeOpenpayDecline($data);
                $this->releaseStockReservation($order, $providerMessage);

                Log::warning('CHECKOUT_OPENPAY_DECLINED', [
                    'order_id' => $order->id,
                    'http_status' => $response->status(),
                    'error_code' => $error['code'],
                    'error_key' => $error['key'],
                    'request_id' => $data['request_id'] ?? null,
                ]);

                return response()->json([
                    'ok' => false,
                    'payment_status' => 'failed',
                    'stage' => 'charge',
                    'error_key' => $error['key'],
                    'error_title' => $error['title'],
                    'message' => $error['message'],
                    'error_code' => $error['code'],
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
                Log::warning('CHECKOUT_OPENPAY_RESPONSE_MISMATCH', ['order_id' => $order->id]);
                return response()->json([
                    'ok' => false,
                    'payment_status' => 'processing',
                    'message' => 'La respuesta del pago requiere revisión. No repitas el cobro.',
                ], 502);
            }

            $openpayStatus = strtolower((string) $data['status']);
            $paymentMethodType = strtolower((string) ($data['payment_method']['type'] ?? ''));
            $paymentRedirectUrl = $data['payment_method']['url'] ?? null;

            $order->payment_transaction_id = $data['id'];
            $order->payment_reference = $openpayReference;
            $order->save();

            Log::info('CHECKOUT_OPENPAY_CHARGE_CREATED', [
                'order_id' => $order->id,
                'transaction_id' => $order->payment_transaction_id,
                'openpay_status' => $openpayStatus,
                'payment_method_type' => $paymentMethodType,
                'requires_redirect' => !empty($paymentRedirectUrl),
                'currency' => strtoupper((string) ($data['currency'] ?? '')),
            ]);

            if ($openpayStatus === 'completed') {
                $account = $this->completePaidOrder($order, $guestCheckout);

                return response()->json(array_merge([
                    'ok' => true,
                    'message' => 'Pago aprobado correctamente.',
                    'payment_status' => 'paid',
                    'openpay_status' => $openpayStatus,
                    'order_id' => $order->id,
                    'order_code' => $order->code,
                    'transaction_id' => $order->payment_transaction_id,
                ], $account));
            }

            if (
                in_array($openpayStatus, ['in_progress', 'charge_pending'], true) ||
                ($paymentMethodType === 'redirect' && !empty($paymentRedirectUrl))
            ) {
                $order->payment_status = 'processing';
                $order->payment_error = null;
                $order->save();

                return response()->json([
                    'ok' => true,
                    'message' => $paymentRedirectUrl
                        ? 'Tarjeta válida. El cargo fue generado y requiere confirmación 3D Secure.'
                        : 'El pago está siendo procesado por Openpay.',
                    'stage' => $paymentRedirectUrl ? '3ds_required' : 'charge',
                    'payment_status' => 'processing',
                    'order_id' => $order->id,
                    'order_code' => $order->code,
                    'transaction_id' => $order->payment_transaction_id,
                    'openpay_status' => $openpayStatus,
                    'requires_redirect' => !empty($paymentRedirectUrl),
                    'redirect_url' => $paymentRedirectUrl,
                ], 202);
            }

            $order->payment_status = 'processing';
            $order->payment_error = 'El estado recibido requiere revisión.';
            $order->save();

            return response()->json([
                'ok' => false,
                'payment_status' => 'processing',
                'order_id' => $order->id,
                'transaction_id' => $order->payment_transaction_id,
                'openpay_status' => $openpayStatus,
                'message' => 'El estado del pago requiere revisión. No realices otro intento.',
            ], 202);
        } catch (\Throwable $e) {
            Log::channel('openpay')->error('OPENPAY_CHARGE_TRANSPORT_FAILED', [
                'order_id' => $order->id,
                'reference' => $openpayReference,
                'environment' => (string) config('openpay.environment', config('openpay.sandbox') ? 'sandbox' : 'production'),
                'merchant' => $merchantFingerprint,
                'resource' => '/v1/{merchant_id}/charges',
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            Log::error('CHECKOUT_OPENPAY_CHARGE_FAILED', [
                'order_id' => $order->id,
                'exception' => get_class($e),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'ok' => false,
                'payment_status' => 'processing',
                'message' => 'No fue posible confirmar el resultado. Revisa el cargo antes de reintentar.',
            ], 502);
        }
    }

    /** Confirma el resultado de 3D Secure al regresar de Openpay. */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'transaction_id' => ['required', 'string', 'max:100'],
            'checkout_token' => ['required', 'string', 'max:5000'],
        ]);

        $context = $this->resolveCheckoutContext($validated['checkout_token'], (int) $validated['order_id']);
        $recoveredSession = false;

        if ($context) {
            /** @var Order $order */
            $order = $context['order'];
            $guestCheckout = $context['guest'];
        } else {
            // Recuperación segura del retorno 3D Secure:
            // si el token local ya no puede descifrarse (por una sesión antigua,
            // despliegue o rotación de APP_KEY), nunca confiamos solo en order_id.
            // Exigimos que el transaction_id de alta entropía coincida exactamente
            // con la transacción que ya quedó asociada al pedido antes del redirect.
            $order = Order::find((int) $validated['order_id']);
            $storedTransactionId = (string) ($order?->payment_transaction_id ?? '');
            $providedTransactionId = (string) $validated['transaction_id'];

            $canRecover = $order
                && $storedTransactionId !== ''
                && $order->payment_reference
                && hash_equals($storedTransactionId, $providedTransactionId)
                && in_array((string) $order->payment_status, ['processing', 'paid'], true);

            if (!$canRecover) {
                return response()->json([
                    'message' => 'La sesión de compra no es válida o ha vencido.',
                    'error_code' => 'CHECKOUT_TOKEN_INVALID',
                ], 403);
            }

            $guestCheckout = empty($order->user_id);
            $recoveredSession = true;

            Log::warning('CHECKOUT_3DS_SESSION_RECOVERED', [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'has_transaction_id' => true,
            ]);
        }

        if (
            !$order->payment_transaction_id ||
            !$order->payment_reference ||
            (string) $order->payment_transaction_id !== (string) $validated['transaction_id']
        ) {
            return response()->json([
                'message' => 'La transacción no corresponde al pedido.',
            ], 409);
        }

        if ($order->payment_status === 'paid') {
            $account = $this->completePaidOrder($order, $guestCheckout);
            if ($recoveredSession) {
                $account = $this->sanitizeRecoveredSessionResponse($account);
            }

            return response()->json(array_merge([
                'ok' => true,
                'payment_status' => 'paid',
                'order_id' => $order->id,
                'order_code' => $order->code,
                'transaction_id' => $order->payment_transaction_id,
                'session_recovered' => $recoveredSession,
                'message' => $recoveredSession
                    ? 'Pago confirmado. Por seguridad, inicia sesión para revisar tu compra.'
                    : 'Pago confirmado.',
            ], $account));
        }

        if ($this->configurationErrors()) {
            return response()->json(['message' => 'Openpay no está configurado correctamente.'], 500);
        }

        $merchantId = (string) config('openpay.merchant_id');
        $privateKey = (string) config('openpay.private_key');
        $baseUrl = rtrim((string) config('openpay.base_url'), '/');

        try {
            $transactionId = rawurlencode((string) $order->payment_transaction_id);
            $response = Http::connectTimeout(4)->timeout(8)->withoutRedirecting()
                ->withBasicAuth($privateKey, '')
                ->acceptJson()
                ->get("{$baseUrl}/v1/{$merchantId}/charges/{$transactionId}");

            $data = $response->json();
            if (!$response->successful() || !is_array($data)) {
                return response()->json([
                    'ok' => false,
                    'payment_status' => 'processing',
                    'message' => 'No fue posible consultar el pago en Openpay.',
                ], 502);
            }

            $receivedAmount = isset($data['amount']) && is_numeric($data['amount'])
                ? (int) round((float) $data['amount'] * 100)
                : null;
            $expectedAmount = (int) round((float) $order->total * 100);
            $expectedCurrency = strtoupper((string) config('openpay.currency', 'MXN'));

            if (
                (string) ($data['id'] ?? '') !== (string) $order->payment_transaction_id ||
                (string) ($data['order_id'] ?? '') !== (string) $order->payment_reference ||
                $receivedAmount !== $expectedAmount ||
                strtoupper((string) ($data['currency'] ?? '')) !== $expectedCurrency
            ) {
                return response()->json([
                    'ok' => false,
                    'payment_status' => 'processing',
                    'message' => 'Los datos de la transacción no coinciden con el pedido.',
                ], 409);
            }

            $openpayStatus = strtolower((string) ($data['status'] ?? ''));

            if ($openpayStatus === 'completed') {
                $account = $this->completePaidOrder($order, $guestCheckout);
                if ($recoveredSession) {
                    $account = $this->sanitizeRecoveredSessionResponse($account);
                }

                return response()->json(array_merge([
                    'ok' => true,
                    'payment_status' => 'paid',
                    'openpay_status' => $openpayStatus,
                    'order_id' => $order->id,
                    'order_code' => $order->code,
                    'transaction_id' => $order->payment_transaction_id,
                    'session_recovered' => $recoveredSession,
                    'message' => $recoveredSession
                        ? 'Pago aprobado correctamente. Por seguridad, inicia sesión para revisar tu compra.'
                        : 'Pago aprobado correctamente.',
                ], $account));
            }

            if (in_array($openpayStatus, ['in_progress', 'charge_pending'], true)) {
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
                $message = $data['error_message'] ?? 'El pago no fue aprobado.';
                $this->releaseStockReservation($order, $message);

                return response()->json([
                    'ok' => false,
                    'payment_status' => 'failed',
                    'openpay_status' => $openpayStatus,
                    'order_id' => $order->id,
                    'message' => $message,
                ], 422);
            }

            return response()->json([
                'ok' => false,
                'payment_status' => 'processing',
                'openpay_status' => $openpayStatus,
                'order_id' => $order->id,
                'message' => 'El estado de la transacción requiere revisión.',
            ], 409);
        } catch (\Throwable $e) {
            Log::error('CHECKOUT_OPENPAY_VERIFY_FAILED', [
                'order_id' => $order->id,
                'exception' => get_class($e),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'ok' => false,
                'payment_status' => 'processing',
                'message' => 'No fue posible verificar el pago con Openpay.',
            ], 502);
        }
    }

    /** Reserva el stock maestro y el stock de talla justo antes del cobro. */
    private function reserveStockForPayment(Order $order, string $reference): ?string
    {
        return DB::transaction(function () use ($order, $reference) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->payment_status === 'paid') {
                return 'Este pedido ya fue pagado.';
            }
            if ($lockedOrder->payment_status === 'processing') {
                return 'Este pedido ya tiene un pago en proceso.';
            }

            $items = OrderItem::where('order_id', $lockedOrder->id)
                ->orderBy('product_id')
                ->get();
            $reservations = [];

            foreach ($items as $item) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if (!$product) {
                    return 'Uno de los productos ya no está disponible.';
                }

                $quantity = (int) $item->quantity;
                if ((int) $product->stock < $quantity) {
                    return "Stock insuficiente para '{$product->name}'. Disponible: {$product->stock}.";
                }

                $sizeRow = null;
                $variantRow = null;
                if (!empty($item->size)) {
                    $sizeRow = ProductSize::where('product_id', $product->id)
                        ->where('size', $item->size)
                        ->lockForUpdate()
                        ->first();
                    if (!$sizeRow || (int) $sizeRow->stock < $quantity) {
                        $available = $sizeRow ? (int) $sizeRow->stock : 0;
                        return "Stock insuficiente para '{$product->name}' talla {$item->size}. Disponible: {$available}.";
                    }

                    if (!empty($item->color) && Schema::hasTable('product_variant_stocks')) {
                        $variantRow = ProductVariantStock::where('product_id', $product->id)
                            ->where('color', $item->color)
                            ->where('size', $item->size)
                            ->lockForUpdate()
                            ->first();

                        if ($variantRow && (int) $variantRow->stock < $quantity) {
                            return "Stock insuficiente para '{$product->name}', color {$item->color}, talla {$item->size}. Disponible: {$variantRow->stock}.";
                        }
                    }
                }

                $reservations[] = [$product, $sizeRow, $variantRow, $quantity];
            }

            foreach ($reservations as [$product, $sizeRow, $variantRow, $quantity]) {
                $product->decrement('stock', $quantity);
                if ($sizeRow) {
                    $sizeRow->decrement('stock', $quantity);
                }
                if ($variantRow) {
                    $variantRow->decrement('stock', $quantity);
                }
            }

            $lockedOrder->payment_method = 'openpay';
            $lockedOrder->payment_status = 'processing';
            $lockedOrder->payment_reference = $reference;
            $lockedOrder->payment_transaction_id = null;
            $lockedOrder->payment_error = null;
            $lockedOrder->stock_reserved_at = now();
            $lockedOrder->stock_released_at = null;
            $lockedOrder->save();

            return null;
        });
    }

    /** Devuelve stock solo cuando un pago reservado falla de forma definitiva. */
    private function releaseStockReservation(Order $order, string $reason): void
    {
        app(CheckoutStockService::class)->release($order, $reason);
    }

    /** Marca el pago, crea la cuenta si era invitado y devuelve datos de sesión. */
    private function completePaidOrder(Order $order, bool $guestCheckout): array
    {
        $createdUser = false;
        $temporaryPassword = null;

        $result = DB::transaction(function () use ($order, $guestCheckout, &$createdUser, &$temporaryPassword) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Si el pedido ya quedó vinculado a un usuario en una verificación previa,
            // podemos reemitir una sesión de forma segura e idempotente. Esto cubre
            // retornos/reintentos de 3D Secure después de que Openpay ya confirmó el pago.
            $userWasAlreadyLinked = !empty($lockedOrder->user_id);
            $user = $lockedOrder->user_id ? User::find($lockedOrder->user_id) : null;

            if (!$user && $guestCheckout) {
                $email = strtolower(trim((string) $lockedOrder->customer_email));
                $user = User::where('email', $email)->first();

                if (!$user) {
                    $temporaryPassword = $this->generateTemporaryPassword();
                    $user = User::create([
                        'name' => $lockedOrder->customer_name ?: 'Cliente Angelita',
                        'email' => $email,
                        'password' => Hash::make($temporaryPassword),
                        'phone' => $lockedOrder->shipping_phone,
                        'role' => 'user',
                    ]);
                    $createdUser = true;
                }

                $lockedOrder->user_id = $user->id;
            }

            $lockedOrder->payment_method = 'openpay';
            $lockedOrder->payment_status = 'paid';
            $lockedOrder->payment_error = null;
            $lockedOrder->paid_at = $lockedOrder->paid_at ?: now();
            $lockedOrder->status = 'sin_preparar';
            $lockedOrder->save();

            if ($createdUser && $user) {
                Address::create([
                    'user_id' => $user->id,
                    'full_name' => $lockedOrder->customer_name,
                    'phone' => $lockedOrder->shipping_phone,
                    'country' => $lockedOrder->shipping_country,
                    'state' => $lockedOrder->shipping_state,
                    'municipality' => $lockedOrder->shipping_municipality,
                    'city' => $lockedOrder->shipping_city,
                    'postal_code' => $lockedOrder->shipping_postal_code,
                    'address' => $lockedOrder->shipping_address,
                    'is_default' => true,
                ]);
            }

            return [$lockedOrder->fresh('items'), $user, $userWasAlreadyLinked];
        });

        /** @var Order $paidOrder */
        [$paidOrder, $user, $userWasAlreadyLinked] = $result;

        app(DeferredOrderNotificationService::class)->schedule(
            $paidOrder,
            $user,
            $temporaryPassword,
            $createdUser && $user && $temporaryPassword !== null,
        );

        $response = [
            'account_created' => false,
            'requires_login' => false,
            'purchase_email_scheduled' => true,
        ];

        if ($createdUser && $user && $temporaryPassword) {
            $response['account_created'] = true;
            $response['auth_token'] = app(AccessTokenService::class)->issue($user, 'checkout_auto_login');
            $response['user'] = $user;
            $response['credentials_email_scheduled'] = true;
            return $response;
        }

        if ($guestCheckout && $user && $userWasAlreadyLinked) {
            // El checkout_token ya fue validado contra este pedido y el pedido ya
            // pertenece a este usuario. Reemitimos el token para que un retorno o
            // reintento de verificación 3D Secure siga terminando en Mis Compras
            // con el cliente autenticado, sin generar otra cuenta ni otra compra.
            $response['auth_token'] = app(AccessTokenService::class)->issue($user, 'checkout_auto_login');
            $response['user'] = $user;
            $response['auto_login_reissued'] = true;
            return $response;
        }

        if ($guestCheckout && $user) {
            // Si la cuenta apareció entre la creación del pedido y el pago por una
            // condición de carrera y aún no estaba vinculada al pedido, exigimos
            // login para no entregar acceso a una cuenta ajena.
            $response['requires_login'] = true;
        }

        return $response;
    }

    /**
     * Un retorno 3D Secure recuperado por transaction_id puede confirmar el pago,
     * pero no debe entregar un token de sesión ni datos de usuario porque el
     * checkout_token original no pudo validarse. El cliente deberá autenticarse.
     */
    private function sanitizeRecoveredSessionResponse(array $account): array
    {
        unset(
            $account['auth_token'],
            $account['user'],
            $account['auto_login_reissued']
        );

        $account['requires_login'] = true;
        $account['session_recovered'] = true;

        return $account;
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if (!$bearer) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($bearer);
        $tokenable = $accessToken?->tokenable;
        return $tokenable instanceof User ? $tokenable : null;
    }

    private function issueCheckoutToken(Order $order, bool $guestCheckout): string
    {
        $payload = [
            'flow' => self::CHECKOUT_FLOW,
            'order_id' => $order->id,
            'email' => strtolower(trim((string) $order->customer_email)),
            'guest' => $guestCheckout,
            'iat' => now()->timestamp,
            'exp' => now()->timestamp + self::CHECKOUT_TOKEN_TTL_SECONDS,
            'nonce' => Str::random(24),
        ];

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function resolveCheckoutContext(string $token, int $orderId): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($payload) || ($payload['flow'] ?? null) !== self::CHECKOUT_FLOW) {
            return null;
        }
        if ((int) ($payload['order_id'] ?? 0) !== $orderId) {
            return null;
        }
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        $order = Order::find($orderId);
        if (!$order) {
            return null;
        }

        $tokenEmail = strtolower(trim((string) ($payload['email'] ?? '')));
        $orderEmail = strtolower(trim((string) $order->customer_email));
        if ($tokenEmail === '' || !hash_equals($tokenEmail, $orderEmail)) {
            return null;
        }

        return [
            'order' => $order,
            'guest' => (bool) ($payload['guest'] ?? true),
        ];
    }

    private function generateTemporaryPassword(): string
    {
        return Str::lower(Str::random(8)) . 'A7!';
    }

    private function generateOrderCode(): string
    {
        do {
            $code = 'ZA-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        } while (Order::where('code', $code)->exists());

        return $code;
    }

    private function normalizeOpenpayDecline(array $data): array
    {
        $code = (string) ($data['error_code'] ?? '');

        $errors = [
            '2004' => [
                'key' => 'INVALID_CARD_NUMBER',
                'title' => 'Número de tarjeta inválido',
                'message' => 'El número de tarjeta no es válido. Verifica los datos e intenta nuevamente.',
            ],
            '2005' => [
                'key' => 'CARD_EXPIRED',
                'title' => 'Tarjeta expirada',
                'message' => 'La tarjeta ha expirado.',
            ],
            '2006' => [
                'key' => 'INVALID_CVV',
                'title' => 'CVV inválido',
                'message' => 'Verifica el código de seguridad (CVV) de la tarjeta e intenta nuevamente.',
            ],
            '2007' => [
                'key' => 'TEST_CARD_NOT_ALLOWED',
                'title' => 'Tarjeta de prueba no permitida',
                'message' => 'La tarjeta de prueba solo puede utilizarse en el ambiente Sandbox de Openpay.',
            ],
            '2009' => [
                'key' => 'INVALID_CVV',
                'title' => 'CVV inválido',
                'message' => 'Verifica el código de seguridad (CVV) de la tarjeta e intenta nuevamente.',
            ],
            '2010' => [
                'key' => 'THREE_DS_FAILED',
                'title' => 'Verificación 3D Secure fallida',
                'message' => 'No se pudo completar la verificación de seguridad. Intenta nuevamente o usa otra tarjeta.',
            ],
            '3001' => [
                'key' => 'CARD_DECLINED',
                'title' => 'Tarjeta rechazada',
                'message' => 'La tarjeta fue rechazada. Intenta con otra tarjeta o comunícate con tu banco.',
            ],
            '3002' => [
                'key' => 'CARD_EXPIRED',
                'title' => 'Tarjeta expirada',
                'message' => 'La tarjeta ha expirado.',
            ],
            '3003' => [
                'key' => 'INSUFFICIENT_FUNDS',
                'title' => 'Fondos insuficientes',
                'message' => 'La tarjeta no tiene fondos suficientes.',
            ],
            '3004' => [
                'key' => 'CARD_DECLINED',
                'title' => 'Tarjeta rechazada',
                'message' => 'La tarjeta fue rechazada.',
            ],
            '3005' => [
                'key' => 'ANTIFRAUD_REJECTED',
                'title' => 'Tarjeta rechazada por seguridad',
                'message' => 'La tarjeta ha sido rechazada por el sistema antifraudes.',
            ],
            '3006' => [
                'key' => 'OPERATION_NOT_ALLOWED',
                'title' => 'Operación no permitida',
                'message' => 'Esta operación no está permitida para la tarjeta. Intenta con otra tarjeta.',
            ],
            '3007' => [
                'key' => 'CARD_DECLINED',
                'title' => 'Tarjeta rechazada',
                'message' => 'La tarjeta fue rechazada. Intenta con otra tarjeta o comunícate con tu banco.',
            ],
            '3008' => [
                'key' => 'ONLINE_PURCHASE_NOT_ALLOWED',
                'title' => 'Tarjeta no compatible',
                'message' => 'Esta tarjeta no permite compras en línea. Intenta con otra tarjeta.',
            ],
            '3009' => [
                'key' => 'CARD_NOT_AUTHORIZED',
                'title' => 'Tarjeta no autorizada',
                'message' => 'El banco no autorizó esta tarjeta. Utiliza otra tarjeta o comunícate con tu banco.',
            ],
            '3010' => [
                'key' => 'CARD_RESTRICTED',
                'title' => 'Tarjeta restringida',
                'message' => 'El banco ha restringido la tarjeta. Comunícate con tu banco o utiliza otra tarjeta.',
            ],
            '3011' => [
                'key' => 'PAYMENT_NOT_AUTHORIZED',
                'title' => 'Tarjeta no autorizada',
                'message' => 'El banco no autorizó el pago. Comunícate con tu banco o utiliza otra tarjeta.',
            ],
            '3012' => [
                'key' => 'BANK_AUTHORIZATION_REQUIRED',
                'title' => 'Autorización bancaria requerida',
                'message' => 'Tu banco requiere una autorización adicional para realizar este pago.',
            ],
        ];

        $fallback = [
            'key' => 'PAYMENT_DECLINED',
            'title' => 'Transacción no aprobada',
            'message' => 'Openpay no aprobó el pago. Revisa los datos o utiliza otra tarjeta.',
        ];

        return array_merge(
            ['code' => $code !== '' ? $code : null],
            $errors[$code] ?? $fallback
        );
    }

    private function configurationErrors(): array
    {
        $errors = [];
        foreach (['merchant_id', 'public_key', 'private_key', 'base_url', 'redirect_url', 'currency'] as $key) {
            $value = config('openpay.' . $key);
            if (!is_string($value) || trim($value) === '') {
                $errors[] = 'openpay.' . $key;
            }
        }

        return array_values(array_unique($errors));
    }
}
