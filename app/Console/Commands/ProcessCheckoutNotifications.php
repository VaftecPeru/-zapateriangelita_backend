<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CpanelOrderNotificationService;
use Illuminate\Console\Command;

class ProcessCheckoutNotifications extends Command
{
    protected $signature = 'checkout:process-notifications {--limit=25}';
    protected $description = 'Reintenta correos de compra y credenciales pendientes en hosting cPanel.';

    public function handle(CpanelOrderNotificationService $service): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));

        $orders = Order::query()
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNull('purchase_email_sent_at')
                    ->orWhere(function ($credentials) {
                        $credentials->whereNotNull('credential_email_ciphertext')
                            ->whereNull('credential_email_sent_at');
                    });
            })
            ->where(function ($query) {
                $query->whereNull('notification_next_attempt_at')
                    ->orWhere('notification_next_attempt_at', '<=', now());
            })
            ->orderBy('notification_next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($orders as $order) {
            $service->processById((int) $order->id);
            $processed++;
        }

        $this->info("Notificaciones revisadas: {$processed}");
        return self::SUCCESS;
    }
}
