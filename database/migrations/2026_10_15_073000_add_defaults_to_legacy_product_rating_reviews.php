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

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'rating')) {
                $table->decimal('rating', 3, 2)->default(0)->change();
            }

            if (Schema::hasColumn('products', 'reviews')) {
                $table->integer('reviews')->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'rating')) {
                $table->decimal('rating', 3, 2)->change();
            }

            if (Schema::hasColumn('products', 'reviews')) {
                $table->integer('reviews')->change();
            }
        });
    }
};
