<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('leads')) {
            return;
        }

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->default('store');
            $table->unsignedBigInteger('item_id')->default(0);
            $table->string('email');
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 30);
            $table->string('product_interest', 100)->nullable();
            $table->string('shoe_size', 20)->nullable();
            $table->string('contact_preference', 30)->nullable();
            $table->text('message')->nullable();
            $table->string('property_title')->nullable();
            $table->json('additional_services')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
