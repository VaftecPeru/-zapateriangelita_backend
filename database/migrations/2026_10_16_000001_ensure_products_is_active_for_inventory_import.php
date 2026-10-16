<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compatibilidad con bases de datos históricas de producción.
     * El importador y el catálogo usan products.is_active.
     */
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'is_active')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * No se elimina la columna al revertir: podría contener estados reales
     * de productos y su eliminación causaría pérdida de información.
     */
    public function down(): void
    {
        // Reversión no destructiva por seguridad de datos.
    }
};
