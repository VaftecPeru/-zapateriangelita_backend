<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
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
        File::deleteDirectory(public_path('uploads/settings'));
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

        $this->assertStringStartsWith('/uploads/settings/logo_', $path);
        $this->assertSame($path, Setting::where('key', 'logo_url')->value('value'));
        $this->assertFileExists(public_path(ltrim($path, '/')));
    }

    public function test_regular_user_cannot_change_site_settings(): void
    {
        $user = $this->user('cliente@example.com', 'user');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/logo_url', [
            'value' => '/uploads/settings/logo.webp',
        ])->assertForbidden();
    }
}
