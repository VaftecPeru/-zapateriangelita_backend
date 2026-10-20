<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductInventoryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UnallocatedInventoryMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Brand::create(['name' => 'Angelita', 'slug' => 'angelita', 'is_active' => true]);
        Category::create(['name' => 'Hombre', 'order' => 1, 'is_active' => true]);
    }

    private function updateFile(int $stock): UploadedFile
    {
        $headers = [
            'ACCIÓN', 'CÓDIGO_PRODUCTO', 'NOMBRE', 'MODELO', 'MARCA',
            'CATEGORÍA', 'SUBCATEGORÍA', 'PRECIO', 'PRECIO_OFERTA',
            'MATERIAL', 'COLOR', 'TALLA', 'STOCK', 'DESCRIPCIÓN',
            'IMAGEN_PRINCIPAL', 'IMAGEN_2', 'IMAGEN_3', 'ESTADO',
            'OBSERVACIONES', 'VALIDACIÓN',
        ];
        $values = [
            'ACTUALIZAR', 'QA-UNALLOCATED', 'Borrador sin tallas',
            'QA-MODEL', 'Angelita', 'Hombre', '', '0', '',
            '', '', '', (string) $stock, '', '', '', '', 'BORRADOR', '', 'OK',
        ];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers);
        fputcsv($stream, $values);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('update-unallocated.csv', $csv);
    }

    public function test_reimport_of_unallocated_stock_records_only_real_total_deltas(): void
    {
        $brand = Brand::firstOrFail();
        $category = Category::firstOrFail();
        $product = Product::create([
            'product_code' => 'QA-UNALLOCATED',
            'name' => 'Borrador sin tallas',
            'model' => 'QA-MODEL',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 0,
            'stock' => 11,
            'status' => 'normal',
            'is_active' => false,
        ]);

        $service = app(ProductInventoryImportService::class);
        $this->assertSame(0, $product->variantStocks()->count());

        $unchanged = $service->commit($this->updateFile(11));
        $this->assertSame(1, $unchanged['summary']['updated']);
        $this->assertSame(11, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);

        $increased = $service->commit($this->updateFile(13));
        $this->assertSame(1, $increased['summary']['updated']);
        $this->assertSame(13, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'color' => null,
            'size' => null,
            'type' => 'ajuste',
            'previous_stock' => 11,
            'new_stock' => 13,
            'quantity_change' => 2,
        ]);
        $this->assertFalse((bool) $product->fresh()->is_active);
    }
}
