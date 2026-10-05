<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                try {
                    $table->dropForeign(['brand_id']);
                } catch (\Throwable $e) {
                    // Some legacy databases do not have the FK anymore.
                }

                try {
                    $table->dropIndex(['brand_id']);
                } catch (\Throwable $e) {
                    // SQLite/MySQL may already have removed the index with the FK.
                }
            });

            if (Schema::hasColumn('products', 'brand_id')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropColumn('brand_id');
                });
            }
        }

        if (Schema::hasColumn('products', 'brand')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('brand');
            });
        }

        if (Schema::hasColumn('products', 'category')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable();
            }

            if (!Schema::hasColumn('products', 'category')) {
                $table->string('category')->nullable();
            }
        });

        if (!Schema::hasColumn('products', 'brand_id') && Schema::hasTable('brands')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            });
        }
    }
};
