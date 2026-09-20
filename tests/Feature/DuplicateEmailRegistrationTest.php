<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DuplicateEmailRegistrationTest extends TestCase
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
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('user');
            $table->string('gender')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('temporary_password_set_at')->nullable();
            $table->string('google_sub')->nullable();
            $table->string('google_email')->nullable();
            $table->timestamp('google_linked_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function test_registration_rejects_same_email_ignoring_case_and_spaces(): void
    {
        User::create([
            'name' => 'Cliente Existente',
            'email' => 'Cliente@Example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);

        $response = $this->postJson('/api/register', [
            'name' => 'Cliente Nuevo',
            'email' => '  cliente@example.com  ',
            'password' => 'Nueva123',
            'password_confirmation' => 'Nueva123',
            'gender' => 'Masculino',
            'birthdate' => '1990-01-01',
            'phone' => '+525512345678',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'error_code' => 'EMAIL_ALREADY_REGISTERED',
            ])
            ->assertJsonPath('errors.email.0', 'Este correo electrónico ya está registrado.');

        $this->assertDatabaseCount('users', 1);
    }
}
