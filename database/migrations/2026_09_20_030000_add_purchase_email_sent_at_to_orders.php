<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || Schema::hasColumn('orders', 'purchase_email_sent_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('purchase_email_sent_at')->nullable()->after('paid_at');
            $table->index('purchase_email_sent_at', 'orders_purchase_email_sent_at_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'purchase_email_sent_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_purchase_email_sent_at_index');
            $table->dropColumn('purchase_email_sent_at');
        });
    }
};
