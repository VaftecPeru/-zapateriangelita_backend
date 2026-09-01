<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200)->nullable();
            $table->string('subtitle', 200)->nullable();
            $table->string('image', 255)->nullable();
            $table->string('link', 255)->nullable();
            $table->string('position', 20)->default('home');
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['position', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};