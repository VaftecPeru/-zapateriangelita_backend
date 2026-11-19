<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->timestamp('welcome_due_at')->nullable()->index();
            $table->string('last_digest_month', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->dropIndex(['welcome_due_at']);
            $table->dropColumn(['welcome_due_at', 'last_digest_month']);
        });
    }
};
