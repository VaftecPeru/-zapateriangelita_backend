<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Production already has the canonical products table. This baseline
        // exists only to make a fresh installation capable of replaying the
        // historical property-to-product migrations that remain in the repo.
        if (Schema::hasTable('products') || Schema::hasTable('properties')) {
            return;
        }

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

    public function down(): void
    {
        // Never drop the canonical products table from this legacy bootstrap.
        if (Schema::hasTable('properties')) {
            Schema::drop('properties');
        }
    }
};
