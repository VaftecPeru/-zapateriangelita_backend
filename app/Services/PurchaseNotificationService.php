<?php

namespace App\Services;

use App\Mail\PurchaseConfirmedMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class PurchaseNotificationService
{
    public function send(Order $order, ?User $user = null): bool
    {
        $order = Order::with('items')->find($order->id);

        if (!$order || $order->payment_status !== 'paid') {
            return false;
        }

        $recipient = strtolower(trim((string) ($order->customer_email ?: $user?->email)));
        if ($recipient === '') {
            Log::warning('PURCHASE_EMAIL_SKIPPED_NO_RECIPIENT', ['order_id' => $order->id]);
            return false;
        }

        if (
            Schema::hasColumn('orders', 'purchase_email_sent_at') &&
            $order->purchase_email_sent_at
        ) {
            return true;
        }

        $adminRecipient = strtolower(trim((string) config('mail.purchase_admin_address', '')));
        if ($adminRecipient !== '' && !filter_var($adminRecipient, FILTER_VALIDATE_EMAIL)) {
            Log::error('PURCHASE_EMAIL_INVALID_ADMIN_CONFIGURATION', ['order_id' => $order->id]);
            return false;
        }

        $claimed = true;
        if (Schema::hasColumn('orders', 'purchase_email_sent_at')) {
            $claimed = Order::whereKey($order->id)
                ->whereNull('purchase_email_sent_at')
                ->update(['purchase_email_sent_at' => now()]) === 1;
        }

        if (!$claimed) {
            return true;
        }

        try {
            $mail = Mail::to($recipient);
            if ($adminRecipient !== '' && $adminRecipient !== $recipient) {
                $mail->bcc($adminRecipient);
            }
            $mail->send(new PurchaseConfirmedMail($order->fresh('items'), $user));

            Log::info('PURCHASE_EMAIL_SENT', [
                'order_id' => $order->id,
                'user_id' => $user?->id ?? $order->user_id,
            ]);

            return true;
        } catch (\Throwable $e) {
            if (Schema::hasColumn('orders', 'purchase_email_sent_at')) {
                Order::whereKey($order->id)->update(['purchase_email_sent_at' => null]);
            }

            Log::error('PURCHASE_EMAIL_FAILED', [
                'order_id' => $order->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
