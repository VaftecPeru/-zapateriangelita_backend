<?php

namespace App\Services;

use App\Mail\TemporaryPasswordMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DeferredOrderNotificationService
{
    public function schedule(
        Order $order,
        ?User $user = null,
        ?string $temporaryPassword = null,
        bool $sendTemporaryPassword = false,
    ): void {
        $orderId = (int) $order->id;
        $userId = $user?->id;
        $temporaryPasswordValue = $temporaryPassword;

        app()->terminating(function () use (
            $orderId,
            $userId,
            $temporaryPasswordValue,
            $sendTemporaryPassword,
        ) {
            try {
                $order = Order::with('items')->find($orderId);
                $user = $userId ? User::find($userId) : null;

                if (!$order || $order->payment_status !== 'paid') {
                    return;
                }

                app(PurchaseNotificationService::class)->send($order, $user);

                if ($sendTemporaryPassword && $user && $temporaryPasswordValue) {
                    Mail::to($user->email)->send(
                        new TemporaryPasswordMail($user, $temporaryPasswordValue)
                    );

                    Log::info('CHECKOUT_CREDENTIAL_EMAIL_SENT', [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('DEFERRED_ORDER_NOTIFICATION_FAILED', [
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
