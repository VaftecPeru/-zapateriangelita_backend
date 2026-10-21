<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductColorPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductColorPricesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Brand::create(['name' => 'Angelita', 'slug' => 'angelita', 'is_active' => true]);
        Category::create(['name' => 'Mujer', 'order' => 1, 'is_active' => true]);
    }

    private function payload(): array
    {
        return [
            'product_code' => 'COLOR-PRICE-01',
            'name' => 'Zapato de prueba',
            'model' => 'CP01',
            'category_id' => Category::firstOrFail()->id,
            'brand_id' => Brand::firstOrFail()->id,
            'price' => 100,
            'discount' => 10,
            'status' => 'normal',
            'color' => 'Negro,Beige',
            'size' => '38',
            'stock' => 2,
            'variant_stocks' => json_encode([
                ['color' => 'Negro', 'size' => '38', 'stock' => 1, 'article_model' => 'CP01-N'],
                ['color' => 'Beige', 'size' => '38', 'stock' => 1, 'article_model' => 'CP01-B'],
            ]),
        ];
    }

    public function test_persists_color_specific_price_and_inherits_general_price(): void
    {
        $this->assertTrue(Schema::hasColumn('product_colors', 'price'));
        $data = $this->payload();
        $data['color_prices'] = json_encode(['Negro' => 145.50]);
        $this->postJson('/api/products', $data)->assertCreated();
        $product = Product::firstOrFail();
        $this->assertDatabaseHas('product_colors', ['product_id' => $product->id, 'color' => 'Negro', 'price' => 145.50]);
        $this->assertDatabaseHas('product_colors', ['product_id' => $product->id, 'color' => 'Beige', 'price' => null]);
        $service = app(ProductColorPricingService::class);
        $this->assertSame(130.95, $service->salePrice($product, 'Negro'));
        $this->assertSame(90.0, $service->salePrice($product, 'Beige'));
        $this->assertSame(2, (int) $product->stock);
    }

    public function test_changing_general_price_does_not_overwrite_color_override(): void
    {
        $data = $this->payload();
        $data['color_prices'] = json_encode(['Negro' => 145.50]);
        $this->postJson('/api/products', $data)->assertCreated();
        $product = Product::firstOrFail();
        $variantIds = $product->variantStocks()->orderBy('id')->pluck('id')->all();
        $this->putJson('/api/products/'.$product->id, [
            'price' => 120, 'color_prices' => json_encode(['Negro' => 145.50]),
        ])->assertOk();
        $product = $product->fresh();
        $service = app(ProductColorPricingService::class);
        $this->assertSame(130.95, $service->salePrice($product, 'Negro'));
        $this->assertSame(108.0, $service->salePrice($product, 'Beige'));
        $this->assertSame($variantIds, $product->variantStocks()->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, (int) $product->stock);
    }

    public function test_empty_prices_reset_to_general_and_legacy_updates_preserve_custom_price(): void
    {
        $data = $this->payload();
        $data['color_prices'] = json_encode(['Negro' => 145.50]);
        $this->postJson('/api/products', $data)->assertCreated();
        $product = Product::firstOrFail();
        $this->putJson('/api/products/'.$product->id, ['description' => 'Cambio heredado'])->assertOk();
        $this->assertSame('145.50', $product->colors()->where('color', 'Negro')->firstOrFail()->price);
        $this->putJson('/api/products/'.$product->id, ['color_prices' => '{}'])->assertOk();
        $this->assertNull($product->colors()->where('color', 'Negro')->firstOrFail()->price);
    }

    public function test_negative_or_invalid_color_price_is_rejected(): void
    {
        foreach (['{"Negro":-1}', '{"Negro":"no-numero"}', '{"Negro":0}', '[100,200]'] as $invalid) {
            $data = $this->payload();
            $data['color_prices'] = $invalid;
            $this->postJson('/api/products', $data)
                ->assertUnprocessable()->assertJsonValidationErrors('color_prices');
        }
        $this->assertDatabaseCount('products', 0);
    }
}
