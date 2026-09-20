<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessTokenService;
use App\Services\MediaStorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionDataMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'media.root' => storage_path('framework/testing/angelita_media_integrity'),
            'media.url_prefix' => '/api/media',
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
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
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->nullable();
            $table->string('name')->nullable();
            $table->string('price');
            $table->string('discount')->nullable();
            $table->text('img')->nullable();
            $table->text('images')->nullable();
            $table->text('color_images')->nullable();
            $table->integer('stock')->nullable();
            $table->string('status')->default('normal');
            $table->timestamps();
        });

        Schema::create('product_colors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('color');
            $table->string('hex')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('municipality')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->text('reference')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/angelita_media_integrity'));
        @unlink(storage_path('app/public/uploads/products/legacy-test.webp'));
        @unlink(storage_path('app/public/uploads/products/legacy-endpoint.webp'));
        parent::tearDown();
    }

    public function test_production_maintenance_repairs_current_dump_patterns(): void
    {
        $user = User::create([
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);

        DB::table('products')->insert([
            'id' => 19,
            'product_code' => ' Mod: 3126735 ',
            'name' => 'Tenis urbano',
            'price' => '190',
            'discount' => '5',
            'img' => '/api/media/uploads/products/producto.webp',
            'images' => json_encode(['/api/media/uploads/products/producto.webp']),
            'color_images' => json_encode([]),
            'stock' => 10,
            'status' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_colors')->insert([
            ['product_id' => 19, 'color' => 'Celeste', 'hex' => '#888888', 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => 19, 'color' => 'Café', 'hex' => '#888888', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('brands')->insert([
            ['id' => 11, 'name' => 'AngelitaPRO', 'slug' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'name' => 'varios', 'slug' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([48, 49, 50] as $id) {
            DB::table('addresses')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'full_name' => 'vaftec peru sac',
                'phone' => '+51900112844',
                'country' => 'México',
                'state' => 'Hidalgo',
                'municipality' => 'Mineral del Chico',
                'address' => 'Chaclacayo, Lima Perú',
                'city' => 'Mineral del Chico',
                'postal_code' => '000051',
                'reference' => null,
                'is_default' => $id === 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        for ($i = 1; $i <= 25; $i++) {
            DB::table('personal_access_tokens')->insert([
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
                'name' => 'auth_token',
                'token' => hash('sha256', 'valid-' . $i),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => 9999,
            'name' => 'orphan',
            'token' => hash('sha256', 'orphan'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_20_040000_harden_production_data_integrity.php');
        $migration->up();

        $this->assertDatabaseHas('products', [
            'id' => 19,
            'product_code' => 'Mod: 3126735',
        ]);

        $this->assertDatabaseHas('product_colors', [
            'product_id' => 19,
            'color' => 'Celeste',
            'hex' => '#7dd3fc',
        ]);

        $this->assertDatabaseHas('product_colors', [
            'product_id' => 19,
            'color' => 'Café',
            'hex' => '#6f4e37',
        ]);

        $this->assertDatabaseHas('brands', ['id' => 11, 'slug' => 'angelitapro']);
        $this->assertDatabaseHas('brands', ['id' => 12, 'slug' => 'varios']);

        $this->assertSame(1, DB::table('addresses')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('addresses', ['id' => 50, 'is_default' => 1]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => 9999,
        ]);

        $this->assertSame(
            20,
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->count()
        );
    }

    public function test_token_service_keeps_only_latest_ten_sessions(): void
    {
        $user = User::create([
            'name' => 'Administrador',
            'email' => 'admin@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin',
        ]);

        $service = app(AccessTokenService::class);

        for ($i = 0; $i < 14; $i++) {
            $service->issue($user, 'auth_token');
        }

        $this->assertSame(10, $user->tokens()->count());
    }

    public function test_admin_can_consolidate_all_referenced_media(): void
    {
        $admin = User::create([
            'name' => 'Administrador',
            'email' => 'media-admin@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin',
        ]);

        DB::table('products')->insert([
            'id' => 88,
            'product_code' => 'MEDIA-88',
            'name' => 'Producto multimedia',
            'price' => '100',
            'discount' => null,
            'img' => '/api/media/uploads/products/legacy-endpoint.webp',
            'images' => json_encode(['/api/media/uploads/products/legacy-endpoint.webp']),
            'color_images' => json_encode([]),
            'stock' => 1,
            'status' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = storage_path('app/public/uploads/products/legacy-endpoint.webp');
        File::ensureDirectoryExists(dirname($legacy));
        file_put_contents($legacy, 'legacy-endpoint-image');

        Sanctum::actingAs($admin);

        $this->postJson('/api/maintenance/media/migrate')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.missing', 0);

        $persistent = app(MediaStorageService::class)
            ->absolutePath('uploads/products/legacy-endpoint.webp');

        $this->assertFileExists($persistent);
        $this->assertSame('legacy-endpoint-image', file_get_contents($persistent));
    }

    public function test_legacy_media_is_copied_to_persistent_root_on_first_read(): void
    {
        $legacy = storage_path('app/public/uploads/products/legacy-test.webp');
        File::ensureDirectoryExists(dirname($legacy));
        file_put_contents($legacy, 'legacy-image');

        $service = app(MediaStorageService::class);
        $resolved = $service->resolveForRead('/api/media/uploads/products/legacy-test.webp');

        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);
        $this->assertSame('legacy-image', file_get_contents($resolved));

        $persistent = $service->absolutePath('uploads/products/legacy-test.webp');
        $this->assertFileExists($persistent);
        $this->assertSame('legacy-image', file_get_contents($persistent));
    }
}
