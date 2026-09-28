<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoginRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'cache.stores.array' => ['driver' => 'array', 'serialize' => false],
        ]);

        Cache::clear();
        RateLimiter::clear('noop');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('temporary_password_set_at')->nullable();
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

        User::create([
            'name' => 'Cliente Angelita',
            'email' => 'cliente@angelita.test',
            'password' => Hash::make('Correcta123!'),
            'role' => 'user',
        ]);
    }

    public function test_successful_logins_do_not_accumulate_failed_attempts(): void
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'cliente@angelita.test',
                'password' => 'Correcta123!',
            ])->assertOk();
        }
    }

    public function test_failed_credentials_are_rate_limited_with_retry_after(): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'cliente@angelita.test',
                'password' => 'Incorrecta123!',
            ])->assertUnauthorized();
        }

        $response = $this->postJson('/api/login', [
            'email' => 'cliente@angelita.test',
            'password' => 'Incorrecta123!',
        ]);

        $response->assertStatus(429)
            ->assertJson([
                'error_code' => 'LOGIN_RATE_LIMITED',
            ])
            ->assertJsonStructure(['retry_after']);

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_rate_limit_for_one_email_does_not_block_another_email(): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'cliente@angelita.test',
                'password' => 'Incorrecta123!',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => 'otro@angelita.test',
            'password' => 'Incorrecta123!',
        ])->assertUnauthorized();
    }
}
