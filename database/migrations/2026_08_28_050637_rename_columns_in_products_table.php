<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        /*
         * Esta migración coexistió históricamente con la migración anterior
         * que ya renombra estas mismas columnas. En una instalación nueva,
         * repetir el rename provoca errores. Solo renombramos cuando la
         * columna legado existe y la columna destino todavía no existe.
         */
        $renames = [
            'type' => 'category',
            'status' => 'brand',
            'title' => 'name',
            'beds' => 'stock',
            'baths' => 'size',
            'area' => 'color',
        ];

        foreach ($renames as $from => $to) {
            if (Schema::hasColumn('products', $from) && !Schema::hasColumn('products', $to)) {
                Schema::table('products', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('products', 'amenities')) {
                $table->dropColumn('amenities');
            }
        });

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

        $renames = [
            'category' => 'type',
            'brand' => 'status',
            'name' => 'title',
            'stock' => 'beds',
            'size' => 'baths',
            'color' => 'area',
        ];

        foreach ($renames as $from => $to) {
            if (Schema::hasColumn('products', $from) && !Schema::hasColumn('products', $to)) {
                Schema::table('products', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }

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
