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

        // This migration historically duplicated part of the previous
        // property-to-product migration. Rename only columns that still exist.
        $this->renameIfNeeded('type', 'category');
        $this->renameIfNeeded('status', 'brand');
        $this->renameIfNeeded('title', 'name');
        $this->renameIfNeeded('beds', 'stock');
        $this->renameIfNeeded('baths', 'size');
        $this->renameIfNeeded('area', 'color');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('products', 'amenities')) {
                $table->dropColumn('amenities');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'material')) {
                $table->string('material')->nullable();
            }
            if (!Schema::hasColumn('products', 'gender')) {
                $table->string('gender')->nullable();
            }
            if (!Schema::hasColumn('products', 'discount')) {
                $table->string('discount')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'material')) {
                $table->dropColumn('material');
            }
            if (Schema::hasColumn('products', 'gender')) {
                $table->dropColumn('gender');
            }
            if (Schema::hasColumn('products', 'discount')) {
                $table->dropColumn('discount');
            }
        });

        $this->renameIfNeeded('category', 'type');
        $this->renameIfNeeded('brand', 'status');
        $this->renameIfNeeded('name', 'title');
        $this->renameIfNeeded('stock', 'beds');
        $this->renameIfNeeded('size', 'baths');
        $this->renameIfNeeded('color', 'area');
    }

    private function renameIfNeeded(string $from, string $to): void
    {
        if (!Schema::hasColumn('products', $from) || Schema::hasColumn('products', $to)) {
            return;
        }

        Schema::table('products', function (Blueprint $table) use ($from, $to) {
            $table->renameColumn($from, $to);
        });
    }
};
