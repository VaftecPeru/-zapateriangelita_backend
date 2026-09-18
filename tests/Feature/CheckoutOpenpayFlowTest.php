<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckoutOpenpayFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'openpay.merchant_id' => 'merchant_test',
            'openpay.public_key' => 'pk_test',
            'openpay.private_key' => 'sk_test',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.redirect_url' => 'https://zapateriangelita.com/checkout',
            'openpay.currency' => 'MXN',
            'openpay.sandbox' => true,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
        Mail::fake();
    }

    public function test_valid_card_reserves_stock_redirects_to_3ds_and_completes_payment(): void
    {
        [$product, $size] = $this->productWithStock(2);
        $orderResponse = $this->createGuestOrder($product);
        $checkoutToken = $orderResponse->json('checkout_token');
        $orderId = (int) $orderResponse->json('order_id');

        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/charges')) {
                $body = $request->data();

                return Http::response([
                    'id' => 'tr_3ds_ok',
                    'status' => 'in_progress',
                    'order_id' => $body['order_id'],
                    'amount' => $body['amount'],
                    'currency' => 'MXN',
                    'payment_method' => [
                        'type' => 'redirect',
                        'url' => 'https://sandbox-api.openpay.mx/3ds/test',
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $charge = $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $orderId,
            'checkout_token' => $checkoutToken,
            'token_id' => 'tok_test_4111',
            'device_session_id' => 'device_test_123',
        ]);

        $charge->assertStatus(202)
            ->assertJsonPath('payment_status', 'processing')
            ->assertJsonPath('stage', '3ds_required')
            ->assertJsonPath('requires_redirect', true)
            ->assertJsonPath('transaction_id', 'tr_3ds_ok');

        $this->assertSame(1, (int) $product->fresh()->stock);
        $this->assertSame(1, (int) $size->fresh()->stock);

        $order = Order::findOrFail($orderId);

        Http::fake([
            '*' => Http::response([
                'id' => 'tr_3ds_ok',
                'status' => 'completed',
                'order_id' => $order->payment_reference,
                'amount' => 108.00,
                'currency' => 'MXN',
            ], 200),
        ]);

        $verify = $this->getJson('/api/checkout/payments/openpay/verify?' . http_build_query([
            'order_id' => $orderId,
            'transaction_id' => 'tr_3ds_ok',
            'checkout_token' => $checkoutToken,
        ]));

        $verify->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('order_id', $orderId);

        $this->assertSame('paid', Order::findOrFail($orderId)->payment_status);
        $this->assertSame(1, User::count());
        $this->assertSame(1, (int) $product->fresh()->stock);
        $this->assertSame(1, (int) $size->fresh()->stock);
    }

    public function test_declined_charge_restores_reserved_stock(): void
    {
        [$product, $size] = $this->productWithStock(2);
        $orderResponse = $this->createGuestOrder($product);

        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/charges')) {
                return Http::response([
                    'error_code' => 3003,
                    'description' => 'Fondos insuficientes',
                    'request_id' => 'req_test',
                ], 422);
            }

            return Http::response([], 404);
        });

        $response = $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $orderResponse->json('order_id'),
            'checkout_token' => $orderResponse->json('checkout_token'),
            'token_id' => 'tok_declined',
            'device_session_id' => 'device_declined',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', '3003')
            ->assertJsonPath('payment_status', 'failed');

        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $size->fresh()->stock);
    }

    public function test_3ds_return_can_recover_session_only_with_matching_transaction(): void
    {
        [$product] = $this->productWithStock(2);
        $orderResponse = $this->createGuestOrder($product);
        $orderId = (int) $orderResponse->json('order_id');

        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'POST') {
                $body = $request->data();

                return Http::response([
                    'id' => 'tr_recovery',
                    'status' => 'in_progress',
                    'order_id' => $body['order_id'],
                    'amount' => $body['amount'],
                    'currency' => 'MXN',
                    'payment_method' => [
                        'type' => 'redirect',
                        'url' => 'https://sandbox-api.openpay.mx/3ds/recovery',
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $orderId,
            'checkout_token' => $orderResponse->json('checkout_token'),
            'token_id' => 'tok_recovery',
            'device_session_id' => 'device_recovery',
        ])->assertStatus(202);

        $order = Order::findOrFail($orderId);

        Http::fake([
            '*' => Http::response([
                'id' => 'tr_recovery',
                'status' => 'completed',
                'order_id' => $order->payment_reference,
                'amount' => 108.00,
                'currency' => 'MXN',
            ], 200),
        ]);

        $verify = $this->getJson('/api/checkout/payments/openpay/verify?' . http_build_query([
            'order_id' => $orderId,
            'transaction_id' => 'tr_recovery',
            'checkout_token' => 'token-local-invalido',
        ]));

        $verify->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('session_recovered', true)
            ->assertJsonPath('requires_login', true)
            ->assertJsonMissing(['auth_token']);
    }

    public function test_duplicate_webhook_is_idempotent_and_does_not_double_change_stock(): void
    {
        [$product, $size] = $this->productWithStock(2);
        $orderResponse = $this->createGuestOrder($product);
        $orderId = (int) $orderResponse->json('order_id');

        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'POST') {
                $body = $request->data();

                return Http::response([
                    'id' => 'tr_webhook',
                    'status' => 'in_progress',
                    'order_id' => $body['order_id'],
                    'amount' => $body['amount'],
                    'currency' => 'MXN',
                    'payment_method' => [
                        'type' => 'redirect',
                        'url' => 'https://sandbox-api.openpay.mx/3ds/webhook',
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $orderId,
            'checkout_token' => $orderResponse->json('checkout_token'),
            'token_id' => 'tok_webhook',
            'device_session_id' => 'device_webhook',
        ])->assertStatus(202);

        $order = Order::findOrFail($orderId);

        Http::fake([
            '*' => Http::response([
                'id' => 'tr_webhook',
                'status' => 'completed',
                'order_id' => $order->payment_reference,
                'amount' => 108.00,
                'currency' => 'MXN',
            ], 200),
        ]);

        $payload = [
            'type' => 'charge.succeeded',
            'transaction' => [
                'id' => 'tr_webhook',
                'order_id' => $order->payment_reference,
                'status' => 'completed',
            ],
        ];

        $this->postJson('/api/openpay/webhook', $payload)->assertOk();
        $this->postJson('/api/openpay/webhook', $payload)->assertOk();

        $this->assertSame('paid', Order::findOrFail($orderId)->payment_status);
        $this->assertSame(1, User::count());
        $this->assertSame(1, (int) $product->fresh()->stock);
        $this->assertSame(1, (int) $size->fresh()->stock);
    }

    private function createGuestOrder(Product $product)
    {
        $response = $this->postJson('/api/checkout/orders', [
            'items' => [[
                'product_id' => $product->id,
                'size' => '40',
                'color' => 'Negro',
                'quantity' => 1,
            ]],
            'customer_name' => 'Cliente Prueba',
            'customer_email' => 'cliente.test.angelita@gmail.com',
            'shipping_address' => 'Calle Prueba 123',
            'shipping_city' => 'Guadalajara',
            'shipping_country' => 'México',
            'shipping_state' => 'Jalisco',
            'shipping_municipality' => 'Guadalajara',
            'shipping_postal_code' => '44100',
            'shipping_phone' => '3312345678',
            'payment_method' => 'openpay',
        ]);

        $response->assertCreated();
        return $response;
    }

    private function productWithStock(int $stock): array
    {
        $product = Product::create([
            'name' => 'Zapato prueba',
            'price' => 108,
            'stock' => $stock,
            'size' => '40',
            'color' => 'Negro',
            'material' => 'Cuero',
            'rating' => 5,
            'reviews' => 0,
            'status' => 'normal',
            'color_sizes' => ['Negro' => ['40']],
        ]);

        $size = ProductSize::create([
            'product_id' => $product->id,
            'size' => '40',
            'stock' => $stock,
        ]);

        return [$product, $size];
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('role')->default('user');
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->decimal('discounted_price', 10, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->string('material')->nullable();
            $table->float('rating')->default(0);
            $table->integer('reviews')->default(0);
            $table->string('status')->default('normal');
            $table->json('color_sizes')->nullable();
            $table->timestamps();
        });

        Schema::create('product_sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('size', 10);
            $table->integer('stock')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'size']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('code')->unique();
            $table->string('status')->default('sin_preparar');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_transaction_id')->nullable();
            $table->text('payment_error')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('shipping_address')->nullable();
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
            $table->string('product_name');
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('municipality')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->text('address');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }
}
