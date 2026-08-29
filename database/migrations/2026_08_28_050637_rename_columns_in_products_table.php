<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Verificar si la tabla existe
        if (!Schema::hasTable('products')) {
            return;
        }

        // Usar DB::statement con CHANGE en lugar de renameColumn
        try {
            // Renombrar columnas usando CHANGE (compatible con MariaDB)
            DB::statement('ALTER TABLE products CHANGE type category VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE status brand VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE title name VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE beds stock INT NULL');
            DB::statement('ALTER TABLE products CHANGE baths size VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE area color VARCHAR(255) NULL');
        } catch (\Exception $e) {
            // Si falla, intentar con renameColumn (para MySQL 8+)
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('type', 'category');
                $table->renameColumn('status', 'brand');
                $table->renameColumn('title', 'name');
                $table->renameColumn('beds', 'stock');
                $table->renameColumn('baths', 'size');
                $table->renameColumn('area', 'color');
            });
        }

        // Eliminar columnas que ya no se usan
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('products', 'amenities')) {
                $table->dropColumn('amenities');
            }
        });

        // Agregar nuevas columnas
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

        // Revertir con CHANGE
        try {
            DB::statement('ALTER TABLE products CHANGE category type VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE brand status VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE name title VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE stock beds INT NULL');
            DB::statement('ALTER TABLE products CHANGE size baths VARCHAR(255) NULL');
            DB::statement('ALTER TABLE products CHANGE color area VARCHAR(255) NULL');
        } catch (\Exception $e) {
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('category', 'type');
                $table->renameColumn('brand', 'status');
                $table->renameColumn('name', 'title');
                $table->renameColumn('stock', 'beds');
                $table->renameColumn('size', 'baths');
                $table->renameColumn('color', 'area');
            });
        }

        // Eliminar nuevas columnas
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'material')) {
                $table->dropColumn('material');
            }
            if (Schema::hasColumn('products', 'gender')) {
                $table->dropColumn('gender');
            }
            if (Schema::hasColumn('products', 'discount')) {
                $table->dropColumn('discount');
            }
        });

        // Restaurar columnas eliminadas
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'location')) {
                $table->string('location')->nullable();
            }
            if (!Schema::hasColumn('products', 'amenities')) {
                $table->string('amenities')->nullable();
            }
        });
    }
};