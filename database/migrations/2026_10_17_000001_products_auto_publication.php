<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'auto_publish')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('auto_publish')->default(false);
        });
    }

    public function down(): void
    {
        // Conserva la intención de publicación en una posible reversión.
    }
};
