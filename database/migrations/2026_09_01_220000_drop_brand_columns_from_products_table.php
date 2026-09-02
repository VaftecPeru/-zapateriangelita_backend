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
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'brand_id')) {
                try {
                    $table->dropForeign(['brand_id']);
                } catch (\Throwable $e) {
                    // Ignore if the FK is already absent.
                }

                $table->dropColumn('brand_id');
            }

            if (Schema::hasColumn('products', 'brand')) {
                $table->dropColumn('brand');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable()->after('id');
            }

            if (!Schema::hasColumn('products', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('id')->constrained('brands')->onDelete('set null');
            }
        });
    }
};
