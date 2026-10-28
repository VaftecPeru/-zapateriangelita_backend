<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // En cPanel pueden existir instalaciones antiguas que ya poseen
        // la tabla. Nunca borrar ni reemplazar sus tokens existentes.
        if (Schema::hasTable('password_reset_tokens')) {
            foreach (['email', 'token', 'created_at'] as $column) {
                if (!Schema::hasColumn('password_reset_tokens', $column)) {
                    throw new RuntimeException(
                        "La tabla password_reset_tokens existe pero le falta {$column}; requiere revision manual."
                    );
                }
            }

            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        // Rollback intencionalmente no destructivo:
        // la tabla puede contener tokens existentes antes de esta migracion.
    }
};
