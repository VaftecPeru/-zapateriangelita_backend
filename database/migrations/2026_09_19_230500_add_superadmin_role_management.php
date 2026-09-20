<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        if (Schema::hasTable('users')) {
            DB::table('users')
                ->whereRaw('LOWER(email) = ?', ['baftec@gmail.com'])
                ->update([
                    'role' => 'superadmin',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->whereRaw('LOWER(email) = ?', ['baftec@gmail.com'])
                ->where('role', 'superadmin')
                ->update([
                    'role' => 'admin',
                    'updated_at' => now(),
                ]);
        }

        Schema::dropIfExists('user_role_audits');
    }
};
