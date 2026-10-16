<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_movements') || !Schema::hasColumn('inventory_movements', 'size')) {
            return;
        }

        // Se amplía solo la columna de auditoría de movimientos.
        // product_sizes.size y product_variant_stocks.size mantienen VARCHAR(10)
        // en la base histórica: el importador valida cada talla individual.
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->string('size', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No reducir la longitud: los nuevos movimientos podrían contener
        // tallas de más de 10 caracteres y truncarse al revertir.
    }
};
