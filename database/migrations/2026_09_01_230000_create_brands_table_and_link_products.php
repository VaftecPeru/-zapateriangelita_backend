<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->nullable()->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('brand_id')->nullable()->after('id')->constrained('brands')->onDelete('set null');
            });
        }

        $brandNames = [
            'Nike',
            'adidas',
            'PUMA',
            'SKECHERS',
            'Reebok',
            'CAT',
            'Boston',
            'Catalina',
            'flexi',
            'Crocs',
        ];

        foreach ($brandNames as $brandName) {
            $cleanName = trim((string) $brandName);
            if ($cleanName === '') {
                continue;
            }

            $brand = DB::table('brands')->where('name', $cleanName)->first();
            if (!$brand) {
                $brandId = DB::table('brands')->insertGetId([
                    'name' => $cleanName,
                    'slug' => Str::slug($cleanName),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $brandId = $brand->id;
            }

            DB::table('products')
                ->whereNull('brand_id')
                ->where('name', 'like', '%' . $cleanName . '%')
                ->update(['brand_id' => $brandId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['brand_id']);
                $table->dropColumn('brand_id');
            });
        }

        Schema::dropIfExists('brands');
    }
};
