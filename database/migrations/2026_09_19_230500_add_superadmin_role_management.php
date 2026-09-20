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
            $supportEmail = 'soporte@vaftec.com.pe';
            $legacyEmail = 'vaftec@gmail.com';

            $supportUser = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$supportEmail])
                ->first();

            if ($supportUser) {
                DB::table('users')
                    ->where('id', $supportUser->id)
                    ->update([
                        'role' => 'superadmin',
                        'updated_at' => now(),
                    ]);

                // Si aún existe la cuenta antigua, conserva acceso administrativo
                // pero el privilegio máximo queda centralizado en soporte@vaftec.com.pe.
                DB::table('users')
                    ->whereRaw('LOWER(email) = ?', [$legacyEmail])
                    ->where('id', '<>', $supportUser->id)
                    ->update([
                        'role' => 'admin',
                        'updated_at' => now(),
                    ]);
            } else {
                // Compatibilidad con instalaciones donde la cuenta todavía usa
                // vaftec@gmail.com: se migra el mismo usuario al correo corporativo.
                DB::table('users')
                    ->whereRaw('LOWER(email) = ?', [$legacyEmail])
                    ->update([
                        'email' => $supportEmail,
                        'role' => 'superadmin',
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->whereRaw('LOWER(email) = ?', ['soporte@vaftec.com.pe'])
                ->where('role', 'superadmin')
                ->update([
                    'role' => 'admin',
                    'updated_at' => now(),
                ]);
        }

        Schema::dropIfExists('user_role_audits');
    }
};
