<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Order;
use App\Services\CheckoutStockService;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

    public function test_preview_inherits_fields_and_normalizes_flexi_dama_after_catalog_approval(): void
    {
        $this->assertNotNull(Brand::whereRaw('LOWER(name) = ?', ['flexi'])->first());

        Category::create(['name' => 'Huarache', 'is_active' => true, 'order' => 10]);
        $file = $this->xlsx([
            $this->headers(),
            ['CREAR', 'ZA-FLEXI-01', 'Huarache Flexi Dama', 'HF-01', 'FLEXI DAMA', 'huarrache', '', '$1,299.00', '', 'Piel', 'Beige', '23', '2', '', '', '', '', 'ACTIVO', '', 'OK'],
            ['', 'ZA-FLEXI-01', '', '', '', '', '', '', '', '', 'Beige', '24', '3', '', '', '', '', '', '', 'OK'],
        ]);
        $response = $this->post('/api/admin/products/import/preview', ['file' => $file], ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('summary.error_rows', 0)
            ->assertJsonPath('rows.1.name', 'Huarache Flexi Dama')
            ->assertJsonPath('rows.1.price', 1299)
            ->assertJsonPath('groups.0.brand', 'flexi')
            ->assertJsonPath('groups.0.category', 'Huarache')
            ->assertJsonPath('groups.0.total_stock', 5);
        $this->assertStringContainsString('Marca normalizada', implode(' | ', $response->json('rows.0.warnings')));
    }

    public function test_unknown_catalog_is_blocked_until_explicit_registration_and_approval(): void
    {
        $file = $this->xlsx([
            $this->headers(),
            ['CREAR', 'ZA-FLEXI-02', 'Huarache Flexi Dama', 'HF-02', 'FLEXI DAMA', 'huarrache', '', '1499', '', 'Piel', 'Negro', '24', '4', '', '', '', '', 'ACTIVO', '', 'OK'],
        ]);

        $this->post('/api/admin/products/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('valid', false);
        $this->post('/api/admin/products/import/commit', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZA-FLEXI-02']);
        $this->assertDatabaseMissing('categories', ['slug' => 'huarache']);

        // El asistente crea el catálogo inactivo y solo después un administrador lo aprueba.
        $response = $this->postJson('/api/categories', [
            'name' => 'Huarache', 'is_active' => false,
        ])->assertCreated();
        $id = $response->json('id');
        $this->post('/api/admin/products/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('valid', false);
        $this->putJson('/api/categories/' . $id, ['is_active' => true])->assertOk();
        $this->post('/api/admin/products/import/commit', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.units', 4);
        $this->assertDatabaseHas('products', ['product_code' => 'ZA-FLEXI-02']);
    }

    public function test_existing_category_mapping_never_creates_a_new_category(): void
    {
        $file = $this->xlsx([
            $this->headers(),
            ['CREAR', 'ZA-CATALOG-MAP', 'Bota caballero', '418103', 'Angelita', 'Señores', 'Botines', '1000', '', 'Piel', 'Negro', '6', '2', '', '', '', '', 'ACTIVO', '', 'OK'],
        ]);
        $mappings = json_encode(['categories' => ['senores' => 'Hombre']], JSON_THROW_ON_ERROR);
        $this->post('/api/admin/products/import/preview', [
            'file' => $file, 'catalog_mappings' => $mappings,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('groups.0.category', 'Hombre');

        $this->post('/api/admin/products/import/commit', [
            'file' => $file, 'catalog_mappings' => $mappings,
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseMissing('categories', ['name' => 'Señores']);
        $this->assertDatabaseHas('products', ['product_code' => 'ZA-CATALOG-MAP']);
    }

    public function test_duplicate_category_registration_is_rejected(): void
    {
        $this->postJson('/api/categories', ['name' => 'hombre'])->assertStatus(422);
        $this->assertDatabaseCount('categories', Category::query()->count());
    }


    /**
     * QA aislado: Paso 0 -> CSV V2 -> variantes SQLite -> checkout ->
     * pago simulado -> liberación idempotente de existencias.
     * Nunca utiliza tarjetas ni endpoints reales de Openpay.
     */
    public function test_e2e_catalog_preflight_import_checkout_and_release_without_external_charge(): void
    {
        config([
            'services.payment_service.enabled' => false,
            'openpay.currency' => 'MXN',
            'openpay.merchant_id' => 'qa_dummy_merchant',
            'openpay.public_key' => 'qa_dummy_public',
            'openpay.private_key' => 'qa_dummy_private',
            'openpay.sandbox' => true,
            'openpay.environment' => 'sandbox',
            'openpay.base_url' => 'https://sandbox-api.openpay.mx',
            'openpay.redirect_url' => 'https://example.test/checkout',
        ]);

        $brand = $this->postJson('/api/brands', [
            'name' => 'PIRMA QA INTEGRACION', 'is_active' => false,
        ])->assertCreated();
        $subcategory = $this->postJson('/api/subcategories', [
            'category_id' => Category::where('name', 'Hombre')->firstOrFail()->id,
            'name' => 'Botas', 'is_active' => false,
        ])->assertCreated();

        $headers = array_merge($this->headers(), ['STOCK_POR_TALLA']);
        $values = [
            'CREAR', 'ZA-QA-V2-001', 'Bota Pirma Caballero Modelo 418103',
            '418103', 'PIRMA QA INTEGRACION', 'Hombre', 'Botas',
            '1000', '', '', 'Negro', '5,6,7', '6', '',
            '/uploads/products/qa-openpay-test.webp', '', '', 'ACTIVO', '', 'OK', '5:2;6:1;7:3',
        ];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers);
        fputcsv($stream, $values);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $file = UploadedFile::fake()->createWithContent('angelita-qa-v2.csv', $csv);

        $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('valid', false);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZA-QA-V2-001']);

        $this->putJson('/api/brands/' . $brand->json('id'), [
            'is_active' => true,
        ])->assertOk();
        $this->putJson('/api/subcategories/' . $subcategory->json('id'), [
            'is_active' => true,
        ])->assertOk();

        $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('summary.variants', 3)
            ->assertJsonPath('groups.0.total_stock', 6);
        $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.units', 6);

        $product = Product::where('product_code', 'ZA-QA-V2-001')->firstOrFail();
        $this->assertSame(6, (int) $product->stock);
        foreach (['5' => 2, '6' => 1, '7' => 3] as $size => $units) {
            $this->assertDatabaseHas('product_variant_stocks', [
                'product_id' => $product->id,
                'color' => 'Negro', 'size' => (string) $size, 'stock' => $units,
            ]);
        }

        $cart = [
            'customer_name' => 'Administrador QA',
            'customer_email' => 'angelita-qa-cliente@example.com',
            'shipping_address' => 'Calle QA 123',
            'shipping_city' => 'Pachuca',
            'shipping_country' => 'México',
            'shipping_state' => 'Hidalgo',
            'shipping_municipality' => 'Pachuca',
            'shipping_postal_code' => '42000',
            'shipping_phone' => '+525512345678',
            'payment_method' => 'openpay',
        ];

        // La selección de variante que no existe debe bloquearse antes del pago.
        $this->postJson('/api/checkout/orders', array_merge($cart, [
            'items' => [[
                'product_id' => $product->id, 'size' => '6',
                'color' => 'Rojo', 'quantity' => 1,
            ]],
        ]))->assertStatus(422);
        $this->assertSame(6, (int) $product->fresh()->stock);

        // El carrito con dos líneas de la talla 6 (1 unidad disponible)
        // se rechaza antes de generar pedidos o descontar inventario.
        $this->postJson('/api/checkout/orders', array_merge($cart, [
            'items' => [
                ['product_id' => $product->id, 'size' => '6', 'color' => 'Negro', 'quantity' => 1],
                ['product_id' => $product->id, 'size' => '6', 'color' => 'Negro', 'quantity' => 1],
            ],
        ]))->assertStatus(422);
        $this->assertSame(6, (int) $product->fresh()->stock);

        $orderResponse = $this->postJson('/api/checkout/orders', array_merge($cart, [
            'items' => [[
                'product_id' => $product->id, 'size' => '6',
                'color' => 'Negro', 'quantity' => 1,
            ]],
        ]))->assertCreated();

        $orderId = (int) $orderResponse->json('order_id');
        $token = $orderResponse->json('checkout_token');

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $payload = $request->data();
            return Http::response([
                'id' => 'qa_mock_transaction_001',
                'order_id' => $payload['order_id'],
                'amount' => $payload['amount'],
                'currency' => 'MXN',
                'status' => 'in_progress',
                'payment_method' => [
                    'type' => 'redirect',
                    'url' => 'https://sandbox-api.openpay.mx/qa-mock-3ds',
                ],
            ], 200);
        });

        $this->postJson('/api/checkout/payments/openpay/charge', [
            'order_id' => $orderId,
            'checkout_token' => $token,
            'token_id' => 'qa_synthetic_token',
            'device_session_id' => 'qa_synthetic_session',
        ])->assertStatus(202)
            ->assertJsonPath('payment_status', 'processing');

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id, 'color' => 'Negro', 'size' => '6', 'stock' => 0,
        ]);
        $this->assertDatabaseHas('product_sizes', [
            'product_id' => $product->id, 'size' => '6', 'stock' => 0,
        ]);
        $order = Order::findOrFail($orderId);
        app(CheckoutStockService::class)->release($order, 'QA: pago simulado no finalizado');
        app(CheckoutStockService::class)->release($order, 'QA: reintento idempotente');
        $this->assertSame(6, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => $product->id, 'color' => 'Negro', 'size' => '6', 'stock' => 1,
        ]);
        $this->assertSame('failed', $order->fresh()->payment_status);
        Http::assertSentCount(1);
    }


    public function test_legacy_pirma_inventory_codes_are_persisted_exactly_without_generated_prefixes(): void
    {
        Category::create([
            'name' => 'Mujer',
            'order' => 2,
            'is_active' => true,
        ]);
        Brand::create([
            'name' => 'Pirma',
            'slug' => 'pirma',
            'is_active' => true,
        ]);

        // Precios de prueba únicamente: se usa base temporal de CI, nunca producción.
        // Los códigos S0380 / S0041 / S0042 proceden de la libreta del cliente.
        $headers = array_merge($this->headers(), ['STOCK_POR_TALLA']);
        $rows = [
            $headers,
            ['CREAR', 'S0380', 'Calzado Pirma Modelo 0248', '0248', 'Pirma', 'Mujer', '', '1000', '', '', 'Negro/Blanco', '2,3,4,5,6', '15', '', '', '', '', 'ACTIVO', '', 'OK', '2:1;3:5;4:4;5:4;6:1'],
            ['CREAR', 'S0041', 'Calzado Pirma Modelo 0248', '0248', 'Pirma', 'Mujer', '', '1000', '', '', 'Negro/Fucsia', '3,4,5', '11', '', '', '', '', 'ACTIVO', '', 'OK', '3:3;4:4;5:4'],
            ['CREAR', 'S0042', 'Calzado Pirma Modelo 0248', '0248', 'Pirma', 'Mujer', '', '1000', '', '', 'Negro/Negro', '3,4,5,6', '18', '', '', '', '', 'ACTIVO', '', 'OK', '3:6;4:5;5:5;6:2'],
        ];
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $file = UploadedFile::fake()->createWithContent(
            'pirma-original-codes-qa.csv',
            stream_get_contents($stream),
        );
        fclose($stream);

        $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('summary.products', 3)
            ->assertJsonPath('summary.variants', 12);

        $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('summary.created', 3)
            ->assertJsonPath('summary.units', 44);

        $this->assertSame(
            ['S0041', 'S0042', 'S0380'],
            Product::query()->orderBy('product_code')->pluck('product_code')->all(),
        );
        $this->assertSame(44, (int) Product::query()->sum('stock'));
        $this->assertDatabaseHas('product_variant_stocks', [
            'product_id' => Product::where('product_code', 'S0380')->firstOrFail()->id,
            'color' => 'Negro/Blanco',
            'size' => '2',
            'stock' => 1,
        ]);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZAP-0380']);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZAP-0041']);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZAP-0042']);
    }


    public function test_zero_price_catalog_draft_imports_stock_but_remains_invisible_and_unpurchasable(): void
    {
        Brand::create(['name' => 'Pirma', 'slug' => 'pirma', 'is_active' => true]);
        $category = Category::create(['name' => 'Mujer', 'is_active' => true, 'order' => 3]);
        $headers = implode(',', array_merge($this->headers(), ['STOCK_POR_TALLA']));
        $csv = $headers . "\n"
            . 'CREAR,S0380,Calzado Pirma 0248,0248,Pirma,Mujer,,0,,,Negro/Blanco,"2,3,4",6,,,,,ACTIVO,,OK,"2:1;3:2;4:3"' . "\n"
            . 'CREAR,S0041,Calzado Pirma 0248,0248,Pirma,Mujer,,,,,Negro/Fucsia,"2,3",4,,,,,ACTIVO,,OK,"2:1;3:3"' . "\n";
        $file = UploadedFile::fake()->createWithContent('pirma-sin-precios.csv', $csv);

        $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('summary.draft_products', 2)
            ->assertJsonPath('summary.ready_products', 0);

        $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('summary.drafts', 2)
            ->assertJsonPath('summary.published', 0)
            ->assertJsonPath('summary.units', 10);

        foreach (['S0380', 'S0041'] as $code) {
            $product = Product::where('product_code', $code)->firstOrFail();
            $this->assertSame('0.00', (string) $product->price);
            $this->assertTrue((bool) $product->auto_publish);
            $this->assertFalse((bool) $product->is_active);
        }
        $this->assertSame(10, (int) Product::sum('stock'));
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products/' . Product::where('product_code', 'S0380')->firstOrFail()->id)
            ->assertStatus(404);

        // Precio único del modelo: los colores con precio cero reciben el mismo
        // importe, sin publicarse mientras sigan sin fotografía.
        $this->putJson('/api/admin/products/model-price', [
            'brand_id' => Brand::where('name', 'Pirma')->firstOrFail()->id,
            'category_id' => $category->id,
            'model' => '0248',
            'price' => 799,
        ])->assertOk()->assertJsonPath('summary.updated', 2)
            ->assertJsonPath('summary.drafts', 2);
        foreach (['S0380', 'S0041'] as $code) {
            $this->assertDatabaseHas('products', [
                'product_code' => $code, 'price' => 799, 'is_active' => false,
            ]);
        }

        // Completar la fotografía comercial de un SKU activa solo ese color.
        $first = Product::where('product_code', 'S0380')->firstOrFail();
        $first->images = ['/uploads/products/pirma-0248-negro-blanco.webp'];
        $first->img = '/uploads/products/pirma-0248-negro-blanco.webp';
        $first->save();
        $this->postJson('/api/products/' . $first->id, [
            'price' => 799,
        ])->assertOk();
        $this->assertTrue((bool) $first->fresh()->is_active);
        $this->assertFalse((bool) Product::where('product_code', 'S0041')->firstOrFail()->is_active);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_price_is_inherited_from_model_only_when_unambiguous(): void
    {
        Brand::create(['name' => 'Pirma', 'slug' => 'pirma', 'is_active' => true]);
        Category::create(['name' => 'Mujer', 'order' => 3, 'is_active' => true]);
        $header = implode(',', array_merge($this->headers(), ['STOCK_POR_TALLA']));
        $data = $header . "\n"
            . 'CREAR,S0380,Calzado Pirma 0248,0248,Pirma,Mujer,,799,,,Negro/Blanco,3,2,,/uploads/products/s0380.webp,,,ACTIVO,,OK,' . "\n"
            . 'CREAR,S0041,Calzado Pirma 0248,0248,Pirma,Mujer,,0,,,Negro/Fucsia,3,4,,,,,ACTIVO,,OK,' . "\n"
            . 'CREAR,S0042,Calzado Pirma 0248,0248,Pirma,Mujer,,,,,,Negro/Negro,3,6,,,,,ACTIVO,,OK,' . "\n";
        $file = UploadedFile::fake()->createWithContent('precio-compartido.csv', $data);
        $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('summary.ready_products', 1)
            ->assertJsonPath('summary.draft_products', 2);
        $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('summary.published', 1)
            ->assertJsonPath('summary.drafts', 2);
        foreach (['S0380', 'S0041', 'S0042'] as $code) {
            $this->assertDatabaseHas('products', ['product_code' => $code, 'price' => 799]);
        }
    }

    public function test_partial_import_is_explicit_and_never_commits_invalid_groups(): void
    {
        $header = implode(',', array_merge($this->headers(), ['STOCK_POR_TALLA']));
        $csv = $header . "\n"
            . 'CREAR,S0250,Calzado para inventario,MODEL-1,Angelita,Hombre,Botines,0,,,Negro,4,3,,,,,ACTIVO,,OK,' . "\n"
            . 'CREAR,S0251,Calzado defectuoso,MODEL-2,Angelita,Hombre,Botines,0,,,Negro,"4,5",3,,,,,ACTIVO,,OK,' . "\n";
        $file = UploadedFile::fake()->createWithContent('parcial.csv', $csv);
        $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('valid', false)
            ->assertJsonPath('summary.valid_products', 1);

        $this->post('/api/admin/products/import/commit', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
        $this->assertDatabaseCount('products', 0);

        $this->post('/api/admin/products/import/commit', [
            'file' => $file, 'import_valid_only' => '1',
        ], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.skipped', 1);
        $this->assertDatabaseHas('products', [
            'product_code' => 'S0250', 'price' => 0, 'is_active' => false,
        ]);
        $this->assertDatabaseMissing('products', ['product_code' => 'S0251']);
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
        $this->assertFalse((bool) $product->is_active); // Sin fotografía comercial: borrador seguro.
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

    public function test_grouped_sizes_require_explicit_quantities_and_do_not_write(): void
    {
        $headers = implode(',', array_merge($this->headers(), ['STOCK_POR_TALLA']));
        $csv = $headers . "\n"
            . 'CREAR,ZA-GROUPED-01,Botin agrupado,MODELO,Angelita,Hombre,Botines,1500,,Piel,Negro,"18,19,20,21",4,,,,,ACTIVO,,OK,' . "\n";
        $file = UploadedFile::fake()->createWithContent('sin-desglose.csv', $csv);

        $this->post('/api/admin/products/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('valid', false);

        $this->post('/api/admin/products/import/commit', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZA-GROUPED-01']);
    }

    public function test_preview_rejects_atomic_size_exceeding_historical_mariadb_limit(): void
    {
        $file = $this->xlsx([
            $this->headers(),
            [
                'CREAR', 'ZA-SIZE-TOO-LONG', 'Botín de prueba', 'QA-SIZE',
                'Angelita', 'Hombre', 'Botines', '1000', '', '',
                'Negro', 'TALLA-EXCESO', '2', '', '', '', '',
                'INACTIVO', '', 'OK',
            ],
        ]);

        $response = $this->post('/api/admin/products/import/preview', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $response->assertOk()->assertJsonPath('valid', false);
        $this->assertStringContainsString(
            'máximo 10 caracteres',
            implode(' | ', $response->json('rows.0.errors')),
        );
        $this->assertDatabaseMissing('products', ['product_code' => 'ZA-SIZE-TOO-LONG']);
    }

    public function test_one_row_import_expands_sizes_into_independent_stock_and_movements(): void
    {
        $headers = implode(',', array_merge($this->headers(), ['STOCK_POR_TALLA']));
        $csv = $headers . "\n"
            . 'CREAR,ZA-GROUPED-02,Botin por talla,MODELO,Angelita,Hombre,Botines,1500,,Piel,Negro,"18,19,20,21",10,,,,,ACTIVO,,OK,"18:1;19:2;20:3;21:4"' . "\n";
        $file = UploadedFile::fake()->createWithContent('con-desglose.csv', $csv);

        $this->post('/api/admin/products/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('summary.variants', 4)
            ->assertJsonPath('groups.0.total_stock', 10);

        $this->post('/api/admin/products/import/commit', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.variants', 4)
            ->assertJsonPath('summary.units', 10);

        $product = Product::where('product_code', 'ZA-GROUPED-02')->firstOrFail();
        foreach (['18' => 1, '19' => 2, '20' => 3, '21' => 4] as $size => $stock) {
            $this->assertDatabaseHas('product_variant_stocks', [
                'product_id' => $product->id, 'color' => 'Negro', 'size' => (string) $size, 'stock' => $stock,
            ]);
            $this->assertDatabaseHas('inventory_movements', [
                'product_id' => $product->id, 'size' => (string) $size, 'new_stock' => $stock,
            ]);
        }
        $this->assertSame(10, (int) $product->stock);
    }

    public function test_mismatched_total_blocks_import_without_partial_writes(): void
    {
        $headers = implode(',', array_merge($this->headers(), ['STOCK_POR_TALLA']));
        $csv = $headers . "\n"
            . 'CREAR,ZA-GROUPED-03,Botin invalido,MODELO,Angelita,Hombre,Botines,1500,,Piel,Negro,"18,19",9,,,,,ACTIVO,,OK,"18:1;19:2"' . "\n";
        $file = UploadedFile::fake()->createWithContent('total-incorrecto.csv', $csv);
        $this->post('/api/admin/products/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('valid', false);
        $this->assertDatabaseMissing('products', ['product_code' => 'ZA-GROUPED-03']);
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
            'CATEGORÍA',
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
