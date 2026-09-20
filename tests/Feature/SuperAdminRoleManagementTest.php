<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminRoleManagementTest extends TestCase
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

        Schema::create('user_role_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('old_role', 30);
            $table->string('new_role', 30);
            $table->string('ip_address', 64)->nullable();
            $table->timestamps();
        });
    }

    public function test_superadmin_can_promote_user_to_admin(): void
    {
        $superadmin = User::create([
            'name' => 'Super Admin',
            'email' => 'baftec@gmail.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'superadmin',
        ]);

        $client = User::create([
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);

        Sanctum::actingAs($superadmin);

        $this->putJson("/api/users/{$client->id}/role", [
            'role' => 'admin',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'role' => 'admin',
        ]);

        $this->assertDatabaseHas('user_role_audits', [
            'user_id' => $client->id,
            'actor_user_id' => $superadmin->id,
            'old_role' => 'user',
            'new_role' => 'admin',
        ]);
    }

    public function test_regular_admin_cannot_change_roles(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin',
        ]);

        $client = User::create([
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$client->id}/role", [
            'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'role' => 'user',
        ]);
    }

    public function test_superadmin_cannot_demote_itself_from_role_management(): void
    {
        $superadmin = User::create([
            'name' => 'Super Admin',
            'email' => 'baftec@gmail.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'superadmin',
        ]);

        Sanctum::actingAs($superadmin);

        $this->putJson("/api/users/{$superadmin->id}/role", [
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id' => $superadmin->id,
            'role' => 'superadmin',
        ]);
    }
}
