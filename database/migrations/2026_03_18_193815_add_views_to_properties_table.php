<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy migration: the current e-commerce schema no longer contains
        // the old "properties" module. Keep fresh installs deployable without
        // recreating an obsolete table.
        if (!Schema::hasTable('properties') || Schema::hasColumn('properties', 'views')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedInteger('views')->default(0);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('properties') || !Schema::hasColumn('properties', 'views')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('views');
        });
    }
};
