<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('additional_services')) {
            return;
        }

        if (!Schema::hasColumn('additional_services', 'code')) {
            Schema::table('additional_services', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->after('name');
                $table->unique('code', 'additional_services_code_unique');
            });
        }

        if (!Schema::hasColumn('additional_services', 'is_active')) {
            Schema::table('additional_services', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('tag');
                $table->index('is_active', 'additional_services_is_active_index');
            });
        }

        $delivery = DB::table('additional_services')
            ->where(function ($query) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ['%delivery%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%envio%']);
            })
            ->orderBy('id')
            ->first();

        if ($delivery) {
            $updates = [
                'code' => 'delivery',
                'updated_at' => now(),
            ];

            // Si la columna ya existía en producción, conserva el estado configurado
            // por el administrador. Una reconciliación de migraciones no debe
            // reactivar delivery de forma silenciosa.
            if ($delivery->is_active === null) {
                $updates['is_active'] = true;
            }

            DB::table('additional_services')
                ->where('id', $delivery->id)
                ->update($updates);
        } else {
            DB::table('additional_services')->insert([
                'name' => 'Costo de delivery',
                'code' => 'delivery',
                'description' => 'Costo de entrega aplicado automáticamente al total de la compra.',
                'price' => 0,
                'tag' => 'Delivery',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('additional_services')) {
            return;
        }

        if (Schema::hasColumn('additional_services', 'code')) {
            DB::table('additional_services')
                ->where('code', 'delivery')
                ->update(['code' => null, 'updated_at' => now()]);
        }

        if (Schema::hasColumn('additional_services', 'is_active')) {
            Schema::table('additional_services', function (Blueprint $table) {
                $table->dropIndex('additional_services_is_active_index');
                $table->dropColumn('is_active');
            });
        }

        if (Schema::hasColumn('additional_services', 'code')) {
            Schema::table('additional_services', function (Blueprint $table) {
                $table->dropUnique('additional_services_code_unique');
                $table->dropColumn('code');
            });
        }
    }
};
