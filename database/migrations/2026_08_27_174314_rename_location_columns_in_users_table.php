<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $this->renameIfNeeded('department', 'state');
        $this->renameIfNeeded('province', 'municipality');
        $this->renameIfNeeded('district', 'city');
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $this->renameIfNeeded('state', 'department');
        $this->renameIfNeeded('municipality', 'province');
        $this->renameIfNeeded('city', 'district');
    }

    private function renameIfNeeded(string $from, string $to): void
    {
        if (!Schema::hasColumn('users', $from) || Schema::hasColumn('users', $to)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($from, $to) {
            $table->renameColumn($from, $to);
        });
    }
};
