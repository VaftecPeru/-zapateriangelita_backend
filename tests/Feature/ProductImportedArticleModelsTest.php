<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImportedArticleModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Brand::create(['name' => 'Angelita', 'slug' => 'angelita', 'is_active' => true]);
        Category::create(['name' => 'Zapatillas', 'order' => 1, 'is_active' => true]);
    }

    private function file(string $beigeModel = '2001-B'): UploadedFile
    {
        $headers = [
            'ACCIÓN','CÓDIGO_PRODUCTO','NOMBRE','MODELO','MARCA','CATEGORÍA','SUBCATEGORÍA',
            'PRECIO','PRECIO_OFERTA','MATERIAL','COLOR','TALLA','STOCK','DESCRIPCIÓN',
            'IMAGEN_PRINCIPAL','IMAGEN_2','IMAGEN_3','ESTADO','OBSERVACIONES','VALIDACIÓN',
        ];
        $rows = [
            ['CREAR','2001','Zapatilla Casual Dama','2001-N','Angelita','Zapatillas','','0','','','Negro','38','3','','','','','BORRADOR','','OK'],
            ['CREAR','2001','Zapatilla Casual Dama',$beigeModel,'Angelita','Zapatillas','','0','','','Beige','38','4','','','','','BORRADOR','','OK'],
        ];
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) fputcsv($handle, $row);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent('modelos-por-color.csv', $csv);
    }

    public function test_same_general_code_imports_different_color_models_as_one_product(): void
    {
        $this->post('/api/admin/products/import/preview', ['file' => $this->file()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('summary.products', 1)
            ->assertJsonPath('summary.variants', 2);

        $this->post('/api/admin/products/import/commit', ['file' => $this->file()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary.created', 1);

        $product = Product::where('product_code','2001')->firstOrFail();
        $this->assertSame(7,(int) $product->stock);
        $this->assertDatabaseCount('products',1);
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id, 'color' => 'Negro',
            'size' => '38', 'article_model' => '2001-N', 'stock' => 3,
        ]);
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id, 'color' => 'Beige',
            'size' => '38', 'article_model' => '2001-B', 'stock' => 4,
        ]);
    }
}
