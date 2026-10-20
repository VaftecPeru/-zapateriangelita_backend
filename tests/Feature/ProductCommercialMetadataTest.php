<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCommercialMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Brand::create(['name' => 'Angelita', 'slug' => 'angelita', 'is_active' => true]);
        Category::create(['name' => 'Zapatillas', 'order' => 1, 'is_active' => true]);
    }

    private function payload(string $code = 'ANG-2001'): array
    {
        return [
            'product_code' => $code,
            'name' => 'Zapatilla Casual Dama',
            'model' => '2001',
            'brand_id' => Brand::firstOrFail()->id,
            'category_id' => Category::firstOrFail()->id,
            'price' => 129.90,
            'stock' => 7,
            'size' => '38',
            'color' => 'Negro',
            'status' => 'normal',
            'gender' => 'mujer',
            'barcode' => '7501234567890',
            'collection_name' => 'Primavera',
            'seo_title' => 'Zapatilla Casual Dama | Angelita',
            'seo_description' => 'Zapatilla cómoda, diseño moderno',
            'variant_stocks' => json_encode([
                ['color' => 'Negro', 'size' => '38', 'stock' => 7, 'article_model' => '2001-N', 'sku' => '2001-N-38'],
            ]),
        ];
    }

    public function test_migration_adds_nullable_commercial_columns(): void
    {
        foreach (['gender', 'barcode', 'collection_name', 'seo_title', 'seo_description', 'product_type'] as $column) {
            $this->assertTrue(Schema::hasColumn('products', $column), $column);
        }
    }

    public function test_explicit_draft_keeps_full_priced_product_private_and_persists_metadata(): void
    {
        $data = $this->payload();
        $data['is_active'] = false;
        $this->postJson('/api/products', $data)
            ->assertCreated()
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('auto_publish', false)
            ->assertJsonPath('gender', 'mujer')
            ->assertJsonPath('barcode', '7501234567890')
            ->assertJsonPath('collection_name', 'Primavera')
            ->assertJsonPath('seo_title', 'Zapatilla Casual Dama | Angelita');

        $product = Product::where('product_code', 'ANG-2001')->firstOrFail();
        $this->assertSame(7, (int) $product->stock);
        $this->assertSame('2001-N', $product->variantStocks()->firstOrFail()->article_model);
        $this->getJson('/api/admin/products?search=ANG-2001')
            ->assertOk()->assertJsonPath('data.0.gender', 'mujer');
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
    }

    public function test_explicit_publish_after_draft_respects_catalog_rules_and_retains_variants(): void
    {
        $data = $this->payload();
        $data['is_active'] = false;
        $this->postJson('/api/products', $data)->assertCreated();
        $product = Product::where('product_code', 'ANG-2001')->firstOrFail();
        $variantId = $product->variantStocks()->firstOrFail()->id;

        $this->putJson('/api/products/'.$product->id, [
            'is_active' => true,
            'gender' => 'unisex',
            'collection_name' => 'Colección especial',
            'seo_title' => 'Nueva publicación',
            'barcode' => '7501234567890',
        ])->assertOk()->assertJsonPath('is_active', true)->assertJsonPath('auto_publish', true)
            ->assertJsonPath('gender', 'unisex');

        $this->assertSame($variantId, $product->fresh()->variantStocks()->firstOrFail()->id);
        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->getJson('/api/products/'.$product->id)->assertOk();
    }

    public function test_draft_can_be_created_with_price_and_existing_auto_publish_remains_compatible(): void
    {
        $data = $this->payload();
        $this->postJson('/api/products', $data)->assertCreated()
            ->assertJsonPath('is_active', true)->assertJsonPath('auto_publish', true);

        $other = $this->payload('ANG-2002');
        $other['barcode'] = '7501234567891';
        $other['variant_stocks'] = json_encode([['color' => 'Negro', 'size' => '38', 'stock' => 7, 'article_model' => '2002-N', 'sku' => '2002-N-38']]);
        $other['is_active'] = false;
        $this->postJson('/api/products', $other)->assertCreated()
            ->assertJsonPath('is_active', false)->assertJsonPath('auto_publish', false);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_duplicate_barcode_and_invalid_gender_are_rejected(): void
    {
        $this->postJson('/api/products', $this->payload())->assertCreated();
        $other = $this->payload('ANG-2002');
        $this->postJson('/api/products', $other)
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');

        $invalid = $this->payload('ANG-2003');
        $invalid['barcode'] = '7501234567892';
        $invalid['gender'] = 'valor-no-admitido';
        $this->postJson('/api/products', $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_can_unpublish_without_erasing_commercial_data(): void
    {
        $this->postJson('/api/products', $this->payload())->assertCreated();
        $product = Product::firstOrFail();
        $this->putJson('/api/products/'.$product->id, ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('auto_publish', false);
        $this->assertSame('7501234567890', $product->fresh()->barcode);
        $this->assertSame('mujer', $product->fresh()->gender);
        $this->assertSame(7, (int) $product->fresh()->stock);
    }
    public function test_explicit_simple_product_publishes_without_artificial_variant_stock(): void
    {
        $data = $this->payload('SIMPLE-1');
        $data['product_type'] = 'simple';
        $data['size'] = '';
        $data['color'] = '';
        $data['variant_stocks'] = '[]';
        $data['stock'] = 8;
        $this->postJson('/api/products', $data)
            ->assertCreated()->assertJsonPath('is_active', true)
            ->assertJsonPath('product_type', 'simple')->assertJsonPath('stock', 8);

        $product = Product::where('product_code', 'SIMPLE-1')->firstOrFail();
        $this->assertSame(0, $product->variantStocks()->count());
        $this->assertSame(8, (int) $product->stock);
        $this->getJson('/api/products/'.$product->id)->assertOk();
    }

    public function test_switching_variants_to_simple_preserves_declared_total_and_removes_variant_rows(): void
    {
        $this->postJson('/api/products', $this->payload())->assertCreated();
        $product = Product::where('product_code', 'ANG-2001')->firstOrFail();
        $this->putJson('/api/products/'.$product->id, [
            'product_type' => 'simple',
            'size' => '',
            'color' => '',
            'variant_stocks' => '[]',
            'color_sizes' => '{}',
            'stock' => 7,
        ])->assertOk()->assertJsonPath('stock', 7)
            ->assertJsonPath('product_type', 'simple');
        $this->assertSame(0, $product->fresh()->variantStocks()->count());
        $this->assertSame(7, (int) $product->fresh()->stock);
    }

}
