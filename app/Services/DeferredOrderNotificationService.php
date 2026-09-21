<?php

namespace App\Services;

use App\Mail\TemporaryPasswordMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

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

        // Compatibilidad durante el despliegue: si el código llega antes que la
        // migración de cPanel, conserva el comportamiento anterior y no rompe el checkout.
        if (!Schema::hasColumn('orders', 'notification_next_attempt_at')) {
            app()->terminating(function () use (
                $orderId,
                $userId,
                $temporaryPassword,
                $sendTemporaryPassword,
            ) {
                try {
                    $currentOrder = Order::with('items')->find($orderId);
                    $currentUser = $userId ? User::find($userId) : null;

                    if (!$currentOrder || $currentOrder->payment_status !== 'paid') {
                        return;
                    }

                    app(PurchaseNotificationService::class)->send($currentOrder, $currentUser);

                    if ($sendTemporaryPassword && $currentUser && $temporaryPassword) {
                        Mail::to($currentUser->email)->send(
                            new TemporaryPasswordMail($currentUser, $temporaryPassword)
                        );
                    }
                } catch (\Throwable $e) {
                    Log::error('DEFERRED_ORDER_NOTIFICATION_LEGACY_FAILED', [
                        'order_id' => $orderId,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                }
            });
            return;
        }

        try {
            // Persistimos primero el pendiente para que un cierre prematuro de PHP-FPM
            // o una caída SMTP no pierda el correo de compra ni las credenciales.
            app(CpanelOrderNotificationService::class)->queue(
                $order,
                $temporaryPassword,
                $sendTemporaryPassword,
            );
        } catch (\Throwable $e) {
            Log::error('CHECKOUT_NOTIFICATION_QUEUE_FAILED', [
                'order_id' => $orderId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return;
        }

        // Primer intento después de entregar la respuesta HTTP. Si falla, queda
        // persistido y el cron de cPanel lo retomará automáticamente.
        app()->terminating(function () use ($orderId) {
            try {
                app(CpanelOrderNotificationService::class)->processById($orderId);
            } catch (\Throwable $e) {
                Log::error('DEFERRED_ORDER_NOTIFICATION_FAILED', [
                    'order_id' => $orderId,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
