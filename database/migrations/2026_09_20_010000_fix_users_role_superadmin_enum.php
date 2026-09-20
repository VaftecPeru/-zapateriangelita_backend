<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'role')) {
            return;
        }

        DB::table('users')
            ->whereNotIn('role', ['user', 'admin', 'superadmin'])
            ->update([
                'role' => 'user',
                'updated_at' => now(),
            ]);

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE users
                 MODIFY COLUMN role
                 ENUM('user','admin','superadmin')
                 NOT NULL DEFAULT 'user'"
            );
        }

        $supportEmail = 'soporte@vaftec.com.pe';
        $legacyEmail = 'vaftec@gmail.com';

        $supportUser = DB::table('users')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$supportEmail])
            ->first();

        $legacyUser = DB::table('users')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$legacyEmail])
            ->first();

        if ($supportUser) {
            DB::table('users')
                ->where('id', $supportUser->id)
                ->update([
                    'email' => $supportEmail,
                    'role' => 'superadmin',
                    'updated_at' => now(),
                ]);

            if ($legacyUser && $legacyUser->id !== $supportUser->id) {
                DB::table('users')
                    ->where('id', $legacyUser->id)
                    ->update([
                        'email' => $legacyEmail,
                        'role' => 'admin',
                        'updated_at' => now(),
                    ]);
            }
        } elseif ($legacyUser) {
            DB::table('users')
                ->where('id', $legacyUser->id)
                ->update([
                    'email' => $supportEmail,
                    'role' => 'superadmin',
                    'updated_at' => now(),
                ]);
        }

        if (!Schema::hasTable('user_role_audits')) {
            Schema::create('user_role_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('old_role', 30);
                $table->string('new_role', 30);
                $table->string('ip_address', 64)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
                $table->index(['actor_user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'role')) {
            return;
        }

        DB::table('users')
            ->whereRaw('LOWER(TRIM(email)) = ?', ['soporte@vaftec.com.pe'])
            ->where('role', 'superadmin')
            ->update([
                'role' => 'admin',
                'updated_at' => now(),
            ]);

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE users
                 MODIFY COLUMN role
                 ENUM('user','admin')
                 NOT NULL DEFAULT 'user'"
            );
        }
    }
};
