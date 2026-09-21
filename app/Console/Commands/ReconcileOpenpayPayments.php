<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CpanelOpenpayReconciliationService;
use Illuminate\Console\Command;

class ReconcileOpenpayPayments extends Command
{
    protected $signature = 'checkout:reconcile-openpay {--limit=}';
    protected $description = 'Concilia pagos Openpay que quedaron en processing sin requerir workers ni Redis.';

    public function handle(CpanelOpenpayReconciliationService $service): int
    {
        $defaultLimit = (int) config('openpay.cpanel_reconcile_limit', 20);
        $limit = max(1, min(100, (int) ($this->option('limit') ?: $defaultLimit)));
        $minutes = max(5, (int) config('openpay.cpanel_reconcile_after_minutes', 10));

        $orders = Order::query()
            ->where('payment_status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = [];
        foreach ($orders as $order) {
            $result = $service->reconcile($order);
            $summary[$result] = ($summary[$result] ?? 0) + 1;
        }

        $this->info('Pagos revisados: ' . $orders->count());
        foreach ($summary as $state => $count) {
            $this->line("  {$state}: {$count}");
        }

        return self::SUCCESS;
    }
}
