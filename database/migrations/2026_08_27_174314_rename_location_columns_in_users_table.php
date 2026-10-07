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

        $renames = [
            'department' => 'state',
            'province' => 'municipality',
            'district' => 'city',
        ];

        foreach ($renames as $from => $to) {
            if (Schema::hasColumn('users', $from) && !Schema::hasColumn('users', $to)) {
                Schema::table('users', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $renames = [
            'state' => 'department',
            'municipality' => 'province',
            'city' => 'district',
        ];

        foreach ($renames as $from => $to) {
            if (Schema::hasColumn('users', $from) && !Schema::hasColumn('users', $to)) {
                Schema::table('users', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }
    }
};
