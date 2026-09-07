<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->after('phone');
            $table->string('state', 100)->nullable()->after('country');
            $table->string('municipality', 100)->nullable()->after('state');
            $table->string('postal_code', 20)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['country', 'state', 'municipality', 'postal_code']);
        });
    }
};