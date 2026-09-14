<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('orders')->where('status', 'pending')->update(['status' => 'sin_preparar']);
        DB::table('orders')->where('status', 'confirmed')->update(['status' => 'preparado']);
        DB::table('orders')->where('status', 'shipped')->update(['status' => 'enviado']);
        DB::table('orders')->where('status', 'delivered')->update(['status' => 'entregado']);
        DB::table('orders')->where('status', 'cancelled')->update(['status' => 'cancelado']);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'sin_preparar')->update(['status' => 'pending']);
        DB::table('orders')->where('status', 'preparado')->update(['status' => 'confirmed']);
        DB::table('orders')->where('status', 'enviado')->update(['status' => 'shipped']);
        DB::table('orders')->where('status', 'entregado')->update(['status' => 'delivered']);
        DB::table('orders')->where('status', 'cancelado')->update(['status' => 'cancelled']);
    }
};
