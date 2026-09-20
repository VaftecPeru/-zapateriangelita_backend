<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuperAdminDatabaseReconciliationTest extends TestCase
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
    }

    public function test_existing_support_account_is_promoted_to_superadmin(): void
    {
        DB::table('users')->insert([
            'name' => 'Administrador',
            'email' => 'soporte@vaftec.com.pe',
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'name' => 'Vaftec Legacy',
            'email' => 'vaftec@gmail.com',
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_20_010000_fix_users_role_superadmin_enum.php');
        $migration->up();

        $this->assertDatabaseHas('users', [
            'email' => 'soporte@vaftec.com.pe',
            'role' => 'superadmin',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'vaftec@gmail.com',
            'role' => 'admin',
        ]);

        $this->assertTrue(Schema::hasTable('user_role_audits'));
    }

    public function test_legacy_account_is_reused_when_support_account_does_not_exist(): void
    {
        DB::table('users')->insert([
            'name' => 'Vaftec Legacy',
            'email' => 'vaftec@gmail.com',
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_20_010000_fix_users_role_superadmin_enum.php');
        $migration->up();

        $this->assertDatabaseHas('users', [
            'email' => 'soporte@vaftec.com.pe',
            'role' => 'superadmin',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'vaftec@gmail.com',
        ]);
    }
}
