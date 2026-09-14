<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_country', 100)->nullable()->after('shipping_city');
            $table->string('shipping_state', 100)->nullable()->after('shipping_country');
            $table->string('shipping_municipality', 100)->nullable()->after('shipping_state');
            $table->string('shipping_postal_code', 20)->nullable()->after('shipping_municipality');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_country', 'shipping_state', 'shipping_municipality', 'shipping_postal_code']);
        });
    }
};