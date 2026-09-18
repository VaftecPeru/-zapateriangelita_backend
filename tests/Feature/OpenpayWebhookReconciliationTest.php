<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Services\CheckoutStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpenpayWebhookReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'openpay.currency' => 'MXN',
        ]);

        $this->createSchema();
    }

    private function createSchema(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('stock')->default(0);
            $table->timestamps();
        });

        Schema::create('product_sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('size', 10);
            $table->integer('stock')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('code', 20);
            $table->string('status', 30)->default('sin_preparar');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_status', 30)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('payment_transaction_id', 100)->nullable();
            $table->text('payment_error')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('stock_reserved_at')->nullable();
            $table->timestamp('stock_released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name')->nullable();
            $table->string('size', 10)->nullable();
            $table->string('color', 50)->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });
    }

    private function makeReservedOrder(): array
    {
        $product = Product::create(['name' => 'Zapato prueba', 'stock' => 3]);
        $size = ProductSize::create([
            'product_id' => $product->id,
            'size' => '40',
            'stock' => 3,
        ]);

        $order = new Order();
        $order->code = 'ZG-TEST-0001';
        $order->status = 'sin_preparar';
        $order->subtotal = 216;
        $order->discount = 0;
        $order->shipping_cost = 0;
        $order->total = 216;
        $order->payment_method = 'openpay';
        $order->payment_status = 'processing';
        $order->payment_reference = 'ZG-TEST-0001-ABC12345';
        $order->payment_transaction_id = 'tr_test_123';
        $order->stock_reserved_at = now();
        $order->save();

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'size' => '40',
            'color' => 'Negro',
            'quantity' => 2,
            'unit_price' => 108,
        ]);

        return [$order, $product, $size];
    }

    public function test_failed_webhook_releases_reserved_stock_only_once(): void
    {
        [$order, $product, $size] = $this->makeReservedOrder();

        $payload = [
            'type' => 'charge.failed',
            'transaction' => [
                'id' => 'tr_test_123',
                'order_id' => 'ZG-TEST-0001-ABC12345',
                'amount' => 216,
                'currency' => 'MXN',
                'error_message' => 'Tarjeta rechazada',
            ],
        ];

        $this->postJson('/api/openpay/webhook', $payload)
            ->assertOk()
            ->assertJson(['received' => true, 'reconciled' => true]);

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(5, (int) $size->fresh()->stock);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->stock_released_at);

        $this->postJson('/api/openpay/webhook', $payload)->assertOk();

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(5, (int) $size->fresh()->stock);
    }

    public function test_succeeded_webhook_marks_order_paid_without_releasing_stock(): void
    {
        [$order, $product, $size] = $this->makeReservedOrder();

        $this->postJson('/api/openpay/webhook', [
            'type' => 'charge.succeeded',
            'transaction' => [
                'id' => 'tr_test_123',
                'order_id' => 'ZG-TEST-0001-ABC12345',
                'amount' => 216,
                'currency' => 'MXN',
                'status' => 'completed',
            ],
        ])->assertOk()->assertJson(['reconciled' => true]);

        $order->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $size->fresh()->stock);
    }

    public function test_webhook_with_wrong_amount_is_not_reconciled(): void
    {
        [$order] = $this->makeReservedOrder();

        $this->postJson('/api/openpay/webhook', [
            'type' => 'charge.succeeded',
            'transaction' => [
                'id' => 'tr_test_123',
                'order_id' => 'ZG-TEST-0001-ABC12345',
                'amount' => 999,
                'currency' => 'MXN',
            ],
        ])->assertOk()->assertJson(['reconciled' => false]);

        $this->assertSame('processing', $order->fresh()->payment_status);
    }

    public function test_stock_service_does_not_release_unreserved_legacy_order(): void
    {
        [$order, $product, $size] = $this->makeReservedOrder();
        $order->stock_reserved_at = null;
        $order->save();

        app(CheckoutStockService::class)->release($order, 'fallo');

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $size->fresh()->stock);
        $this->assertSame('processing', $order->fresh()->payment_status);
    }
}
