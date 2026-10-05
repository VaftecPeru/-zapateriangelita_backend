<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
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
            $table->string('password');
            $table->string('role')->default('user');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->nullable();
            $table->string('name');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->string('material')->nullable();
            $table->string('status')->default('normal');
            $table->string('img')->nullable();
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

        Schema::create('product_variant_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('color', 50);
            $table->string('size', 10);
            $table->unsignedInteger('stock')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'color', 'size']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('color', 50)->nullable();
            $table->string('size', 10)->nullable();
            $table->string('type', 20);
            $table->integer('quantity_change');
            $table->unsignedInteger('previous_stock');
            $table->unsignedInteger('new_stock');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function test_inventory_routes_are_not_public(): void
    {
        $this->getJson('/api/admin/inventory')->assertUnauthorized();
        $this->postJson('/api/admin/inventory/adjust', [])->assertUnauthorized();
    }

    public function test_regular_user_cannot_manage_inventory(): void
    {
        Sanctum::actingAs($this->user('user'));

        $this->getJson('/api/admin/inventory')->assertForbidden();
    }

    public function test_admin_can_adjust_variant_and_inventory_stays_consistent(): void
    {
        $admin = $this->user('admin');
        $product = $this->product(8);
        $variant = ProductVariantStock::create([
            'product_id' => $product->id,
            'color' => 'Negro',
            'size' => '38',
            'stock' => 8,
        ]);
        ProductSize::create([
            'product_id' => $product->id,
            'size' => '38',
            'stock' => 8,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'type' => 'salida',
            'quantity' => 3,
            'reason' => 'Ajuste operativo',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(5, ProductSize::where('product_id', $product->id)->where('size', '38')->value('stock'));
        $this->assertSame(5, $product->fresh()->stock);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'user_id' => $admin->id,
            'type' => 'salida',
            'quantity_change' => -3,
            'previous_stock' => 8,
            'new_stock' => 5,
        ]);
    }

    public function test_stock_cannot_become_negative(): void
    {
        $admin = $this->user('admin');
        $product = $this->product(2);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id,
            'type' => 'salida',
            'quantity' => 3,
            'reason' => 'Salida manual',
        ])->assertStatus(422);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_manual_adjustment_requires_reason(): void
    {
        $admin = $this->user('admin');
        $product = $this->product(10);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id,
            'type' => 'ajuste',
            'quantity' => 7,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Indica el motivo del ajuste manual para conservar la trazabilidad.');

        $this->assertSame(10, $product->fresh()->stock);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role . '@angelita.test',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
        ]);
    }

    private function product(int $stock): Product
    {
        return Product::create([
            'product_code' => 'ZAP-INV-001',
            'name' => 'Zapato Inventario',
            'price' => 1200,
            'stock' => $stock,
            'size' => '38',
            'color' => 'Negro',
            'material' => 'Cuero',
            'status' => 'normal',
        ]);
    }
}
