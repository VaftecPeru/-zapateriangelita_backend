<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.name' => 'Zapatería Angelita',
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->nullable();
            $table->string('name');
            $table->string('model')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('discounted_price', 10, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->string('material')->nullable();
            $table->string('status')->default('normal');
            $table->timestamps();
        });

        Schema::create('product_sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('size', 10);
            $table->integer('stock')->default(0);
            $table->timestamps();
        });

        DB::table('categories')->insert([
            'id' => 1,
            'name' => 'Mujer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('brands')->insert([
            'id' => 1,
            'name' => 'Angelita',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_can_export_inventory_excel_with_size_stock(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        ProductSize::create([
            'product_id' => $product->id,
            'size' => '36',
            'stock' => 6,
        ]);
        ProductSize::create([
            'product_id' => $product->id,
            'size' => '37',
            'stock' => 4,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/inventory/export/excel');

        $response->assertOk();
        $this->assertStringContainsString('application/vnd.ms-excel', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inventario-zapateria-angelita-', (string) $response->headers->get('Content-Disposition'));

        $content = $response->getContent();
        $this->assertStringContainsString('REPORTE PROFESIONAL DE INVENTARIO POR TALLA', $content);
        $this->assertStringContainsString('ZAP-001', $content);
        $this->assertStringContainsString('Stock talla', $content);
        $this->assertStringContainsString('>36<', $content);
        $this->assertStringContainsString('>6<', $content);
        $this->assertStringContainsString('>37<', $content);
        $this->assertStringContainsString('>4<', $content);
    }

    public function test_admin_can_export_valid_pdf_inventory(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        ProductSize::create([
            'product_id' => $product->id,
            'size' => '38',
            'stock' => 10,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/inventory/export/pdf');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inventario-zapateria-angelita-', (string) $response->headers->get('Content-Disposition'));

        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $content);
        $this->assertStringContainsString('REPORTE PROFESIONAL DE INVENTARIO POR TALLA', $content);
        $this->assertStringContainsString('ZAP-001', $content);
    }

    public function test_inventory_exports_are_not_public(): void
    {
        $this->getJson('/api/inventory/export/excel')->assertUnauthorized();
        $this->getJson('/api/inventory/export/pdf')->assertUnauthorized();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Administrador',
            'email' => 'admin@angelita.test',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'product_code' => 'ZAP-001',
            'name' => 'Zapato Premium',
            'brand_id' => 1,
            'category_id' => 1,
            'price' => 1599.90,
            'stock' => 10,
            'size' => '36|37',
            'color' => 'Negro',
            'material' => 'Cuero',
            'status' => 'normal',
        ]);
    }
}
