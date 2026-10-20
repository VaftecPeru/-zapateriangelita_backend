<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'import_pending_data')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->json('import_pending_data')->nullable();
        });
    }

    public function down(): void
    {
        // No eliminar evidencia de las filas originales ni stock sin asignar.
    }
};
