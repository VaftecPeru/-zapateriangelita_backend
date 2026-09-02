<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Desactivar restricciones de clave foránea temporalmente
        Schema::disableForeignKeyConstraints();

        // Eliminar tablas que no son esenciales
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('brands');

        // Reactivar restricciones de clave foránea
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No restaurar - estas tablas no son críticas
    }
};
