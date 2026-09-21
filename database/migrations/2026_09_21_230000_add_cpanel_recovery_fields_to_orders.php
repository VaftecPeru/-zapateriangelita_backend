<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'credential_email_ciphertext')) {
                $table->text('credential_email_ciphertext')->nullable()->after('purchase_email_sent_at');
            }
            if (!Schema::hasColumn('orders', 'credential_email_sent_at')) {
                $table->timestamp('credential_email_sent_at')->nullable()->after('credential_email_ciphertext');
            }
            if (!Schema::hasColumn('orders', 'notification_attempts')) {
                $table->unsignedSmallInteger('notification_attempts')->default(0)->after('credential_email_sent_at');
            }
            if (!Schema::hasColumn('orders', 'notification_next_attempt_at')) {
                $table->timestamp('notification_next_attempt_at')->nullable()->index()->after('notification_attempts');
            }
            if (!Schema::hasColumn('orders', 'notification_last_error')) {
                $table->text('notification_last_error')->nullable()->after('notification_next_attempt_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'notification_last_error',
                'notification_next_attempt_at',
                'notification_attempts',
                'credential_email_sent_at',
                'credential_email_ciphertext',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
