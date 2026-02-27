<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('type');
            $blueprint->string('status');
            $blueprint->string('title');
            $blueprint->string('location');
            $blueprint->integer('beds');
            $blueprint->integer('baths');
            $blueprint->string('area'); 
            $blueprint->string('price'); 
            $blueprint->decimal('rating', 3, 2);
            $blueprint->integer('reviews');
            $blueprint->text('img');
            $blueprint->timestamps();
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
