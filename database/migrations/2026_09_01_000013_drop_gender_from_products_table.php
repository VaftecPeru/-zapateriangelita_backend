<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('products', 'gender')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('gender');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'gender')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('gender')->nullable();
            });
        }
    }
};
