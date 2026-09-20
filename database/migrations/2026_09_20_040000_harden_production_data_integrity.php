<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeProducts();
        $this->repairColorPalette();
        $this->backfillBrandSlugs();
        $this->deduplicateAddresses();
        $this->cleanAccessTokens();
    }

    public function down(): void
    {
        // Data-integrity maintenance is intentionally not reversed.
    }

    private function normalizeProducts(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        DB::table('products')
            ->whereNotNull('product_code')
            ->orderBy('id')
            ->get(['id', 'product_code'])
            ->each(function ($product) {
                $normalized = trim((string) $product->product_code);

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'product_code' => $normalized !== '' ? $normalized : null,
                    ]);
            });

        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $invalidPriceCount = (int) (DB::selectOne(
            "SELECT COUNT(*) AS total
             FROM products
             WHERE price IS NULL
                OR TRIM(price) = ''
                OR TRIM(price) NOT REGEXP '^[0-9]+([.][0-9]{1,2})?$'"
        )->total ?? 0);

        if ($invalidPriceCount > 0) {
            throw new RuntimeException(
                "Hay {$invalidPriceCount} producto(s) con precio no numérico. Corrige esos datos antes de continuar."
            );
        }

        DB::statement(
            "ALTER TABLE products
             MODIFY COLUMN price DECIMAL(10,2) NOT NULL,
             MODIFY COLUMN discount DECIMAL(5,2) NULL,
             MODIFY COLUMN img TEXT NULL,
             MODIFY COLUMN stock INT UNSIGNED NOT NULL DEFAULT 0"
        );

        $duplicateCode = DB::selectOne(
            "SELECT product_code, COUNT(*) AS total
             FROM products
             WHERE product_code IS NOT NULL
             GROUP BY product_code
             HAVING COUNT(*) > 1
             LIMIT 1"
        );

        if ($duplicateCode) {
            throw new RuntimeException(
                'Existen códigos de producto duplicados. No se puede crear el índice único.'
            );
        }

        if (!$this->indexExists('products', 'products_product_code_unique')) {
            DB::statement(
                'ALTER TABLE products ADD UNIQUE KEY products_product_code_unique (product_code)'
            );
        }

        if (!$this->indexExists('products', 'products_status_stock_index')) {
            DB::statement(
                'ALTER TABLE products ADD INDEX products_status_stock_index (status, stock)'
            );
        }

        if (!$this->indexExists('addresses', 'addresses_user_default_index') && Schema::hasTable('addresses')) {
            DB::statement(
                'ALTER TABLE addresses ADD INDEX addresses_user_default_index (user_id, is_default)'
            );
        }
    }

    private function repairColorPalette(): void
    {
        if (!Schema::hasTable('product_colors')) {
            return;
        }

        $palette = [
            'arena' => '#d6b98c',
            'café' => '#6f4e37',
            'cafe' => '#6f4e37',
            'crema' => '#fffdd0',
            'marron oscuro' => '#4a2c2a',
            'marrón oscuro' => '#4a2c2a',
            'celeste' => '#7dd3fc',
            'azul cielo' => '#7dd3fc',
            'turquesa' => '#2dd4bf',
            'naranja' => '#f97316',
            'vino' => '#7f1d1d',
            'dorado' => '#d4a017',
            'plateado' => '#c0c0c0',
        ];

        DB::table('product_colors')
            ->where(function ($query) {
                $query->whereNull('hex')->orWhere('hex', '#888888');
            })
            ->orderBy('id')
            ->get(['id', 'color'])
            ->each(function ($row) use ($palette) {
                $normalized = mb_strtolower(trim((string) $row->color));

                if (isset($palette[$normalized])) {
                    DB::table('product_colors')
                        ->where('id', $row->id)
                        ->update(['hex' => $palette[$normalized]]);
                }
            });
    }

    private function backfillBrandSlugs(): void
    {
        if (!Schema::hasTable('brands') || !Schema::hasColumn('brands', 'slug')) {
            return;
        }

        $used = DB::table('brands')
            ->whereNotNull('slug')
            ->pluck('slug')
            ->map(fn ($slug) => mb_strtolower(trim((string) $slug)))
            ->filter()
            ->flip()
            ->all();

        DB::table('brands')
            ->where(function ($query) {
                $query->whereNull('slug')->orWhere('slug', '');
            })
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($brand) use (&$used) {
                $base = Str::slug((string) $brand->name) ?: 'marca-' . $brand->id;
                $slug = $base;
                $counter = 2;

                while (isset($used[mb_strtolower($slug)])) {
                    $slug = $base . '-' . $counter;
                    $counter++;
                }

                DB::table('brands')->where('id', $brand->id)->update(['slug' => $slug]);
                $used[mb_strtolower($slug)] = true;
            });
    }

    private function deduplicateAddresses(): void
    {
        if (!Schema::hasTable('addresses')) {
            return;
        }

        $groups = [];

        DB::table('addresses')
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->each(function ($address) use (&$groups) {
                $parts = [
                    $address->user_id,
                    $address->full_name,
                    $address->phone,
                    $address->country,
                    $address->state,
                    $address->municipality,
                    $address->address,
                    $address->city,
                    $address->postal_code,
                    $address->reference,
                ];

                $fingerprint = implode('|', array_map(
                    fn ($value) => mb_strtolower(trim((string) ($value ?? ''))),
                    $parts,
                ));

                $groups[$fingerprint][] = $address;
            });

        foreach ($groups as $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }

            usort($duplicates, function ($a, $b) {
                $defaultComparison = ((int) $b->is_default) <=> ((int) $a->is_default);

                return $defaultComparison !== 0
                    ? $defaultComparison
                    : ((int) $b->id <=> (int) $a->id);
            });

            $keep = array_shift($duplicates);
            $deleteIds = array_map(fn ($address) => $address->id, $duplicates);

            if ($deleteIds) {
                DB::table('addresses')->whereIn('id', $deleteIds)->delete();
            }

            if ((int) $keep->is_default === 1) {
                DB::table('addresses')
                    ->where('user_id', $keep->user_id)
                    ->where('id', '<>', $keep->id)
                    ->update(['is_default' => 0]);
            }
        }
    }

    private function cleanAccessTokens(): void
    {
        if (!Schema::hasTable('personal_access_tokens') || !Schema::hasTable('users')) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'personal_access_tokens.tokenable_id');
            })
            ->delete();

        $userIds = DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->distinct()
            ->pluck('tokenable_id');

        foreach ($userIds as $userId) {
            $oldIds = DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->where('tokenable_id', $userId)
                ->orderByDesc('id')
                ->pluck('id')
                ->slice(20)
                ->values()
                ->all();

            if ($oldIds) {
                DB::table('personal_access_tokens')->whereIn('id', $oldIds)->delete();
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS total
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND index_name = ?',
            [$table, $index],
        )->total ?? 0) > 0;
    }
};
