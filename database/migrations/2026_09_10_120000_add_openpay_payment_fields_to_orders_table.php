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
                $table->string('payment_status', 30)
                    ->nullable()
                    ->after('payment_method')
                    ->index();
            });
        }

        if (!Schema::hasColumn('orders', 'payment_reference')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_reference', 100)
                    ->nullable()
                    ->after('payment_status')
                    ->index();
            });
        }

        if (!Schema::hasColumn('orders', 'payment_transaction_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_transaction_id', 100)
                    ->nullable()
                    ->after('payment_reference')
                    ->index();
            });
        }

        if (!Schema::hasColumn('orders', 'payment_error')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('payment_error')
                    ->nullable()
                    ->after('payment_transaction_id');
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
            'payment_transaction_id',
            'payment_reference',
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
