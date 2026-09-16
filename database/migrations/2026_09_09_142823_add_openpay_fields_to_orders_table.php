<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (!Schema::hasColumn('orders', 'payment_status')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_status')
                    ->default('pending')
                    ->after('payment_method');
            });
        }

        if (!Schema::hasColumn('orders', 'payment_transaction_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_transaction_id')
                    ->nullable()
                    ->after('payment_status');
            });
        }

        if (!Schema::hasColumn('orders', 'payment_reference')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_reference')
                    ->nullable()
                    ->after('payment_transaction_id');
            });
        }

        if (!Schema::hasColumn('orders', 'payment_error')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('payment_error')
                    ->nullable()
                    ->after('payment_reference');
            });
        }

        if (!Schema::hasColumn('orders', 'paid_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('paid_at')
                    ->nullable()
                    ->after('payment_error');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        foreach ([
            'paid_at',
            'payment_error',
            'payment_reference',
            'payment_transaction_id',
            'payment_status',
        ] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};