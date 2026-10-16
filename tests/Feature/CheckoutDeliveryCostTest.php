<?php

namespace Tests\Feature;

use App\Models\AdditionalService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\DeliveryCostService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutDeliveryCostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('user');
            $table->timestamps();
        });

        Schema::create('additional_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->nullable()->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('tag')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->nullable();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->decimal('discounted_price', 10, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->text('color_sizes')->nullable();
            $table->timestamps();
        });

        Schema::create('product_sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('size');
            $table->integer('stock')->default(0);
            $table->timestamps();
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
            $table->timestamp('stock_reserved_at')->nullable();
            $table->timestamp('stock_released_at')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_municipality')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_colony')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name');
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });
    }

    private function user(string $email, string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'role' => $role,
        ]);
    }

    public function test_delivery_service_cost_is_exposed_and_added_to_checkout_total(): void
    {
        AdditionalService::create([
            'name' => 'Costo de delivery',
            'code' => 'delivery',
            'description' => 'Entrega',
            'price' => 85.50,
            'tag' => 'Delivery',
            'is_active' => true,
        ]);

        $product = Product::create([
            'product_code' => 'ZA-DEL-001',
            'name' => 'Zapato de prueba',
            'price' => 500,
            'stock' => 10,
            'is_active' => true,
        ]);

        $deliveryResponse = $this->getJson('/api/checkout/delivery-cost')
            ->assertOk()
            ->assertJsonPath('code', 'delivery')
            ->assertJsonPath('price', 85.5);

        $this->assertStringContainsString(
            'no-store',
            (string) $deliveryResponse->headers->get('Cache-Control')
        );

        $response = $this->postJson('/api/checkout/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'customer_name' => 'Cliente Prueba',
            'customer_email' => 'cliente-delivery@example.com',
            'shipping_address' => 'Av. Prueba 123',
            'shipping_city' => 'Ciudad de México',
            'shipping_country' => 'México',
            'shipping_state' => 'Ciudad de México',
            'shipping_municipality' => 'Cuauhtémoc',
            'shipping_postal_code' => '06000',
            'shipping_colony' => 'Centro',
            'shipping_phone' => '+525512345678',
            'payment_method' => 'openpay',
        ]);

        $response->assertCreated()
            ->assertJsonPath('subtotal', 500)
            ->assertJsonPath('shipping_cost', 85.5)
            ->assertJsonPath('total', 585.5);

        $this->assertDatabaseHas('orders', [
            'shipping_cost' => 85.50,
            'shipping_colony' => 'Centro',
            'total' => 585.50,
        ]);
    }

    public function test_checkout_charge_sends_post_to_openpay_charges_resource(): void
    {
        config([
            'openpay.currency' => 'MXN',
            'openpay.merchant_id' => 'merchant_test',
            'openpay.public_key' => 'pk_test',
            'openpay.private_key' => 'sk_test',
            'openpay.sandbox' => true,
            'openpay.environment' => 'sandbox',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.redirect_url' => 'https://example.test/checkout',
        ]);

        $product = Product::create([
            'product_code' => 'ZA-OPENPAY-001',
            'name' => 'Zapato Openpay',
            'price' => 108,
            'stock' => 3,
        ]);

        $order = Order::create([
            'code' => 'ZA-OPENPAY-TEST-001',
            'status' => 'sin_preparar',
            'subtotal' => 108,
            'discount' => 0,
            'shipping_cost' => 40,
            'total' => 148,
            'payment_method' => 'openpay',
            'payment_status' => null,
            'shipping_address' => 'Calle prueba 123',
            'shipping_city' => 'La Barca',
            'shipping_country' => 'México',
            'shipping_state' => 'Jalisco',
            'shipping_municipality' => 'La Barca',
            'shipping_postal_code' => '47910',
            'shipping_colony' => 'Centro',
            'shipping_phone' => '+52123123123',
            'customer_name' => 'Carlos Prueba',
            'customer_email' => 'prueba32@gmail.com',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'size' => null,
            'color' => null,
            'quantity' => 1,
            'unit_price' => 108,
        ]);

        $checkoutToken = Crypt::encryptString(json_encode([
            'flow' => 'angelita_checkout_v2',
            'order_id' => $order->id,
            'email' => $order->customer_email,
            'guest' => true,
            'exp' => now()->addHour()->timestamp,
        ]));

        Http::fake(function ($request) {
            $payload = $request->data();

            return Http::response([
                'id' => 'tr_charge_test_001',
                'order_id' => $payload['order_id'],
                'amount' => $payload['amount'],
                'currency' => 'MXN',
                'status' => 'in_progress',
                'payment_method' => [
                    'type' => 'redirect',
                    'url' => 'https://sandbox-api.openpay.mx/v1/3dsecure/test',
                ],
            ], 200);
        });

        $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $order->id,
            'checkout_token' => $checkoutToken,
            'token_id' => 'tok_test_001',
            'device_session_id' => 'device_session_test_001',
        ])->assertStatus(202)
          ->assertJsonPath('payment_status', 'processing')
          ->assertJsonPath('requires_redirect', true);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://sandbox-api.openpay.mx/v1/merchant_test/charges'
                && ($payload['method'] ?? null) === 'card'
                && ($payload['source_id'] ?? null) === 'tok_test_001'
                && ($payload['device_session_id'] ?? null) === 'device_session_test_001'
                && ($payload['use_3d_secure'] ?? null) === true
                && (float) ($payload['amount'] ?? 0) === 148.0;
        });
    }

    public function test_inactive_delivery_service_cost_is_zero(): void
    {
        AdditionalService::create([
            'name' => 'Costo de delivery',
            'code' => 'delivery',
            'price' => 150,
            'tag' => 'Delivery',
            'is_active' => false,
        ]);

        $this->assertSame(0.0, app(DeliveryCostService::class)->current());

        $this->getJson('/api/checkout/delivery-cost')
            ->assertOk()
            ->assertJsonPath('price', 0)
            ->assertJsonPath('is_active', false);
    }

    public function test_admin_can_manage_delivery_cost(): void
    {
        $admin = $this->user('admin-delivery@example.com', 'admin');
        Sanctum::actingAs($admin);

        $this->putJson('/api/services/delivery', [
            'price' => 125.75,
            'is_active' => true,
            'description' => 'Tarifa de entrega configurada por administración.',
        ])->assertOk()
          ->assertJsonPath('service.code', 'delivery')
          ->assertJsonPath('service.name', 'Costo de delivery')
          ->assertJsonPath('service.price', '125.75')
          ->assertJsonPath('service.is_active', true);

        $this->getJson('/api/checkout/delivery-cost')
            ->assertOk()
            ->assertJsonPath('price', 125.75)
            ->assertJsonPath('is_active', true);
    }

    public function test_superadmin_can_deactivate_delivery_cost(): void
    {
        AdditionalService::create([
            'name' => 'Delivery antiguo',
            'code' => 'delivery',
            'price' => 99,
            'tag' => 'Delivery',
            'is_active' => true,
        ]);

        $superadmin = $this->user('soporte@vaftec.com.pe', 'superadmin');
        Sanctum::actingAs($superadmin);

        $this->putJson('/api/services/delivery', [
            'price' => 99,
            'is_active' => false,
            'description' => 'Delivery temporalmente desactivado.',
        ])->assertOk()
          ->assertJsonPath('service.name', 'Costo de delivery')
          ->assertJsonPath('service.is_active', false);

        $this->getJson('/api/checkout/delivery-cost')
            ->assertOk()
            ->assertJsonPath('price', 0)
            ->assertJsonPath('is_active', false);
    }

    public function test_final_user_cannot_manage_delivery_cost(): void
    {
        $customer = $this->user('cliente-delivery@example.com', 'user');
        Sanctum::actingAs($customer);

        $this->putJson('/api/services/delivery', [
            'price' => 10,
            'is_active' => true,
        ])->assertForbidden();
    }

    public function test_customer_purchase_history_includes_delivery_breakdown(): void
    {
        $customer = $this->user('historial-delivery@example.com', 'user');
        Sanctum::actingAs($customer);

        Order::create([
            'user_id' => $customer->id,
            'code' => 'ZA-DEL-HIST-001',
            'status' => 'sin_preparar',
            'subtotal' => 400,
            'discount' => 0,
            'shipping_cost' => 49.90,
            'total' => 449.90,
            'payment_method' => 'openpay',
            'payment_status' => 'paid',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
        ]);

        $this->getJson('/api/my-orders')
            ->assertOk()
            ->assertJsonPath('0.subtotal', '400.00')
            ->assertJsonPath('0.shipping_cost', '49.90')
            ->assertJsonPath('0.total', '449.90');
    }
}
