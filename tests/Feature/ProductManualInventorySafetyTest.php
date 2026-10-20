<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductManualInventorySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Brand::create(['name' => 'QA Angelita', 'slug' => 'qa-angelita', 'is_active' => true]);
        Category::create(['name' => 'QA Mujer', 'order' => 1, 'is_active' => true]);
    }

    private function payload(string $code): array
    {
        return [
            'product_code' => $code,
            'name' => 'Zapato QA',
            'model' => 'QA-MODEL',
            'brand_id' => Brand::firstOrFail()->id,
            'category_id' => Category::firstOrFail()->id,
            'price' => 1450,
            'stock' => 9,
            'size' => '3,4',
            'color' => 'Negro',
            'status' => 'normal',
        ];
    }

    public function test_multi_size_manual_entry_without_breakdown_is_draft_with_zero_sellable_size_stock(): void
    {
        $this->postJson('/api/products', $this->payload('QA-NO-BREAKDOWN'))
            ->assertCreated()->assertJsonPath('is_active', false);
        $product = Product::where('product_code', 'QA-NO-BREAKDOWN')->firstOrFail();
        $this->assertSame(9, (int) $product->stock);
        $this->assertSame(0, (int) $product->sizes()->sum('stock'));
        $this->assertSame(0, $product->variantStocks()->count());
        $this->assertSame('manual', $product->import_pending_data['source']);
        $this->assertSame(9, $product->import_pending_data['unallocated_stock']);
        $this->assertFalse((bool) $product->is_active);
    }

    public function test_exact_color_size_quantities_override_declared_total_and_allow_publication(): void
    {
        $payload = $this->payload('QA-EXACT-VARIANTS');
        $payload['variant_stocks'] = json_encode([
            ['color' => 'Negro', 'size' => '3', 'stock' => 2],
            ['color' => 'Negro', 'size' => '4', 'stock' => 3],
        ]);
        $this->postJson('/api/products', $payload)
            ->assertCreated()->assertJsonPath('is_active', true);
        $product = Product::where('product_code', 'QA-EXACT-VARIANTS')->firstOrFail();
        $this->assertSame(5, (int) $product->stock);
        $this->assertSame(2, $product->variantStocks()->count());
        $this->assertSame(5, (int) $product->variantStocks()->sum('stock'));
    }

    public function test_single_size_manual_stock_can_remain_directly_allocated(): void
    {
        $payload = $this->payload('QA-SINGLE-SIZE');
        $payload['size'] = '3';
        $payload['stock'] = 4;
        $this->postJson('/api/products', $payload)->assertCreated();
        $product = Product::where('product_code', 'QA-SINGLE-SIZE')->firstOrFail();
        $this->assertSame(4, (int) $product->sizes()->sum('stock'));
        $this->assertNull($product->import_pending_data);
    }
}
