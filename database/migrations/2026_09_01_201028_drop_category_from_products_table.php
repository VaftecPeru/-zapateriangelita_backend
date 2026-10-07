<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'brand_id')) {
            /*
             * En el esquema legado se creó además un índice explícito sobre
             * brand_id. SQLite no permite eliminar la columna mientras ese
             * índice siga existiendo, por lo que limpiamos índice y FK de
             * forma defensiva antes de retirar la columna.
             */
            try {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropIndex('products_brand_id_index');
                });
            } catch (\Throwable $e) {
                // El índice puede no existir en instalaciones históricas.
            }

            try {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropForeign(['brand_id']);
                });
            } catch (\Throwable $e) {
                // La FK puede haber desaparecido al eliminar la tabla brands.
            }

            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('brand_id');
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'category')) {
                $table->dropColumn('category');
            }

            if (Schema::hasColumn('products', 'brand')) {
                $table->dropColumn('brand');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable();
            }

            if (!Schema::hasColumn('products', 'category')) {
                $table->string('category')->nullable();
            }
        });

        if (Schema::hasTable('brands') && !Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->constrained('brands')
                    ->nullOnDelete();
            });
        }
    }
};
