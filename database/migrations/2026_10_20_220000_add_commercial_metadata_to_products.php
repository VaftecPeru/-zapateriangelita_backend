<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'product_type')) {
                $table->string('product_type', 16)->nullable();
            }
            if (!Schema::hasColumn('products', 'gender')) {
                $table->string('gender', 12)->nullable();
            }
            if (!Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode', 120)->nullable()->unique('products_barcode_unique');
            }
            if (!Schema::hasColumn('products', 'collection_name')) {
                $table->string('collection_name', 120)->nullable();
            }
            if (!Schema::hasColumn('products', 'seo_title')) {
                $table->string('seo_title', 255)->nullable();
            }
            if (!Schema::hasColumn('products', 'seo_description')) {
                $table->string('seo_description', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['seo_description', 'seo_title', 'collection_name', 'barcode', 'gender', 'product_type'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
