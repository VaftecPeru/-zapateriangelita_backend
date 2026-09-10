<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status')
                ->default('pending');

            $table->string('payment_transaction_id')
                ->nullable();

            $table->string('payment_reference')
                ->nullable();

            $table->text('payment_error')
                ->nullable();

            $table->timestamp('paid_at')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'payment_transaction_id',
                'payment_reference',
                'payment_error',
                'paid_at',
            ]);
        });
    }
};