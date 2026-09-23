<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddressColonyTest extends TestCase
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
            $table->string('password')->nullable();
            $table->string('role')->default('user');
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
            $table->string('colony', 120)->nullable();
            $table->text('reference')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function test_authenticated_customer_can_store_colony(): void
    {
        $user = User::create([
            'name' => 'Cliente',
            'email' => 'cliente-colonia@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'user',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/addresses', [
            'full_name' => 'Angela Correa',
            'phone' => '+525512345678',
            'country' => 'México',
            'state' => 'Michoacán',
            'municipality' => 'Hidalgo',
            'city' => 'Ciudad Hidalgo',
            'postal_code' => '61100',
            'colony' => 'Centro',
            'address' => 'Leandro Valle 6',
            'reference' => 'Frente al parque',
            'is_default' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('colony', 'Centro');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'state' => 'Michoacán',
            'municipality' => 'Hidalgo',
            'city' => 'Ciudad Hidalgo',
            'postal_code' => '61100',
            'colony' => 'Centro',
        ]);
    }
}
