<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenpayController extends Controller
{
    public function config()
    {
        return response()->json([
            'merchant_id' => config('openpay.merchant_id'),
            'public_key' => config('openpay.public_key'),
            'sandbox' => config('openpay.sandbox'),
        ]);
    }

    public function charge(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],

            'token_id' => [ 'required', 'string', 'max:100'],

            'device_session_id' => [ 'required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $order = Order::where('id', $validated['order_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($order->payment_status === 'paid') {
            return response()->json([
                'ok' => false,
                'message' => 'Este pedido ya fue pagado.',
            ], 409);
        }

        $amount = round((float) $order->total, 2);

        if ($amount <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'El total del pedido no es válido.',
            ], 422);
        }

        $merchantId = config('openpay.merchant_id');
        $privateKey = config('openpay.private_key');
        $baseUrl = rtrim( config('openpay.base_url'), '/' );
        $redirectUrl = rtrim(config('openpay.redirect_url'), '/') . '/?openpay_return=1&order_id=' . $order->id;

        if ( !$merchantId || !$privateKey || !$baseUrl ) {
            return response()->json([
                'ok' => false,
                'message' => 'Openpay no está configurado correctamente.',
            ], 500);
        }

        $fullName = trim( $order->customer_name ?: $user->name );

        $nameParts = preg_split( '/\s+/', $fullName, 2 );

        $firstName = $nameParts[0] ?? 'Cliente';
        $lastName = $nameParts[1] ?? 'Angelita';
        $openpayReference = $order->code . '-' . strtoupper(Str::random(8));
        $clientIp =$request->header('X-Forwarded-For') ?: $request->ip();

        if (str_contains($clientIp, ',')) {$clientIp = trim(explode(',', $clientIp)[0]);}
        if ($clientIp === '::1') {$clientIp = '127.0.0.1';}

        $payload = [
            'method' => 'card',
            'source_id' => $validated['token_id'],
            'amount' => $amount,
            'currency' => config('openpay.currency', 'MXN'),
            'description' => 'Compra Zapateria Angelita ' . $order->code,
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
            Log::info('Payload Openpay - estructura', [
                'keys' => array_keys($payload),
                'customer_keys' => isset($payload['customer'])
                    ? array_keys($payload['customer'])
                    : [],
                'method' => $payload['method'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'use_3d_secure' => $payload['use_3d_secure'] ?? null,
                'redirect_url_present' =>
                    !empty($payload['redirect_url']),
            ]);
            $response = Http::timeout(30)

                ->withBasicAuth( $privateKey, '')
                ->withHeaders([
                    'X-Forwarded-For' => $clientIp,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post( "{$baseUrl}/v1/{$merchantId}/charges", $payload );

            $data = $response->json();
            if (!$response->successful()) {
                $message = $data['description'] ?? $data['error_message'] ?? 'Openpay rechazó la operación.';
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

            $openpayStatus = strtolower( $data['status'] ?? '' );

            if ($openpayStatus === 'completed') {
                $order->payment_method = 'openpay';
                $order->payment_status = 'paid';
                $order->payment_transaction_id = $data['id'] ?? null;
                $order->payment_reference = $openpayReference;
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
                    'transaction_id' => $data['id'] ?? null,
                    'openpay_status' => $openpayStatus,
                ]);
            }

            if (in_array( $openpayStatus, ['in_progress', 'charge_pending'], true )) {
                $paymentRedirectUrl = $data['payment_method']['url'] ?? null;
                $order->payment_method = 'openpay';
                $order->payment_status = 'processing';
                $order->payment_transaction_id = $data['id'] ?? null;
                $order->payment_reference = $openpayReference;
                $order->payment_error = null;
                $order->save();
                return response()->json([
                    'ok' => true,
                    'message' => $paymentRedirectUrl ? 'Continúa con la verificación de seguridad.': 'El pago está siendo procesado por Openpay.',

                    'order_id' => $order->id,
                    'order_code' => $order->code,
                    'payment_status' => 'processing',
                    'transaction_id' => $data['id'] ?? null,
                    'openpay_status' => $openpayStatus,
                    'requires_redirect' => !empty($paymentRedirectUrl),
                    'redirect_url' => $paymentRedirectUrl,
                ], 202);
            }

            $order->payment_method ='openpay';
            $order->payment_status ='failed';
            $order->payment_transaction_id =$data['id'] ?? null;
            $order->payment_reference = $openpayReference;
            $order->payment_error = $data['error_message'] ?? 'El pago no pudo ser completado.';
            $order->save();

            return response()->json([
                'ok' => false,
                'message' => $order->payment_error,
                'payment_status' => 'failed',
                'openpay_status' => $openpayStatus,
            ], 422);

        } catch (\Throwable $e) {

            Log::error( 'Error comunicando con Openpay',
                [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]
            );

            return response()->json([
                'ok' => false,
                'message' => 'No fue posible comunicarse con Openpay.',
            ], 502);
        }
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'order_id' => [ 'required', 'integer', 'exists:orders,id'],
            'transaction_id' => [ 'required', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $order = Order::where( 'id', $validated['order_id'])
            ->where( 'user_id', $user->id )
            ->firstOrFail();

        if ($order->payment_status === 'paid') {
            return response()->json([
                'ok' => true,
                'payment_status' => 'paid',
                'order_id' => $order->id,
                'order_code' => $order->code,
                'transaction_id' =>
                    $order->payment_transaction_id,
                'message' =>
                    'El pago ya había sido confirmado.',
            ]);
        }


        $merchantId =
            config('openpay.merchant_id');

        $privateKey =
            config('openpay.private_key');

        $baseUrl =
            rtrim(
                config('openpay.base_url'),
                '/'
            );


        if (
            !$merchantId ||
            !$privateKey ||
            !$baseUrl
        ) {
            return response()->json([
                'ok' => false,
                'message' =>
                    'Openpay no está configurado correctamente.',
            ], 500);
        }


        try {

            /*
            * Consultamos directamente a Openpay.
            */
            $response = Http::timeout(30)
                ->withBasicAuth(
                    $privateKey,
                    ''
                )
                ->acceptJson()
                ->get(
                    "{$baseUrl}/v1/{$merchantId}/charges/" .
                    $validated['transaction_id']
                );


            $data = $response->json();


            if (!$response->successful()) {

                return response()->json([
                    'ok' => false,

                    'message' =>
                        $data['description']
                        ?? $data['error_message']
                        ?? 'No fue posible verificar el pago.',

                    'error_code' =>
                        $data['error_code']
                        ?? null,

                    'request_id' =>
                        $data['request_id']
                        ?? null,

                ], 422);
            }


            /*
            * Seguridad adicional:
            * comprobamos que la transacción sea
            * realmente del pedido que tenemos guardado.
            */
            if (
                $order->payment_reference &&
                isset($data['order_id']) &&
                $data['order_id'] !==
                    $order->payment_reference
            ) {
                Log::warning(
                    'Transacción Openpay no coincide con pedido',
                    [
                        'order_id' =>
                            $order->id,

                        'expected_reference' =>
                            $order->payment_reference,

                        'received_reference' =>
                            $data['order_id'],
                    ]
                );

                return response()->json([
                    'ok' => false,
                    'message' =>
                        'La transacción no corresponde al pedido.',
                ], 409);
            }


            $openpayStatus =
                strtolower(
                    $data['status'] ?? ''
                );


            /*
            * PAGO APROBADO
            */
            if ($openpayStatus === 'completed') {

                $order->payment_method =
                    'openpay';

                $order->payment_status =
                    'paid';

                $order->payment_transaction_id =
                    $data['id'];

                $order->payment_error =
                    null;

                $order->paid_at =
                    now();

                $order->status =
                    'confirmed';

                $order->save();


                return response()->json([
                    'ok' => true,

                    'payment_status' =>
                        'paid',

                    'openpay_status' =>
                        $openpayStatus,

                    'order_id' =>
                        $order->id,

                    'order_code' =>
                        $order->code,

                    'transaction_id' =>
                        $data['id'],

                    'message' =>
                        'Pago aprobado correctamente.',
                ]);
            }


            /*
            * TODAVÍA EN PROCESO
            */
            if (in_array(
                $openpayStatus,
                [
                    'in_progress',
                    'charge_pending',
                ],
                true
            )) {

                $order->payment_status =
                    'processing';

                $order->payment_transaction_id =
                    $data['id'] ?? null;

                $order->payment_error =
                    null;

                $order->save();


                return response()->json([
                    'ok' => true,

                    'payment_status' =>
                        'processing',

                    'openpay_status' =>
                        $openpayStatus,

                    'order_id' =>
                        $order->id,

                    'order_code' =>
                        $order->code,

                    'transaction_id' =>
                        $data['id'] ?? null,

                    'message' =>
                        'El pago continúa siendo procesado.',
                ], 202);
            }


            /*
            * CUALQUIER OTRO ESTADO
            */
            $order->payment_status =
                'failed';

            $order->payment_transaction_id =
                $data['id'] ?? null;

            $order->payment_error =
                $data['error_message']
                ?? 'El pago no fue aprobado.';

            $order->save();


            return response()->json([
                'ok' => false,

                'payment_status' =>
                    'failed',

                'openpay_status' =>
                    $openpayStatus,

                'order_id' =>
                    $order->id,

                'message' =>
                    $order->payment_error,

            ], 422);


        } catch (\Throwable $e) {

            Log::error(
                'Error verificando pago Openpay',
                [
                    'order_id' =>
                        $order->id,

                    'transaction_id' =>
                        $validated['transaction_id'],

                    'message' =>
                        $e->getMessage(),
                ]
            );


            return response()->json([
                'ok' => false,

                'message' =>
                    'No fue posible verificar el pago con Openpay.',

            ], 502);
        }
    }
    public function webhook(Request $request)
    {
        Log::info( 'Webhook Openpay recibido', $request->all() );
        return response()->json([ 'received' => true, ], 200);
    }
}