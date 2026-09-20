<?php

namespace Tests\Feature;

use App\Models\AdditionalService;
use App\Models\Product;
use App\Services\DeliveryCostService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name');
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });
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
        ]);

        $this->getJson('/api/checkout/delivery-cost')
            ->assertOk()
            ->assertJsonPath('code', 'delivery')
            ->assertJsonPath('price', 85.5);

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
            'shipping_phone' => '+525512345678',
            'payment_method' => 'openpay',
        ]);

        $response->assertCreated()
            ->assertJsonPath('subtotal', 500)
            ->assertJsonPath('shipping_cost', 85.5)
            ->assertJsonPath('total', 585.5);

        $this->assertDatabaseHas('orders', [
            'shipping_cost' => 85.50,
            'total' => 585.50,
        ]);
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
            ->assertJsonPath('price', 0);
    }
}
