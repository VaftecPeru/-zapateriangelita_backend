<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpenpayInterruptedChargeRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'openpay.currency' => 'MXN',
            'openpay.merchant_id' => 'merchant_test',
            'openpay.public_key' => 'pk_test',
            'openpay.private_key' => 'sk_test',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.redirect_url' => 'https://example.test/checkout',
            'openpay.sandbox' => true,
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

        Schema::create('product_variant_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('color', 50);
            $table->string('size', 10);
            $table->unsignedInteger('stock')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('code', 40)->unique();
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
            $table->timestamp('purchase_email_sent_at')->nullable();
            $table->timestamp('stock_reserved_at')->nullable();
            $table->timestamp('stock_released_at')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_municipality')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->text('notes')->nullable();
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

    public function test_processing_without_transaction_is_reconciled_before_new_charge(): void
    {
        $product = Product::create(['name' => 'Zapato prueba', 'stock' => 2]);
        ProductSize::create(['product_id' => $product->id, 'size' => '40', 'stock' => 2]);
        ProductVariantStock::create([
            'product_id' => $product->id,
            'color' => 'Negro',
            'size' => '40',
            'stock' => 2,
        ]);

        $order = Order::create([
            'code' => 'ZA-RECOVERY-001',
            'status' => 'sin_preparar',
            'subtotal' => 216,
            'discount' => 0,
            'shipping_cost' => 0,
            'total' => 216,
            'payment_method' => 'openpay',
            'payment_status' => 'processing',
            'payment_reference' => 'ZA-RECOVERY-001-OLDREF',
            'payment_transaction_id' => null,
            'stock_reserved_at' => now(),
            'shipping_address' => 'Av. Prueba 123',
            'shipping_city' => 'Pachuca',
            'shipping_country' => 'México',
            'shipping_state' => 'Hidalgo',
            'shipping_municipality' => 'Pachuca de Soto',
            'shipping_postal_code' => '42000',
            'shipping_phone' => '+527711234567',
            'customer_name' => 'Cliente Prueba',
            'customer_email' => 'cliente@example.com',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'size' => '40',
            'color' => 'Negro',
            'quantity' => 1,
            'unit_price' => 216,
        ]);

        $checkoutToken = Crypt::encryptString(json_encode([
            'flow' => 'angelita_checkout_v2',
            'order_id' => $order->id,
            'email' => $order->customer_email,
            'guest' => true,
            'iat' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
            'nonce' => 'test-recovery',
        ]));

        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/charges')) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/charges')) {
                return Http::response([
                    'error_code' => 3004,
                    'description' => 'The card was declined',
                    'request_id' => 'req_declined',
                ], 402);
            }

            return Http::response([], 500);
        });

        $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $order->id,
            'checkout_token' => $checkoutToken,
            'token_id' => 'tok_second_attempt',
            'device_session_id' => 'device_session_test',
        ])
            ->assertStatus(422)
            ->assertJsonPath('payment_status', 'failed')
            ->assertJsonPath('stage', 'charge')
            ->assertJsonPath('error_code', '3004');

        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->payment_transaction_id);
        $this->assertSame(3, (int) $product->fresh()->stock);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && str_contains($request->url(), 'order_id=ZA-RECOVERY-001-OLDREF')
        );
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/charges')
            && $request['source_id'] === 'tok_second_attempt'
        );
    }

    public function test_existing_charge_is_recovered_without_posting_a_duplicate(): void
    {
        $product = Product::create(['name' => 'Zapato prueba', 'stock' => 2]);
        ProductSize::create(['product_id' => $product->id, 'size' => '40', 'stock' => 2]);

        $order = Order::create([
            'code' => 'ZA-RECOVERY-002',
            'status' => 'sin_preparar',
            'subtotal' => 216,
            'discount' => 0,
            'shipping_cost' => 0,
            'total' => 216,
            'payment_method' => 'openpay',
            'payment_status' => 'processing',
            'payment_reference' => 'ZA-RECOVERY-002-OLDREF',
            'payment_transaction_id' => null,
            'stock_reserved_at' => now(),
            'shipping_address' => 'Av. Prueba 123',
            'shipping_city' => 'Pachuca',
            'shipping_country' => 'México',
            'shipping_state' => 'Hidalgo',
            'shipping_municipality' => 'Pachuca de Soto',
            'shipping_postal_code' => '42000',
            'shipping_phone' => '+527711234567',
            'customer_name' => 'Cliente Prueba',
            'customer_email' => 'cliente2@example.com',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'size' => '40',
            'color' => null,
            'quantity' => 1,
            'unit_price' => 216,
        ]);

        $checkoutToken = Crypt::encryptString(json_encode([
            'flow' => 'angelita_checkout_v2',
            'order_id' => $order->id,
            'email' => $order->customer_email,
            'guest' => true,
            'iat' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
            'nonce' => 'test-existing-charge',
        ]));

        Http::fake([
            'https://sandbox-api.openpay.mx/v1/merchant_test/charges*' => Http::response([
                'data' => [[
                    'id' => 'tr_recovered_001',
                    'order_id' => 'ZA-RECOVERY-002-OLDREF',
                    'amount' => 216,
                    'currency' => 'MXN',
                    'status' => 'in_progress',
                    'payment_method' => ['type' => 'redirect', 'url' => 'https://openpay.test/3ds'],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $order->id,
            'checkout_token' => $checkoutToken,
            'token_id' => 'tok_must_not_be_used',
            'device_session_id' => 'device_session_test',
        ])
            ->assertStatus(202)
            ->assertJsonPath('payment_status', 'processing')
            ->assertJsonPath('transaction_id', 'tr_recovered_001')
            ->assertJsonPath('requires_redirect', true);

        $this->assertSame('tr_recovered_001', $order->fresh()->payment_transaction_id);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
