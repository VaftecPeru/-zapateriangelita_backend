<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Support\Facades\DB;

class CheckoutStockService
{
    /**
     * Libera una reserva de inventario de forma idempotente.
     */
    public function release(Order $order, string $reason): void
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
}
