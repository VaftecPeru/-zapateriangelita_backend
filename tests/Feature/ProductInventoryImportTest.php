<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class ProductInventoryImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $category = Category::create([
            'name' => 'Hombre',
            'order' => 1,
            'is_active' => true,
        ]);

        Subcategory::create([
            'category_id' => $category->id,
            'name' => 'Botines',
            'order' => 1,
            'is_active' => true,
        ]);

        Brand::create([
            'name' => 'Angelita',
            'slug' => 'angelita',
            'is_active' => true,
        ]);
    }

    public function test_preview_reads_v1_xlsx_with_title_rows_and_groups_color_size_variants(): void
    {
        $file = $this->xlsx([
            ['ZAPATERÍA ANGELITA — PLANTILLA DE IMPORTACIÓN DE PRODUCTOS E INVENTARIO'],
            ['Propuesta V1 · Una fila = una variante de producto (color + talla).'],
            [],
            $this->headers(),
            ['CREAR', 'ZA-0001', 'Botín Ejecutivo Café', 'D06300189550', 'Angelita', 'Caballeros', 'Botines', '1299', '1199', 'Piel sintética', 'Café', '25', '3', 'Botín de vestir', '', '', '', 'ACTIVO', '', 'OK'],
            ['CREAR', 'ZA-0001', 'Botín Ejecutivo Café', 'D06300189550', 'Angelita', 'Caballeros', 'Botines', '1299', '1199', 'Piel sintética', 'Café', '26', '5', 'Botín de vestir', '', '', '', 'ACTIVO', '', 'OK'],
            ['CREAR', 'ZA-0001', 'Botín Ejecutivo Café', 'D06300189550', 'Angelita', 'Caballeros', 'Botines', '1299', '1199', 'Piel sintética', 'Negro', '25', '2', 'Botín de vestir', '', '', '', 'ACTIVO', '', 'OK'],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'FALTAN DATOS'],
        ]);

        $response = $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('format', 'xlsx')
            ->assertJsonPath('summary.products', 1)
            ->assertJsonPath('summary.variants', 3)
            ->assertJsonPath('summary.rows', 3)
            ->assertJsonPath('groups.0.category', 'Hombre')
            ->assertJsonPath('groups.0.total_stock', 10);
    }

    public function test_preview_detects_semicolon_csv_after_title_rows_and_quoted_newline(): void
    {
        $headers = implode(';', $this->headers());
        $row = [
            'CREAR', 'ZA-0009', 'Botín CSV', 'CSV-9', 'Angelita', 'Caballeros',
            'Botines', '1099', '999', 'Piel', 'Café', '25', '4',
            '"Descripción con' . "\n" . 'salto de línea"', '', '', '', 'ACTIVO', '', 'OK',
        ];

        $csv = "\xEF\xBB\xBFZAPATERÍA ANGELITA — IMPORTACIÓN CSV\n"
            . "Propuesta V1\n\n"
            . $headers . "\n"
            . implode(';', $row) . "\n";

        $file = UploadedFile::fake()->createWithContent('inventario.csv', $csv);

        $response = $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('format', 'csv')
            ->assertJsonPath('summary.products', 1)
            ->assertJsonPath('summary.variants', 1)
            ->assertJsonPath('groups.0.code', 'ZA-0009')
            ->assertJsonPath('groups.0.total_stock', 4);
    }

    public function test_preview_detects_semicolon_csv_when_quoted_data_contains_many_commas(): void
    {
        $headers = implode(';', $this->headers());
        $description = '"' . implode(',', array_fill(0, 30, 'detalle')) . '"';
        $row = [
            'CREAR', 'ZA-0010', 'Botín CSV Comas', 'CSV-10', 'Angelita', 'Caballeros',
            'Botines', '1099', '999', 'Piel', 'Café', '25', '4',
            $description, '', '', '', 'ACTIVO', '', 'OK',
        ];

        $csv = "ZAPATERÍA ANGELITA — IMPORTACIÓN CSV\n"
            . "Propuesta V1\n\n"
            . $headers . "\n"
            . implode(';', $row) . "\n";

        $file = UploadedFile::fake()->createWithContent('inventario-con-comas.csv', $csv);

        $response = $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('format', 'csv')
            ->assertJsonPath('summary.products', 1)
            ->assertJsonPath('summary.variants', 1)
            ->assertJsonPath('groups.0.code', 'ZA-0010')
            ->assertJsonPath('groups.0.total_stock', 4);
    }

    public function test_preview_blocks_example_rows_and_duplicate_color_size_variant(): void
    {
        $file = $this->xlsx([
            $this->headers(),
            ['CREAR', 'ZA-0002', 'Botín Prueba', '', 'Angelita', 'Hombre', 'Botines', '999', '', 'Piel', 'Negro', '25', '2', '', '', '', '', 'ACTIVO', 'EJEMPLO — eliminar antes de importar', 'OK'],
            ['CREAR', 'ZA-0002', 'Botín Prueba', '', 'Angelita', 'Hombre', 'Botines', '999', '', 'Piel', 'Negro', '25', '3', '', '', '', '', 'ACTIVO', '', 'OK'],
        ]);

        $response = $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('summary.error_rows', 2);

        $payload = $response->json();
        $errors = implode(' | ', $payload['groups'][0]['errors']);

        $this->assertStringContainsString('EJEMPLO', $errors);
        $this->assertStringContainsString('Variante duplicada', $errors);
    }

    public function test_commit_creates_product_and_variant_inventory_atomically(): void
    {
        $file = $this->xlsx([
            $this->headers(),
            ['CREAR', 'ZA-0100', 'Botín Nuevo', 'BN-100', 'Angelita', 'Hombre', 'Botines', '1500', '1200', 'Piel', 'Café', '25', '3', 'Nuevo botín', '', '', '', 'ACTIVO', '', 'OK'],
            ['CREAR', 'ZA-0100', 'Botín Nuevo', 'BN-100', 'Angelita', 'Hombre', 'Botines', '1500', '1200', 'Piel', 'Café', '26', '5', 'Nuevo botín', '', '', '', 'ACTIVO', '', 'OK'],
            ['CREAR', 'ZA-0100', 'Botín Nuevo', 'BN-100', 'Angelita', 'Hombre', 'Botines', '1500', '1200', 'Piel', 'Negro', '25', '2', 'Nuevo botín', '', '', '', 'ACTIVO', '', 'OK'],
        ]);

        $response = $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.updated', 0)
            ->assertJsonPath('summary.variants', 3)
            ->assertJsonPath('summary.units', 10);

        $product = Product::where('product_code', 'ZA-0100')->firstOrFail();

        $this->assertSame(10, (int) $product->stock);
        $this->assertSame('1200.00', (string) $product->discounted_price);
        $this->assertSame('oferta', $product->status);
        $this->assertTrue((bool) $product->is_active);
        $this->assertDatabaseCount('product_variant_stocks', 3);
        $this->assertDatabaseHas('product_sizes', [
            'product_id' => $product->id,
            'size' => '25',
            'stock' => 5,
        ]);
        $this->assertDatabaseHas('product_sizes', [
            'product_id' => $product->id,
            'size' => '26',
            'stock' => 5,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'color' => 'Café',
            'size' => '25',
            'type' => 'ajuste',
            'previous_stock' => 0,
            'new_stock' => 3,
            'quantity_change' => 3,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'color' => 'Negro',
            'size' => '25',
            'type' => 'ajuste',
            'previous_stock' => 0,
            'new_stock' => 2,
            'quantity_change' => 2,
        ]);
    }

    public function test_update_requires_existing_code_and_replaces_variant_snapshot(): void
    {
        $category = Category::where('name', 'Hombre')->firstOrFail();
        $brand = Brand::where('name', 'Angelita')->firstOrFail();

        $product = Product::create([
            'product_code' => 'ZA-0200',
            'name' => 'Anterior',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 800,
            'stock' => 7,
            'size' => '25',
            'color' => 'Negro',
            'material' => 'Piel',
            'status' => 'normal',
            'is_active' => true,
        ]);

        $product->variantStocks()->create([
            'color' => 'Negro',
            'size' => '25',
            'stock' => 7,
        ]);

        $file = $this->xlsx([
            $this->headers(),
            ['ACTUALIZAR', 'ZA-0200', 'Botín Actualizado', 'ACT-2', 'Angelita', 'Hombre', 'Botines', '950', '', 'Piel', 'Café', '26', '4', 'Actualizado', '', '', '', 'INACTIVO', '', 'OK'],
        ]);

        $response = $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.updated', 1);

        $product->refresh();

        $this->assertSame('Botín Actualizado', $product->name);
        $this->assertSame(4, (int) $product->stock);
        $this->assertFalse((bool) $product->is_active);
        $this->assertDatabaseMissing('product_variant_stocks', [
            'product_id' => $product->id,
            'color' => 'Negro',
            'size' => '25',
        ]);
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id,
            'color' => 'Café',
            'size' => '26',
            'stock' => 4,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'color' => 'Negro',
            'size' => '25',
            'type' => 'ajuste',
            'previous_stock' => 7,
            'new_stock' => 0,
            'quantity_change' => -7,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'color' => 'Café',
            'size' => '26',
            'type' => 'ajuste',
            'previous_stock' => 0,
            'new_stock' => 4,
            'quantity_change' => 4,
        ]);

        $this->getJson('/api/products?per_page=200')
            ->assertOk()
            ->assertJsonMissing(['product_code' => 'ZA-0200']);

        $this->getJson('/api/admin/products?per_page=200')
            ->assertOk()
            ->assertJsonFragment(['product_code' => 'ZA-0200']);
    }

    public function test_inventory_export_respects_active_state_filter(): void
    {
        $category = Category::where('name', 'Hombre')->firstOrFail();
        $brand = Brand::where('name', 'Angelita')->firstOrFail();

        Product::create([
            'product_code' => 'ZA-ACTIVO',
            'name' => 'Producto Visible',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 1000,
            'stock' => 3,
            'size' => '25',
            'color' => 'Negro',
            'material' => 'Piel',
            'status' => 'normal',
            'is_active' => true,
        ]);

        Product::create([
            'product_code' => 'ZA-INACTIVO',
            'name' => 'Producto Oculto',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 1100,
            'stock' => 4,
            'size' => '26',
            'color' => 'Café',
            'material' => 'Piel',
            'status' => 'normal',
            'is_active' => false,
        ]);

        $response = $this->get('/api/inventory/export/excel?active_state=inactive');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('Producto Oculto', $content);
        $this->assertStringNotContainsString('Producto Visible', $content);
    }

    public function test_commit_rejects_whole_file_when_any_group_is_invalid(): void
    {
        $file = $this->xlsx([
            $this->headers(),
            ['CREAR', 'ZA-0300', 'Producto válido', '', 'Angelita', 'Hombre', 'Botines', '1000', '', 'Piel', 'Negro', '25', '2', '', '', '', '', 'ACTIVO', '', 'OK'],
            ['CREAR', 'ZA-0301', 'Producto inválido', '', 'Marca inexistente', 'Hombre', 'Botines', '1000', '', 'Piel', 'Negro', '26', '2', '', '', '', '', 'ACTIVO', '', 'OK'],
        ]);

        $response = $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZA-0300']);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZA-0301']);
    }

    private function headers(): array
    {
        return [
            'ACCIÓN*',
            'CÓDIGO_PRODUCTO*',
            'NOMBRE*',
            'MODELO',
            'MARCA*',
            'CATEGORÍA*',
            'SUBCATEGORÍA',
            'PRECIO*',
            'PRECIO_OFERTA',
            'MATERIAL',
            'COLOR*',
            'TALLA*',
            'STOCK*',
            'DESCRIPCIÓN',
            'IMAGEN_PRINCIPAL',
            'IMAGEN_2',
            'IMAGEN_3',
            'ESTADO*',
            'OBSERVACIONES',
            'VALIDACIÓN',
        ];
    }

    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'angelita_xlsx_');
        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal.');
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="IMPORTAR_PRODUCTOS" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');

        $sheet = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $values) {
            $excelRow = $rowIndex + 1;
            $sheet .= '<row r="' . $excelRow . '">';

            foreach ($values as $columnIndex => $value) {
                if ((string) $value === '') {
                    continue;
                }

                $reference = $this->columnLetters($columnIndex + 1) . $excelRow;
                $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $sheet .= '<c r="' . $reference . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }

            $sheet .= '</row>';
        }

        $sheet .= '</sheetData></worksheet>';

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return new UploadedFile(
            $path,
            'Plantilla_Importacion_Productos_Inventario_Zapateria_Angelita_V1.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    private function columnLetters(int $column): string
    {
        $letters = '';

        while ($column > 0) {
            $column--;
            $letters = chr(65 + ($column % 26)) . $letters;
            $column = intdiv($column, 26);
        }

        return $letters;
    }
}
