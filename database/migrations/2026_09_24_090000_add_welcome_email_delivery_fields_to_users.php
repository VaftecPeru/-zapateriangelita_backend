<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (!Schema::hasColumn('users', 'welcome_email_method')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('welcome_email_method', 20)->nullable()->after('google_linked_at');
            });
        }

        if (!Schema::hasColumn('users', 'welcome_email_sent_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('welcome_email_sent_at')->nullable()->after('welcome_email_method');
            });
        }

        if (!Schema::hasColumn('users', 'welcome_email_attempts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('welcome_email_attempts')->default(0)->after('welcome_email_sent_at');
            });
        }

        if (!Schema::hasColumn('users', 'welcome_email_next_attempt_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('welcome_email_next_attempt_at')->nullable()->index()->after('welcome_email_attempts');
            });
        }

        if (!Schema::hasColumn('users', 'welcome_email_last_error')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('welcome_email_last_error')->nullable()->after('welcome_email_next_attempt_at');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        foreach ([
            'welcome_email_last_error',
            'welcome_email_next_attempt_at',
            'welcome_email_attempts',
            'welcome_email_sent_at',
            'welcome_email_method',
        ] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
