<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'shipping_colony')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('shipping_colony', 120)
                    ->nullable()
                    ->after('shipping_postal_code');
            });
        }

        if (Schema::hasTable('addresses') && !Schema::hasColumn('addresses', 'colony')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->string('colony', 120)
                    ->nullable()
                    ->after('postal_code');
            });
        }
    }

    public function down(): void
    {
        // Migración de reconciliación: en producción estas columnas pueden
        // haber sido creadas manualmente antes de ejecutar artisan migrate.
        // No se eliminan automáticamente para evitar pérdida de datos.
    }
};
