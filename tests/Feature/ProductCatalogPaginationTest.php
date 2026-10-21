<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCatalogPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_page_through_drafts_with_null_catalog_relations(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $timestamp = now()->startOfSecond();
        $ids = [];
        for ($i = 1; $i <= 26; $i++) {
            $product = Product::create([
                'product_code' => 'IMP-'.$i, 'name' => 'Importado '.$i,
                'brand_id' => null, 'category_id' => null,
                'price' => 0, 'stock' => 3, 'is_active' => false,
            ]);
            $product->timestamps = false;
            $product->forceFill(['updated_at' => $timestamp])->saveQuietly();
            $ids[] = $product->id;
        }
        // All imports may share updated_at; id must make page ordering deterministic.
        $expected = array_reverse($ids);
        $first = $this->getJson('/api/admin/products?per_page=25&page=1')
            ->assertOk()->assertJsonPath('total', 26)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.brand', null)->assertJsonPath('data.0.category', null)
            ->assertJsonPath('data.0.is_active', false);
        $second = $this->getJson('/api/admin/products?per_page=25&page=2')->assertOk();
        $this->assertSame(array_slice($expected, 0, 25), array_column($first->json('data'), 'id'));
        $this->assertSame(array_slice($expected, 25), array_column($second->json('data'), 'id'));
        $this->getJson('/api/admin/products?search=IMP-26')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/products?active_state=active')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_public_catalog_has_deterministic_pages_for_equal_timestamps(): void
    {
        $brand = Brand::create(['name' => 'Pagination QA', 'slug' => 'pagination-qa', 'is_active' => true]);
        $category = Category::create(['name' => 'Hombre', 'is_active' => true]);
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::create([
                'product_code' => 'PUB-'.$i, 'name' => 'Público '.$i,
                'brand_id' => $brand->id, 'category_id' => $category->id,
                'price' => 500, 'stock' => 2, 'is_active' => true,
            ]);
            $product->timestamps = false;
            $product->forceFill(['updated_at' => '2026-10-21 12:00:00'])->saveQuietly();
            $ids[] = $product->id;
        }
        $first = $this->getJson('/api/products?per_page=2')->assertOk();
        $second = $this->getJson('/api/products?per_page=2&page=2')->assertOk();
        $this->assertSame(array_reverse($ids), array_merge(
            array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'),
        ));
    }

    public function test_page_size_validation_and_admin_access_remain_enforced(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([0, 201] as $pageSize) {
            $this->getJson('/api/admin/products?per_page='.$pageSize)
                ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        }
    }
}
