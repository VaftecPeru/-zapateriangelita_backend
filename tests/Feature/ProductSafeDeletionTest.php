<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductSafeDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_remove_product_without_physical_deletion(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $product = Product::create([
            'name' => 'Zapato de prueba',
            'product_code' => 'TEST-DELETE-ADMIN',
            'price' => 90,
            'stock' => 3,
            'is_active' => true,
            'auto_publish' => true,
            'img' => '/api/media/uploads/products/not-a-real-file.webp',
        ]);

        $this->deleteJson('/api/products/'.$product->id)->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_active' => false,
            'auto_publish' => false,
        ]);
        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(
            '/api/media/uploads/products/not-a-real-file.webp',
            Product::withTrashed()->findOrFail($product->id)->img
        );
    }

    public function test_superadmin_can_remove_product_without_breaking_existing_order_item(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $product = Product::create([
            'name' => 'Artículo vendido',
            'product_code' => 'TEST-DELETE-ORDER',
            'price' => 120,
            'stock' => 2,
            'is_active' => true,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DEL-ORDER-001',
            'status' => 'entregado',
            'subtotal' => 120,
            'total' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'product_name' => 'Artículo vendido',
            'quantity' => 1,
            'unit_price' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson('/api/products/'.$product->id)->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', [
            'id' => $itemId,
            'order_id' => $orderId,
            'product_id' => $product->id,
            'product_name' => 'Artículo vendido',
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_client_cannot_delete_product(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        $product = Product::create([
            'name' => 'Producto protegido',
            'product_code' => 'TEST-DELETE-FORBIDDEN',
            'price' => 30,
            'stock' => 1,
            'is_active' => true,
        ]);
        $this->deleteJson('/api/products/'.$product->id)->assertForbidden();
        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_soft_delete_schema_is_present(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'deleted_at'));
    }
}
