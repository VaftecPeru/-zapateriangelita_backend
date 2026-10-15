<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductLegacyColumnsCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_creation_fills_legacy_rating_and_reviews_columns(): void
    {
        $this->addLegacyColumns();

        try {
            $product = Product::create([
                'product_code' => 'LEGACY-MODEL-01',
                'name' => 'Producto legacy',
                'price' => 1299,
                'stock' => 1,
                'status' => 'normal',
                'is_active' => true,
            ]);

            $row = DB::table('products')->where('id', $product->id)->first();

            $this->assertNotNull($row);
            $this->assertSame(0.0, (float) $row->rating);
            $this->assertSame(0, (int) $row->reviews);
        } finally {
            $this->dropLegacyColumns();
        }
    }

    public function test_schema_hardening_adds_database_defaults_for_legacy_columns(): void
    {
        $this->addLegacyColumns();

        try {
            $migration = require database_path(
                'migrations/2026_10_15_073000_add_defaults_to_legacy_product_rating_reviews.php'
            );
            $migration->up();

            DB::table('products')->insert([
                'product_code' => 'LEGACY-RAW-01',
                'name' => 'Producto legacy raw',
                'price' => 999,
                'stock' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('products')->where('product_code', 'LEGACY-RAW-01')->first();

            $this->assertNotNull($row);
            $this->assertSame(0.0, (float) $row->rating);
            $this->assertSame(0, (int) $row->reviews);
        } finally {
            $this->dropLegacyColumns();
        }
    }

    private function addLegacyColumns(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'rating')) {
                $table->decimal('rating', 3, 2);
            }

            if (!Schema::hasColumn('products', 'reviews')) {
                $table->integer('reviews');
            }
        });
    }

    private function dropLegacyColumns(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'rating')) {
                $table->dropColumn('rating');
            }

            if (Schema::hasColumn('products', 'reviews')) {
                $table->dropColumn('reviews');
            }
        });
    }
}
