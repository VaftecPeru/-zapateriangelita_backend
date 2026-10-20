<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_stocks', function (Blueprint $table): void {
            if (!Schema::hasColumn('product_variant_stocks', 'article_model')) {
                $table->string('article_model', 120)->nullable()->after('color');
            }
            if (!Schema::hasColumn('product_variant_stocks', 'sku')) {
                $table->string('sku', 120)->nullable()->after('size');
            }
        });
        // MySQL/MariaDB y SQLite admiten múltiples valores NULL en UNIQUE.
        Schema::table('product_variant_stocks', function (Blueprint $table): void {
            $table->unique('sku', 'product_variant_stocks_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_stocks', function (Blueprint $table): void {
            $table->dropUnique('product_variant_stocks_sku_unique');
            $table->dropColumn(['article_model', 'sku']);
        });
    }
};
