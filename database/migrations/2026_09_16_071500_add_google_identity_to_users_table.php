<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_sub', 255)->nullable()->unique()->after('email');
            $table->string('google_email', 255)->nullable()->index()->after('google_sub');
            $table->timestamp('google_linked_at')->nullable()->after('google_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_sub']);
            $table->dropIndex(['google_email']);
            $table->dropColumn(['google_sub', 'google_email', 'google_linked_at']);
        });
    }
};
