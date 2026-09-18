<?php

namespace App\Services;

use App\Mail\UserCredentialsMail;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CheckoutPaymentService
{
    public function releaseStockReservation(Order $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->payment_status !== 'processing') {
                return;
            }

            $items = OrderItem::where('order_id', $lockedOrder->id)
                ->orderBy('product_id')
                ->get();

            foreach ($items as $item) {
                $quantity = (int) $item->quantity;

                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if ($product) {
                    $product->increment('stock', $quantity);
                }

                if (!empty($item->size)) {
                    $sizeRow = ProductSize::where('product_id', $item->product_id)
                        ->where('size', $item->size)
                        ->lockForUpdate()
                        ->first();

                    if ($sizeRow) {
                        $sizeRow->increment('stock', $quantity);
                    }
                }
            }

            $lockedOrder->payment_status = 'failed';
            $lockedOrder->payment_error = $reason;
            $lockedOrder->save();
        });
    }

    public function completePaidOrder(Order $order, bool $guestCheckout): array
    {
        $createdUser = false;

        $result = DB::transaction(function () use ($order, $guestCheckout, &$createdUser) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $userWasAlreadyLinked = !empty($lockedOrder->user_id);
            $user = $lockedOrder->user_id ? User::find($lockedOrder->user_id) : null;

            if (!$user && $guestCheckout) {
                $email = strtolower(trim((string) $lockedOrder->customer_email));
                $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

                if (!$user) {
                    $user = User::create([
                        'name' => $lockedOrder->customer_name ?: 'Cliente Angelita',
                        'email' => $email,
                        'password' => Hash::make(Str::random(64)),
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
                Address::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'address' => $lockedOrder->shipping_address,
                        'postal_code' => $lockedOrder->shipping_postal_code,
                    ],
                    [
                        'full_name' => $lockedOrder->customer_name,
                        'phone' => $lockedOrder->shipping_phone,
                        'country' => $lockedOrder->shipping_country,
                        'state' => $lockedOrder->shipping_state,
                        'municipality' => $lockedOrder->shipping_municipality,
                        'city' => $lockedOrder->shipping_city,
                        'is_default' => true,
                    ]
                );
            }

            return [$lockedOrder->fresh('items'), $user, $userWasAlreadyLinked];
        });

        /** @var Order $paidOrder */
        [$paidOrder, $user, $userWasAlreadyLinked] = $result;

        $response = [
            'account_created' => false,
            'requires_login' => false,
        ];

        if ($createdUser && $user) {
            try {
                $resetToken = Password::broker()->createToken($user);
                $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');
                $setupUrl = $frontend . '/reset-password?' . http_build_query([
                    'token' => $resetToken,
                    'email' => $user->email,
                ]);

                Mail::to($user->email)->send(new UserCredentialsMail($user, $setupUrl, $paidOrder));
            } catch (\Throwable $e) {
                Log::error('CHECKOUT_ACCOUNT_EMAIL_FAILED', [
                    'order_id' => $paidOrder->id,
                    'user_id' => $user->id,
                    'exception' => get_class($e),
                ]);
            }

            $response['account_created'] = true;
            $response['auth_token'] = $user->createToken('checkout_auto_login')->plainTextToken;
            $response['user'] = $user;
            $response['credentials_sent'] = true;
            return $response;
        }

        if ($guestCheckout && $user && $userWasAlreadyLinked) {
            $response['auth_token'] = $user->createToken('checkout_auto_login')->plainTextToken;
            $response['user'] = $user;
            $response['auto_login_reissued'] = true;
            return $response;
        }

        if ($guestCheckout && $user) {
            $response['requires_login'] = true;
        }

        return $response;
    }
}
