<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('slug');
            $table->index('is_active');
        });

        // Agregar brand_id a products (nullable para no romper productos existentes)
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('brands')
                    ->onDelete('set null');

                $table->index('brand_id');
            });
        }

        // Poblar automáticamente las marcas desde los productos existentes
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'brand')) {
            $brandNames = DB::table('products')
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->distinct()
                ->pluck('brand');

            foreach ($brandNames as $name) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }

                $name = trim($name);
                $existing = DB::table('brands')->where('name', $name)->first();

                if ($existing) {
                    $brandId = $existing->id;
                } else {
                    $brandId = DB::table('brands')->insertGetId([
                        'name' => $name,
                        'slug' => Str::slug($name),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('products')->where('brand', $name)->update(['brand_id' => $brandId]);
            }
        }
    }

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