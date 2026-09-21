<?php

namespace App\Services;

use App\Mail\TemporaryPasswordMail;
use App\Models\Order;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CpanelOrderNotificationService
{
    public function queue(
        Order $order,
        ?string $temporaryPassword = null,
        bool $sendTemporaryPassword = false
    ): void {
        $updates = [
            'notification_next_attempt_at' => now(),
            'notification_last_error' => null,
        ];

        if ($sendTemporaryPassword && $temporaryPassword) {
            $updates['credential_email_ciphertext'] = Crypt::encryptString($temporaryPassword);
            $updates['credential_email_sent_at'] = null;
        }

        Order::whereKey($order->id)->update($updates);
    }

    public function processById(int $orderId): bool
    {
        $order = Order::with('items', 'user')->find($orderId);
        if (!$order || $order->payment_status !== 'paid') {
            return false;
        }

        $errors = [];

        if (!$order->purchase_email_sent_at) {
            $sent = app(PurchaseNotificationService::class)->send($order, $order->user);
            if (!$sent) {
                $errors[] = 'No se pudo enviar la confirmación de compra.';
            }
            $order->refresh();
        }

        if ($order->credential_email_ciphertext && !$order->credential_email_sent_at) {
            if (!$order->user) {
                $errors[] = 'El pedido pagado no tiene usuario asociado para enviar credenciales.';
            } else {
                try {
                    $temporaryPassword = Crypt::decryptString($order->credential_email_ciphertext);
                    Mail::to($order->user->email)->send(
                        new TemporaryPasswordMail($order->user, $temporaryPassword)
                    );

                    Order::whereKey($order->id)->update([
                        'credential_email_ciphertext' => null,
                        'credential_email_sent_at' => now(),
                    ]);

                    Log::info('CHECKOUT_CREDENTIAL_EMAIL_SENT', [
                        'order_id' => $order->id,
                        'user_id' => $order->user->id,
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = 'No se pudo enviar el correo de credenciales.';
                    Log::error('CHECKOUT_CREDENTIAL_EMAIL_FAILED', [
                        'order_id' => $order->id,
                        'user_id' => $order->user?->id,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        $order->refresh();

        $pendingPurchase = !$order->purchase_email_sent_at;
        $pendingCredentials = (bool) $order->credential_email_ciphertext && !$order->credential_email_sent_at;

        if (!$pendingPurchase && !$pendingCredentials) {
            Order::whereKey($order->id)->update([
                'notification_attempts' => 0,
                'notification_next_attempt_at' => null,
                'notification_last_error' => null,
            ]);
            return true;
        }

        $attempts = ((int) $order->notification_attempts) + 1;
        $delayMinutes = $this->backoffMinutes($attempts);

        Order::whereKey($order->id)->update([
            'notification_attempts' => $attempts,
            'notification_next_attempt_at' => now()->addMinutes($delayMinutes),
            'notification_last_error' => implode(' ', array_unique($errors)) ?: 'Notificación pendiente.',
        ]);

        return false;
    }

    private function backoffMinutes(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 2,
            $attempts === 2 => 5,
            $attempts === 3 => 15,
            $attempts === 4 => 30,
            default => 60,
        };
    }
}
