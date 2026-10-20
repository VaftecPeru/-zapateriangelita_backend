<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductArticleModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Brand::create(['name' => 'Angelita', 'slug' => 'angelita', 'is_active' => true]);
        Category::create(['name' => 'Zapatillas', 'order' => 1, 'is_active' => true]);
    }

    private function productData(): array
    {
        return [
            'product_code' => '2001',
            'name' => 'Zapatilla Casual Dama',
            'model' => 'Modelo general 2001',
            'brand_id' => Brand::firstOrFail()->id,
            'category_id' => Category::firstOrFail()->id,
            'price' => 0,
            'stock' => 999, // El total real debe venir de las variantes.
            'size' => '38,39',
            'color' => 'Negro,Beige',
            'status' => 'normal',
            'variant_stocks' => json_encode([
                ['color' => 'Negro', 'article_model' => '2001-N', 'size' => '38', 'sku' => '2001-N-38', 'stock' => 2],
                ['color' => 'Negro', 'article_model' => '2001-N', 'size' => '39', 'sku' => '2001-N-39', 'stock' => 3],
                ['color' => 'Beige', 'article_model' => '2001-B', 'size' => '38', 'sku' => '2001-B-38', 'stock' => 4],
                ['color' => 'Beige', 'article_model' => '2001-B', 'size' => '39', 'sku' => '2001-B-39', 'stock' => 5],
            ]),
        ];
    }

    public function test_article_models_and_skus_persist_for_each_color_and_size(): void
    {
        $this->postJson('/api/products', $this->productData())
            ->assertCreated()
            ->assertJsonPath('is_active', false);
        $product = Product::where('product_code', '2001')->firstOrFail();
        $this->assertSame('Modelo general 2001', $product->model);
        $this->assertSame(14, (int) $product->stock);
        $this->assertSame(4, $product->variantStocks()->count());
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id,
            'color' => 'Negro', 'size' => '38',
            'article_model' => '2001-N', 'sku' => '2001-N-38', 'stock' => 2,
        ]);
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id,
            'color' => 'Beige', 'size' => '39',
            'article_model' => '2001-B', 'sku' => '2001-B-39', 'stock' => 5,
        ]);
        $updated = $this->productData();
        $updated['variant_stocks'] = json_encode([
            ['color' => 'Negro', 'article_model' => '2001-N', 'size' => '38', 'sku' => '2001-N-38', 'stock' => 2],
            ['color' => 'Negro', 'article_model' => '2001-N', 'size' => '39', 'sku' => '2001-N-39', 'stock' => 3],
            ['color' => 'Beige', 'article_model' => 'BEIGE-EDITADO', 'size' => '38', 'sku' => '2001-B-38', 'stock' => 4],
            ['color' => 'Beige', 'article_model' => 'BEIGE-EDITADO', 'size' => '39', 'sku' => '2001-B-39', 'stock' => 5],
        ]);
        $this->putJson('/api/products/' . $product->id, $updated)
            ->assertOk();
        $this->assertSame(14, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id, 'color' => 'Beige', 'size' => '38',
            'article_model' => 'BEIGE-EDITADO', 'sku' => '2001-B-38', 'stock' => 4,
        ]);
    }

    public function test_duplicate_skus_are_rejected_without_creating_products(): void
    {
        $payload = $this->productData();
        $data = json_decode($payload['variant_stocks'], true);
        $data[1]['sku'] = $data[0]['sku'];
        $payload['variant_stocks'] = json_encode($data);
        $this->postJson('/api/products', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_same_color_cannot_have_conflicting_article_models(): void
    {
        $payload = $this->productData();
        $data = json_decode($payload['variant_stocks'], true);
        $data[1]['article_model'] = 'UN-MODELO-DIFERENTE';
        $payload['variant_stocks'] = json_encode($data);
        $this->postJson('/api/products', $payload)->assertUnprocessable();
    }

    public function test_admin_can_search_by_color_model_or_specific_sku(): void
    {
        $this->postJson('/api/products', $this->productData())->assertCreated();
        $this->getJson('/api/admin/products?search=2001-B-39')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.product_code', '2001');
        $this->getJson('/api/admin/products?search=2001-N')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_simple_products_can_exist_without_variant_skus(): void
    {
        $data = $this->productData();
        $data['product_code'] = 'SIMPLE-01';
        $data['color'] = '';
        $data['size'] = '';
        $data['variant_stocks'] = '[]';
        $data['stock'] = 7;
        $this->postJson('/api/products', $data)->assertCreated();
        $product = Product::where('product_code', 'SIMPLE-01')->firstOrFail();
        $this->assertSame(0, $product->variantStocks()->count());
        $this->assertSame(7, (int) $product->stock);
    }
}
