<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserRoleVisibilityTest extends TestCase
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
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'role' => $role,
        ]);
    }

    public function test_superadmin_can_see_all_accounts_and_roles(): void
    {
        $superadmin = $this->makeUser('soporte@vaftec.com.pe', 'superadmin');
        $admin = $this->makeUser('admin@example.com', 'admin');
        $client = $this->makeUser('cliente@example.com', 'user');

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/users')->assertOk();
        $data = collect($response->json());

        $this->assertCount(3, $data);
        $this->assertSame('superadmin', $data->firstWhere('id', $superadmin->id)['role']);
        $this->assertSame('admin', $data->firstWhere('id', $admin->id)['role']);
        $this->assertSame('user', $data->firstWhere('id', $client->id)['role']);
    }

    public function test_admin_only_sees_own_admin_role_and_final_users_without_role_field(): void
    {
        $superadmin = $this->makeUser('soporte@vaftec.com.pe', 'superadmin');
        $admin = $this->makeUser('admin@example.com', 'admin');
        $otherAdmin = $this->makeUser('admin2@example.com', 'admin');
        $client = $this->makeUser('cliente@example.com', 'user');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users')->assertOk();
        $data = collect($response->json());

        $this->assertNull($data->firstWhere('id', $superadmin->id));
        $this->assertNull($data->firstWhere('id', $otherAdmin->id));

        $own = $data->firstWhere('id', $admin->id);
        $this->assertSame('admin', $own['role']);

        $finalUser = $data->firstWhere('id', $client->id);
        $this->assertArrayNotHasKey('role', $finalUser);
    }

    public function test_admin_cannot_fetch_superadmin_or_other_admin_directly(): void
    {
        $superadmin = $this->makeUser('soporte@vaftec.com.pe', 'superadmin');
        $admin = $this->makeUser('admin@example.com', 'admin');
        $otherAdmin = $this->makeUser('admin2@example.com', 'admin');

        Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$superadmin->id}")->assertNotFound();
        $this->getJson("/api/users/{$otherAdmin->id}")->assertNotFound();
    }

    public function test_admin_can_fetch_final_user_but_role_is_hidden(): void
    {
        $admin = $this->makeUser('admin@example.com', 'admin');
        $client = $this->makeUser('cliente@example.com', 'user');

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$client->id}")
            ->assertOk();

        $this->assertArrayNotHasKey('role', $response->json());
    }
}
