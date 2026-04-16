<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
      
            if (!Schema::hasColumn('users', 'gender')) $table->string('gender')->nullable()->after('password');
            if (!Schema::hasColumn('users', 'birthdate')) $table->date('birthdate')->nullable()->after('gender');
            if (!Schema::hasColumn('users', 'department')) $table->string('department')->nullable()->after('birthdate');
            if (!Schema::hasColumn('users', 'province')) $table->string('province')->nullable()->after('department');
            if (!Schema::hasColumn('users', 'district')) $table->string('district')->nullable()->after('province');
        });
    }

    
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birthdate', 'department', 'province', 'district']);
        });
    }
};
