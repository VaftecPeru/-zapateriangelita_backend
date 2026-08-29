<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $columns = Schema::getColumnListing('users');

        if (in_array('department', $columns)) {
            DB::statement('ALTER TABLE users CHANGE department state VARCHAR(255) NULL');
        }

        if (in_array('province', $columns)) {
            DB::statement('ALTER TABLE users CHANGE province municipality VARCHAR(255) NULL');
        }

        if (in_array('district', $columns)) {
            DB::statement('ALTER TABLE users CHANGE district city VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $columns = Schema::getColumnListing('users');

        if (in_array('state', $columns)) {
            DB::statement('ALTER TABLE users CHANGE state department VARCHAR(255) NULL');
        }

        if (in_array('municipality', $columns)) {
            DB::statement('ALTER TABLE users CHANGE municipality province VARCHAR(255) NULL');
        }

        if (in_array('city', $columns)) {
            DB::statement('ALTER TABLE users CHANGE city district VARCHAR(255) NULL');
        }
    }
};