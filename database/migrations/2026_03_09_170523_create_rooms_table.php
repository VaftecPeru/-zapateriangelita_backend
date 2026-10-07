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
        /*
         * Compatibilidad para instalaciones nuevas.
         *
         * El historial original del proyecto partía de una tabla `properties`
         * creada fuera del conjunto de migraciones que llegó al repositorio.
         * Varias migraciones antiguas todavía la necesitan antes de que sea
         * renombrada a `products`. Crear aquí el esquema legado mínimo hace
         * que `migrate:fresh` sea reproducible sin afectar instalaciones que
         * ya tengan la tabla.
         */
        if (!Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('type')->nullable();
                $table->string('status')->nullable();
                $table->unsignedInteger('beds')->default(0);
                $table->string('baths')->nullable();
                $table->string('area')->nullable();
                $table->string('location')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('img')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('properties')->onDelete('cascade');
                $table->string('room_number');
                $table->string('type'); // Simple, Double, Suite, etc.
                $table->decimal('price', 10, 2);
                $table->string('status')->default('available'); // available, occupied, maintenance
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');

        // No se elimina `properties` aquí porque en instalaciones históricas
        // esa tabla no era propiedad de esta migración.
    }
};
