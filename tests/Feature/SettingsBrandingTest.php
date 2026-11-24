<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\MediaStorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'media.root' => storage_path('framework/testing/angelita_media'),
            'media.url_prefix' => '/api/media',
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

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/angelita_media'));
        parent::tearDown();
    }

    private function user(string $email, string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'role' => $role,
        ]);
    }

    public function test_superadmin_can_manage_homepage_banners(): void
    {
        $superadmin = $this->user('soporte@vaftec.com.pe', 'superadmin');
        Sanctum::actingAs($superadmin);

        $payload = json_encode([
            [
                'id' => 'hero-1',
                'eyebrow' => 'Temporada',
                'title' => 'Banner principal',
                'description' => 'Texto de prueba',
                'image' => '/uploads/banners/banner.webp',
                'imagePosition' => 'center center',
                'textColor' => '#ffffff',
                'active' => true,
            ],
        ]);

        $this->putJson('/api/settings/homepage_banners', [
            'value' => $payload,
        ])->assertOk();

        $this->assertSame(
            $payload,
            Setting::where('key', 'homepage_banners')->value('value')
        );
    }

    public function test_superadmin_can_upload_and_persist_site_logo(): void
    {
        $superadmin = $this->user('logo-admin@example.com', 'superadmin');
        Sanctum::actingAs($superadmin);

        $response = $this->post('/api/settings/site_logo_image', [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('angelita.png', 600, 180),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $path = (string) $response->json('data.path');

        $this->assertStringStartsWith('/api/media/uploads/settings/logo_', $path);
        $this->assertSame($path, Setting::where('key', 'logo_url')->value('value'));

        $relative = app(MediaStorageService::class)->normalizeRelativePath($path);
        $this->assertNotNull($relative);
        $this->assertFileExists(app(MediaStorageService::class)->absolutePath($relative));
    }

    public function test_superadmin_can_publish_animated_gif_preloader_without_converting_it(): void
    {
        $admin = $this->user('preloader-admin@example.com', 'superadmin');
        Sanctum::actingAs($admin);

        $image = UploadedFile::fake()->image('precarga.gif', 320, 180);
        $originalBytes = file_get_contents($image->getPathname());
        $response = $this->post('/api/settings/store_preloader_image_upload', [
            '_method' => 'PUT',
            'image' => $image,
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.mode', 'branded');
        $path = (string) $response->json('data.path');
        $this->assertStringStartsWith('/api/media/uploads/settings/preloader_', $path);
        $this->assertStringEndsWith('.gif', $path);
        $this->assertSame($path, Setting::where('key', 'store_preloader_image')->value('value'));
        $this->assertSame('branded', Setting::where('key', 'store_preloader')->value('value'));

        $relative = app(MediaStorageService::class)->normalizeRelativePath($path);
        $this->assertNotNull($relative);
        $this->assertSame($originalBytes, file_get_contents(app(MediaStorageService::class)->absolutePath($relative)));
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.store_preloader_image', $path);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_uploading_preloader_does_not_change_the_main_store_logo(): void
    {
        Setting::create(['key' => 'logo_url', 'value' => '/api/media/uploads/settings/existing_logo.webp']);
        $admin = $this->user('preloader-other-admin@example.com', 'admin');
        Sanctum::actingAs($admin);

        $response = $this->post('/api/settings/store_preloader_image_upload', [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('precarga.png', 360, 180),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertStringEndsWith('.png', (string) $response->json('data.path'));
        $this->assertSame('/api/media/uploads/settings/existing_logo.webp', Setting::where('key', 'logo_url')->value('value'));
    }

    public function test_preloader_upload_rejects_unsupported_files_and_unauthorized_users(): void
    {
        $admin = $this->user('image-admin@example.com', 'admin');
        Sanctum::actingAs($admin);
        $this->post('/api/settings/store_preloader_image_upload', [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseMissing('settings', ['key' => 'store_preloader_image']);

        $user = $this->user('cliente-preloader@example.com', 'user');
        Sanctum::actingAs($user);
        $this->post('/api/settings/store_preloader_image_upload', [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('precarga.png', 320, 180),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_mexican_whatsapp_number_requires_ten_digits_after_country_code(): void
    {
        $admin = $this->user('whatsapp-admin@example.com', 'admin');
        Sanctum::actingAs($admin);

        $this->putJson('/api/settings/whatsapp_number', [
            'value' => '+52557863609',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->assertNull(Setting::where('key', 'whatsapp_number')->value('value'));

        $this->putJson('/api/settings/whatsapp_number', [
            'value' => '+5255123456789',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->putJson('/api/settings/whatsapp_number', [
            'value' => '+525512345678',
        ])->assertOk();

        $this->assertSame(
            '+525512345678',
            Setting::where('key', 'whatsapp_number')->value('value')
        );

        // Other countries retain the existing international-number validation.
        $this->putJson('/api/settings/whatsapp_number', [
            'value' => '+51900112844',
        ])->assertOk();
    }

    public function test_email_logo_uses_white_background_and_preserves_black_details(): void
    {
        config(['app.url' => 'https://api.zapateriangelita.com']);
        $media = app(MediaStorageService::class);
        $relative = 'uploads/settings/logo_configurado.webp';
        $media->ensureDirectory(dirname($relative));
        $source = $media->absolutePath($relative);

        $image = imagecreatetruecolor(8, 8);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesetpixel($image, 4, 4, imagecolorallocate($image, 0, 0, 0));
        $this->assertTrue(imagewebp($image, $source, IMG_WEBP_LOSSLESS));
        $original = hash_file('sha256', $source);

        $setting = Setting::create(['key' => 'logo_url', 'value' => $media->publicUrl($relative)]);
        $branding = app(BrandingService::class);
        $url = $branding->logoUrl();
        $this->assertStringStartsWith('https://api.zapateriangelita.com/api/media/uploads/settings/email-logo-white-', $url);
        $this->assertStringEndsWith('.png', $url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/png');

        $png = imagecreatefrompng($media->resolveForRead($url));
        $this->assertSame(0xffffff, imagecolorat($png, 0, 0));
        $this->assertSame(0, imagecolorat($png, 4, 4));
        $this->assertSame($original, hash_file('sha256', $source));
        $this->assertSame($media->publicUrl($relative), $setting->fresh()->value);
        $this->assertSame($url, $branding->logoUrl());

        imagesetpixel($image, 4, 4, imagecolorallocate($image, 255, 0, 0));
        $this->assertTrue(imagewebp($image, $source, IMG_WEBP_LOSSLESS));
        $this->assertNotSame($url, $branding->logoUrl());
    }

    public function test_admin_can_publish_and_remove_social_links(): void
    {
        Sanctum::actingAs($this->user('redes@example.com', 'admin'));
        $links = json_encode(['instagram' => 'https://www.instagram.com/angelita/', 'facebook' => '']);

        $this->putJson('/api/settings/social_links', ['value' => $links])->assertOk();
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.social_links', $links);

        $this->putJson('/api/settings/social_links', [
            'value' => json_encode(['instagram' => 'javascript:alert(1)', 'facebook' => '']),
        ])->assertUnprocessable()->assertJsonValidationErrors('links.instagram');

        $this->putJson('/api/settings/social_links', [
            'value' => json_encode(['instagram' => '', 'facebook' => '', 'other' => 'https://example.com']),
        ])->assertUnprocessable()->assertJsonValidationErrors('links');
        $this->assertSame($links, Setting::where('key', 'social_links')->value('value'));

        $removed = json_encode(['instagram' => '', 'facebook' => '']);
        $this->putJson('/api/settings/social_links', ['value' => $removed])->assertOk();
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.social_links', $removed);
    }

    public function test_regular_user_cannot_change_site_settings(): void
    {
        $user = $this->user('cliente@example.com', 'user');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/logo_url', [
            'value' => '/uploads/settings/logo.webp',
        ])->assertForbidden();

        $this->putJson('/api/settings/social_links', [
            'value' => json_encode(['instagram' => '', 'facebook' => '']),
        ])->assertForbidden();
    }
}
