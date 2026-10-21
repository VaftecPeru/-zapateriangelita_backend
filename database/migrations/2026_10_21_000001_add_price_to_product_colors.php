<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('product_colors', 'price')) {
            Schema::table('product_colors', function (Blueprint $table) {
                // NULL inherits the general price stored in products.price.
                $table->decimal('price', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_colors', 'price')) {
            Schema::table('product_colors', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }
    }
};
