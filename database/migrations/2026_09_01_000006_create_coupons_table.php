<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('type', 10)->default('percent'); // percent | fixed
            $table->decimal('value', 10, 2);
            $table->decimal('min_amount', 10, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('max_uses')->nullable();
            $table->integer('used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};