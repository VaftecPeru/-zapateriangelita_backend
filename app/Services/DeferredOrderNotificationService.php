<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DeferredOrderNotificationService
{
    public function schedule(
        Order $order,
        ?User $user = null,
        ?string $temporaryPassword = null,
        bool $sendTemporaryPassword = false,
    ): void {
        $orderId = (int) $order->id;

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
