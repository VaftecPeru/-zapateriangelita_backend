<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1. Verificar si la columna ya existe
        if (!Schema::hasColumn('products', 'category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('categories')
                    ->onDelete('set null');

                $table->index('category_id');
            });
        }

        // 2. Asignar categorías a productos existentes
        $mujer = DB::table('categories')->where('slug', 'mujer')->first();
        $hombre = DB::table('categories')->where('slug', 'hombre')->first();
        $ninos = DB::table('categories')->where('slug', 'ninos')->first();

        if ($mujer) {
            DB::table('products')
                ->where('category', 'Mujer')
                ->orWhere('category', 'mujer')
                ->update(['category_id' => $mujer->id]);
        }

        if ($hombre) {
            DB::table('products')
                ->where('category', 'Hombre')
                ->orWhere('category', 'hombre')
                ->update(['category_id' => $hombre->id]);
        }

        if ($ninos) {
            DB::table('products')
                ->where('category', 'Niños')
                ->orWhere('category', 'niños')
                ->update(['category_id' => $ninos->id]);
        }

        // Productos sin categoría (asignar a Mujer por defecto)
        if ($mujer) {
            DB::table('products')
                ->whereNull('category_id')
                ->update(['category_id' => $mujer->id]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};