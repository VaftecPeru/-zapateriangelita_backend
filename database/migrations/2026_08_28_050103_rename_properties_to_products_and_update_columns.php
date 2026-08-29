<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 🔍 Verificar si la tabla existe
        if (!Schema::hasTable('properties')) {
            return;
        }

        // 1. Eliminar claves foráneas (si existen)
        try {
            DB::statement('ALTER TABLE properties DROP FOREIGN KEY properties_category_id_foreign');
        } catch (\Exception $e) {
            // La clave no existe, continuar
        }

        // 2. Renombrar la tabla
        Schema::rename('properties', 'products');

        // 3. Renombrar columnas (sin 'after')
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('type', 'category');
            $table->renameColumn('status', 'brand');
            $table->renameColumn('title', 'name');
            $table->renameColumn('beds', 'stock');
            $table->renameColumn('baths', 'size');
            $table->renameColumn('area', 'color');

            // Eliminar columnas que ya no se usan
            if (Schema::hasColumn('products', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('products', 'amenities')) {
                $table->dropColumn('amenities');
            }
        });

        // 4. Agregar nuevas columnas (en un Schema::table separado)
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'material')) {
                $table->string('material')->nullable();
            }
            if (!Schema::hasColumn('products', 'gender')) {
                $table->string('gender')->nullable();
            }
            if (!Schema::hasColumn('products', 'discount')) {
                $table->string('discount')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            // Eliminar columnas agregadas
            if (Schema::hasColumn('products', 'material')) {
                $table->dropColumn('material');
            }
            if (Schema::hasColumn('products', 'gender')) {
                $table->dropColumn('gender');
            }
            if (Schema::hasColumn('products', 'discount')) {
                $table->dropColumn('discount');
            }

            // Restaurar columnas eliminadas
            if (!Schema::hasColumn('products', 'location')) {
                $table->string('location')->nullable();
            }
            if (!Schema::hasColumn('products', 'amenities')) {
                $table->string('amenities')->nullable();
            }

            // Revertir renombres
            $table->renameColumn('category', 'type');
            $table->renameColumn('brand', 'status');
            $table->renameColumn('name', 'title');
            $table->renameColumn('stock', 'beds');
            $table->renameColumn('size', 'baths');
            $table->renameColumn('color', 'area');
        });

        Schema::rename('products', 'properties');
    }
};