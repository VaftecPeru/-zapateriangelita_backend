<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('item_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->after('last_name');
            $table->date('check_in')->nullable()->after('phone');
            $table->date('check_out')->nullable()->after('check_in');
            $table->integer('guests')->nullable()->after('check_out');
            $table->string('property_title')->nullable()->after('guests');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'phone', 'check_in', 'check_out', 'guests', 'property_title']);
        });
    }
};

