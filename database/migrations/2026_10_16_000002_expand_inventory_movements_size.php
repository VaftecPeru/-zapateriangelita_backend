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

        // La tabla histórica usa VARCHAR(10), mientras products, product_sizes
        // y product_variant_stocks admiten tallas de hasta 255 caracteres.
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
