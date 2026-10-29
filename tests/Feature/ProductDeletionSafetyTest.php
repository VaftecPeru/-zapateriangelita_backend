<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductDeletionSafetyTest extends TestCase
{
    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // Se necesitan commits reales para verificar el filesystem tras afterCommit.
        // Recrear la BD aislada evita ejecutar down() históricos durante teardown.
        $this->assertTrue(app()->environment('testing'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->mediaRoot = sys_get_temp_dir() . '/angelita-delete-' . bin2hex(random_bytes(8));
        File::makeDirectory($this->mediaRoot . '/uploads/products', 0755, true);
        config(['media.root' => $this->mediaRoot]);
        file_put_contents($this->mediaRoot . '/uploads/products/test.jpg', 'test-image');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);
        parent::tearDown();
    }

    private function product(): Product
    {
        return Product::create([
            'product_code' => 'DELETE-QA', 'name' => 'Zapato QA', 'model' => 'QA',
            'brand_id' => Brand::create(['name' => 'QA', 'slug' => 'qa', 'is_active' => true])->id,
            'category_id' => Category::create(['name' => 'QA', 'order' => 1, 'is_active' => true])->id,
            'price' => 100, 'stock' => 0, 'status' => 'normal',
            'img' => '/uploads/products/test.jpg',
        ]);
    }

    public function test_unreferenced_product_and_image_are_deleted(): void
    {
        $product = $this->product();
        $this->deleteJson('/api/products/' . $product->id)->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertFileDoesNotExist($this->mediaRoot . '/uploads/products/test.jpg');
    }

    public function test_order_history_prevents_deletion_and_preserves_image(): void
    {
        $product = $this->product();
        $order = Order::create(['code' => 'DELETE-ORDER', 'total' => 100]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => 'Zapato QA', 'quantity' => 1, 'unit_price' => 100,
        ]);
        $this->assertConflictPreservesProduct($product);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id]);
    }

    public function test_inventory_history_is_not_deleted_by_cascade(): void
    {
        $product = $this->product();
        DB::table('inventory_movements')->insert([
            'product_id' => $product->id, 'type' => 'ajuste',
            'quantity_change' => 0, 'previous_stock' => 0, 'new_stock' => 0,
        ]);
        $this->assertConflictPreservesProduct($product);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id]);
    }

    public function test_other_foreign_key_conflict_rolls_back_without_removing_image(): void
    {
        $product = $this->product();
        Schema::create('qa_product_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
        });
        try {
            DB::table('qa_product_references')->insert(['product_id' => $product->id]);
            $this->assertConflictPreservesProduct($product);
        } finally {
            Schema::dropIfExists('qa_product_references');
        }
    }

    private function assertConflictPreservesProduct(Product $product): void
    {
        $this->deleteJson('/api/products/' . $product->id)
            ->assertStatus(409)->assertJsonPath('error_code', 'PRODUCT_HAS_RELATED_RECORDS');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertFileExists($this->mediaRoot . '/uploads/products/test.jpg');
    }
}
