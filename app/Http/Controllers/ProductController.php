<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductPublicationService;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use App\Services\MediaStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

class ProductController extends Controller
{
    public function __construct(private readonly MediaStorageService $media)
    {
    }

    private function deleteUploadImages(array $images, array $keptImages = []): void
    {
        $kept = array_values(array_filter(array_map(
            fn ($image) => $this->media->normalizeRelativePath($image),
            $keptImages,
        )));

        foreach (array_unique($images) as $image) {
            $relative = $this->media->normalizeRelativePath($image);

            if ($relative && !in_array($relative, $kept, true)) {
                $this->media->deleteReference($image);
            }
        }
    }

    private function flattenImageValues(?array $images): array
    {
        if (!$images) {
            return [];
        }

        $result = [];
        array_walk_recursive($images, function ($image) use (&$result) {
            if (is_string($image) && $image !== '') {
                $result[] = $image;
            }
        });

        return $result;
    }

    private function optimizeImage($file, $subfolder = 'products')
    {
        return $this->media->storeOptimizedImage(
            $file,
            $subfolder,
            'prod_',
            1200,
            82,
        );
    }

    private function syncVariants(Product $product, array $existingSizeStock = []): void
    {
        // Conservar precios por color incluso al cambiar tallas/colores desde clientes antiguos.
        $savedColorPrices = $product->colors()->get()->mapWithKeys(fn ($row) => [mb_strtolower($row->color) => $row->price])->all();
        $product->sizes()->delete();
        $product->colors()->delete();

        $sizes = collect(preg_split('/[,|]+/', (string) $product->size))
            ->map(fn ($size) => trim($size))
            ->filter(fn ($size) => $size !== '' && strlen($size) <= 10)
            ->unique();

        $masterStock = max(0, (int) $product->stock);
        // No repartir inventario entre varias tallas sin conteo real.
        // Una talla única puede usar el total; varias tallas requieren
        // cantidades explícitas por talla o variante.
        $singleSizeStock = $sizes->count() === 1 ? $masterStock : 0;

        foreach ($sizes->values() as $size) {
            ProductSize::create([
                'product_id' => $product->id,
                'size' => $size,
                // Reutilizar conteos existentes; nunca inventar unidades para
                // nuevas tallas que aún no tienen desglose declarado.
                'stock' => array_key_exists($size, $existingSizeStock)
                    ? max(0, (int) $existingSizeStock[$size])
                    : $singleSizeStock,
            ]);
        }

        $hexByColor = [
            'blanco' => '#ffffff', 'negro' => '#111111', 'rojo' => '#d62828',
            'azul' => '#2563eb', 'verde' => '#2e8b57', 'amarillo' => '#facc15',
            'rosa' => '#f472b6', 'morado' => '#7c3aed', 'gris' => '#9ca3af',
            'cafe' => '#8b5e3c', 'marron' => '#8b5e3c', 'beige' => '#d6c2a1',
            'celeste' => '#7dd3fc', 'azul cielo' => '#7dd3fc', 'turquesa' => '#2dd4bf',
            'naranja' => '#f97316', 'vino' => '#7f1d1d', 'dorado' => '#d4a017',
            'plateado' => '#c0c0c0', 'arena' => '#d6b98c', 'café' => '#6f4e37',
            'cafe' => '#6f4e37', 'crema' => '#fffdd0', 'marron oscuro' => '#4a2c2a',
            'marrón oscuro' => '#4a2c2a',
        ];
        $colors = collect(preg_split('/[,\/|]+/', (string) $product->color))
            ->map(fn ($color) => trim($color))
            ->filter()
            ->unique();

        foreach ($colors as $color) {
            $normalized = mb_strtolower($color);
            ProductColor::create([
                'product_id' => $product->id,
                'color' => $color,
                'hex' => $hexByColor[$normalized] ?? (str_starts_with($color, '#') ? $color : '#888888'),
                'price' => $savedColorPrices[$normalized] ?? null,
            ]);
        }
    }

    /**
     * Overrides por color. El objeto JSON omite los colores que heredan
     * el precio de products.price. No aceptamos valores negativos o no numéricos.
     */
    private function normalizeColorPrices(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $data = json_decode($value, true);
        if (!is_array($data) || (json_last_error() !== JSON_ERROR_NONE) || ($data !== [] && array_is_list($data))) {
            throw ValidationException::withMessages(['color_prices' => 'Los precios de colores deben ser un objeto JSON.']);
        }
        $result = [];
        foreach ($data as $color => $price) {
            $color = trim((string) $color);
            if ($color === '' || mb_strlen($color) > 50
                || !is_numeric($price) || !is_finite((float) $price)
                || (float) $price <= 0 || (float) $price > 99999999.99) {
                throw ValidationException::withMessages(['color_prices' => 'Cada precio personalizado debe ser mayor que 0 y tener un color válido.']);
            }
            $key = mb_strtolower($color);
            if (array_key_exists($key, $result)) {
                throw ValidationException::withMessages(['color_prices' => 'No repitas colores en los precios personalizados.']);
            }
            $result[$key] = round((float) $price, 2);
        }
        return $result;
    }

    private function syncColorPrices(Product $product, array $overrides): void
    {
        foreach ($product->colors()->get() as $color) {
            $key = mb_strtolower($color->color);
            $color->price = $overrides[$key] ?? null;
            $color->save();
        }
    }

    private function normalizeSizeStocks(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        $seen = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $size = trim((string) ($entry['size'] ?? ''));
            $stock = $entry['stock'] ?? null;
            $numericStock = is_numeric($stock) ? (float) $stock : -1;

            if (
                $size === '' ||
                mb_strlen($size) > 10 ||
                $numericStock < 0 ||
                floor($numericStock) !== $numericStock
            ) {
                continue;
            }

            $key = mb_strtolower($size);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = [
                'size' => $size,
                'stock' => (int) $numericStock,
            ];
        }

        return $result;
    }

    private function syncSizeStocks(Product $product, array $sizeStocks): void
    {
        if (!$sizeStocks) {
            return;
        }

        $sizes = array_column($sizeStocks, 'size');
        $totalStock = array_sum(array_column($sizeStocks, 'stock'));

        $product->sizes()->whereNotIn('size', $sizes)->delete();

        foreach ($sizeStocks as $entry) {
            ProductSize::updateOrCreate(
                ['product_id' => $product->id, 'size' => $entry['size']],
                ['stock' => $entry['stock']],
            );
        }

        $product->forceFill(['stock' => $totalStock])->saveQuietly();
    }

    private function normalizeVariantStocks(?string $value, ?int $productId = null): array
    {
        if (!$value) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        $seen = [];
        $skuSeen = [];
        $modelsByColor = [];

        foreach ($decoded as $variant) {
            if (!is_array($variant)) {
                throw ValidationException::withMessages([
                    'variant_stocks' => 'Cada variante debe contener color, talla y una cantidad válida.',
                ]);
            }

            $color = trim((string) ($variant['color'] ?? ''));
            $size = trim((string) ($variant['size'] ?? ''));
            $stock = filter_var($variant['stock'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            $articleModel = trim((string) ($variant['article_model'] ?? ''));
            $sku = trim((string) ($variant['sku'] ?? ''));

            if (mb_strlen($articleModel) > 120 || mb_strlen($sku) > 120) {
                throw ValidationException::withMessages(['variant_stocks' => 'El modelo y el SKU no pueden superar 120 caracteres.']);
            }

            if ($color === '' || $size === '' || mb_strlen($color) > 50
                || mb_strlen($size) > 10 || $stock === false) {
                throw ValidationException::withMessages([
                    'variant_stocks' => 'La variante requiere color (máx. 50), talla (máx. 10) y stock entero no negativo.',
                ]);
            }

            $key = mb_strtolower($color) . '|' . mb_strtolower($size);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'variant_stocks' => 'No se puede repetir la misma combinación de color y talla.',
                ]);
            }

            $colorKey = mb_strtolower($color);
            if (isset($modelsByColor[$colorKey]) && $modelsByColor[$colorKey] !== $articleModel) {
                throw ValidationException::withMessages(['variant_stocks' => 'Un mismo color debe conservar un único modelo de artículo.']);
            }
            $modelsByColor[$colorKey] = $articleModel;
            if ($sku !== '') {
                $skuKey = mb_strtolower($sku);
                if (isset($skuSeen[$skuKey])) {
                    throw ValidationException::withMessages(['variant_stocks' => 'El SKU debe ser único por combinación de color y talla.']);
                }
                $skuSeen[$skuKey] = true;
                $other = ProductVariantStock::query()->where('sku', $sku);
                if ($productId !== null) {
                    $other->where('product_id', '!=', $productId);
                }
                if ($other->exists()) {
                    throw ValidationException::withMessages(['variant_stocks' => 'El SKU ya está asignado a otro artículo.']);
                }
            }
            $seen[$key] = true;
            $result[] = [
                'article_model' => $articleModel !== '' ? $articleModel : null,
                'sku' => $sku !== '' ? $sku : null,
                // Clientes antiguos no incluyen estos campos: conservar valores existentes.
                'article_model_provided' => array_key_exists('article_model', $variant),
                'sku_provided' => array_key_exists('sku', $variant),
                'color' => $color,
                'size' => $size,
                'stock' => $stock,
            ];
        }

        return $result;
    }

    private function syncVariantStocks(Product $product, array $variants): void
    {
        $existing = $product->variantStocks()->get()
            ->keyBy(fn ($row) => mb_strtolower($row->color) . '|' . mb_strtolower($row->size));
        $incoming = [];
        $sizeTotals = [];
        $totalStock = 0;

        foreach ($variants as $variant) {
            $key = mb_strtolower($variant['color']) . '|' . mb_strtolower($variant['size']);
            $incoming[$key] = true;
            $sizeTotals[$variant['size']] = ($sizeTotals[$variant['size']] ?? 0) + $variant['stock'];
            $totalStock += $variant['stock'];
        }

        // Eliminar únicamente las tallas/colores retirados. Mantener IDs de
        // variantes existentes para no destruir referencias ni trazabilidad.
        foreach ($existing as $key => $row) {
            if (!isset($incoming[$key])) {
                $row->delete();
            }
        }

        // Producto simple o registro manual heredado sin desglose: mantener
        // las existencias por talla gestionadas por syncVariants/syncSizeStocks.
        if (!$variants) {
            return;
        }

        // Permitir el intercambio controlado de dos SKU dentro del mismo producto.
        // Se ejecuta dentro de la transacción del controlador.
        foreach ($variants as $variant) {
            $key = mb_strtolower($variant['color']) . '|' . mb_strtolower($variant['size']);
            $row = $existing->get($key);
            if ($row && ($variant['sku_provided'] ?? true)
                && $row->sku !== ($variant['sku'] ?? null)) {
                $row->forceFill(['sku' => null])->save();
            }
        }

        foreach ($variants as $variant) {
            $key = mb_strtolower($variant['color']) . '|' . mb_strtolower($variant['size']);
            $row = $existing->get($key);
            $fields = [
                'color' => $variant['color'],
                'size' => $variant['size'],
                'stock' => $variant['stock'],
            ];
            if (!$row || ($variant['article_model_provided'] ?? true)) {
                $fields['article_model'] = $variant['article_model'] ?? null;
            }
            if (!$row || ($variant['sku_provided'] ?? true)) {
                $fields['sku'] = $variant['sku'] ?? null;
            }
            if ($row) {
                // Eloquent solo actualiza si realmente cambiaron los atributos.
                $row->fill($fields);
                if ($row->isDirty()) {
                    $row->save();
                }
            } else {
                ProductVariantStock::create(['product_id' => $product->id] + $fields);
            }
        }

        $product->sizes()->whereNotIn('size', array_keys($sizeTotals))->delete();
        foreach ($sizeTotals as $size => $stock) {
            ProductSize::updateOrCreate(
                ['product_id' => $product->id, 'size' => $size],
                ['stock' => $stock],
            );
        }

        // Sin variantes el producto simple conserva su stock declarado; con
        // variantes prevalece siempre la suma verificable de tallas.
        if ($variants) {
            $product->forceFill(['stock' => $totalStock])->saveQuietly();
        }
    }

    private function processColorImages(Request $request, array $colors, ?array $existing = null): ?array
    {
        $uploaded = $request->file('color_images', []);
        $existing = $existing ?? [];
        $result = [];

        foreach ($colors as $index => $color) {
            $files = $uploaded[$index] ?? [];
            $files = is_array($files) ? array_slice($files, 0, 5) : [];
            if (!$files && !empty($existing[$color])) {
                $result[$color] = array_slice($existing[$color], 0, 5);
                continue;
            }

            if ($files) {
                $result[$color] = array_map(fn ($file) => $this->optimizeImage($file), $files);
            }
        }

        return $result ?: null;
    }

    private function normalizeColorSizes(?string $value): ?array
    {
        if (!$value) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $color => $sizes) {
            if (!is_string($color) || !is_array($sizes)) {
                continue;
            }

            $normalizedSizes = collect($sizes)
                ->map(fn ($size) => trim((string) $size))
                ->filter(fn ($size) => $size !== '' && strlen($size) <= 10)
                ->unique()
                ->values()
                ->all();

            if ($normalizedSizes) {
                $result[trim($color)] = $normalizedSizes;
            }
        }

        return $result ?: null;
    }


    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'stock_state' => ['nullable', 'in:available,low,out'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Product::with(
            'brand:id,name',
            'category:id,name,slug',
            'subcategory:id,name',
            'sizes',
            'colors',
            'variantStocks',
        )->where('is_active', true)
            ->where('price', '>', 0)
            ->whereHas('brand', fn ($brand) => $brand->where('is_active', true))
            ->whereHas('category', fn ($category) => $category->where('is_active', true));

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('product_code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('model', 'like', '%' . $search . '%')
                    ->orWhereHas('variantStocks', fn ($variant) => $variant
                        ->where('article_model', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%'))
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', '%' . $search . '%'));
            });
        }

        if (!empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (!empty($validated['brand_id'])) {
            $query->where('brand_id', $validated['brand_id']);
        }

        if (($validated['stock_state'] ?? null) === 'available') {
            $query->where('stock', '>', 5);
        } elseif (($validated['stock_state'] ?? null) === 'low') {
            $query->whereBetween('stock', [1, 5]);
        } elseif (($validated['stock_state'] ?? null) === 'out') {
            $query->where('stock', '<=', 0);
        }

        return response()->json(
            $query->orderByDesc('updated_at')->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 20))
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'stock_state' => ['nullable', 'in:available,low,out'],
            'active_state' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Product::with(
            'brand:id,name',
            'category:id,name,slug',
            'subcategory:id,name',
            'sizes',
            'colors',
            'variantStocks',
        );

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('product_code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('model', 'like', '%' . $search . '%')
                    ->orWhereHas('variantStocks', fn ($variant) => $variant
                        ->where('article_model', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%'))
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', '%' . $search . '%'));
            });
        }

        if (!empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (!empty($validated['brand_id'])) {
            $query->where('brand_id', $validated['brand_id']);
        }

        if (($validated['stock_state'] ?? null) === 'available') {
            $query->where('stock', '>', 5);
        } elseif (($validated['stock_state'] ?? null) === 'low') {
            $query->whereBetween('stock', [1, 5]);
        } elseif (($validated['stock_state'] ?? null) === 'out') {
            $query->where('stock', '<=', 0);
        }

        if (($validated['active_state'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($validated['active_state'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        return response()->json(
            $query->orderByDesc('updated_at')->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 200))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_code' => 'nullable|string|max:100|unique:products,product_code',
            'name' => 'required|string|max:255',
            'model' => 'nullable|string|max:120',
            'product_type' => 'nullable|in:simple,variable',
            'gender' => 'nullable|in:mujer,hombre,ninos,unisex',
            'barcode' => 'nullable|string|max:120|unique:products,barcode',
            'collection_name' => 'nullable|string|max:120',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',

            'brand_id' => 'nullable|integer|exists:brands,id',
            'category_id' => 'required|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
            'price' => 'required|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'discounted_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'material' => 'nullable|string|max:100',
            'img' => 'nullable',
            'img_file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            'new_images.*' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            'description' => 'nullable|string|max:5000',
            'discount' => 'nullable|numeric|min:0|max:100',
            'status' => 'required|in:normal,oferta,nuevo',
            'color_sizes' => 'nullable|json',
            'variant_stocks' => 'nullable|json',
            'color_prices' => 'nullable|json',
            'size_stocks' => 'nullable|json',
        ]);

        $variantStocks = $this->normalizeVariantStocks($request->input('variant_stocks'));
        $colorPrices = $this->normalizeColorPrices($request->input('color_prices'));
        $sizeStocks = $this->normalizeSizeStocks($request->input('size_stocks'));

        // Alta manual: conservar el total informado, pero sin adjudicarlo a
        // tallas específicas hasta recibir un desglose verificable.
        $declaredSizes = array_values(array_filter(array_map(
            'trim', preg_split('/[,|]+/', (string) ($validated['size'] ?? '')) ?: []
        )));
        if (count($declaredSizes) > 1 && !$variantStocks && !$sizeStocks
            && (int) $validated['stock'] > 0
        ) {
            $validated['import_pending_data'] = [
                'source' => 'manual',
                'unallocated_stock' => (int) $validated['stock'],
            ];
        }

        if (($validated['product_type'] ?? '') === 'simple'
            && ($variantStocks || trim((string) ($validated['size'] ?? '')) !== ''
                || trim((string) ($validated['color'] ?? '')) !== '')
        ) {
            throw ValidationException::withMessages([
                'product_type' => 'Un producto simple no admite variantes ni tallas/colores.',
            ]);
        }

        if ($variantStocks) {
            $validated['stock'] = array_sum(array_column($variantStocks, 'stock'));
        } elseif ($sizeStocks) {
            $validated['stock'] = array_sum(array_column($sizeStocks, 'stock'));
        }

        $discount = (float) ($validated['discount'] ?? 0);
        $validated['discounted_price'] = $discount > 0 && (float) $validated['price'] > 0
            ? round((float) $validated['price'] * (1 - min($discount, 100) / 100), 2)
            : null;
        $validated['discount'] = $discount > 0 ? (string) $discount : null;

        $colors = collect(preg_split('/[,\/|]+/', (string) ($validated['color'] ?? '')))
            ->map(fn ($color) => trim($color))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $validated['color_images'] = $this->processColorImages($request, $colors);
        $validated['color_sizes'] = $this->normalizeColorSizes($request->input('color_sizes'));

        $imageUrls = [];
        if ($request->hasFile('new_images')) {
            $files = $request->file('new_images');
            foreach (array_slice($files, 0, 5) as $file) {
                $imageUrls[] = $this->optimizeImage($file);
            }
            $validated['images'] = $imageUrls;
        }

        if ($request->hasFile('img_file')) {
            $validated['img'] = $this->optimizeImage($request->file('img_file'));
        } elseif (count($imageUrls) > 0) {
            $validated['img'] = $imageUrls[0];
        }

        try {
            $fields = ['name', 'model', 'size', 'color', 'material', 'description', 'discount'];
            foreach ($fields as $field) {
                if (isset($validated[$field])) {
                    $validated[$field] = htmlspecialchars($validated[$field], ENT_QUOTES, 'UTF-8');
                }
            }

            $requestDraft = $request->has('is_active') && !$request->boolean('is_active');
            DB::transaction(function () use (&$product, $validated, $variantStocks, $sizeStocks, $requestDraft, $colorPrices) {
                // Un alta manual sin precio o fotografía comienza en borrador.
                // El alta admite borrador explícito, aun con precio y stock.
                // Los clientes históricos sin is_active conservan la publicación automática.
                $validated['auto_publish'] = !$requestDraft;
                $validated['is_active'] = false;
                $product = Product::create($validated);
                $this->syncVariants($product);
                $this->syncColorPrices($product, $colorPrices);

                if ($sizeStocks) {
                    $this->syncSizeStocks($product, $sizeStocks);
                }

                $this->syncVariantStocks($product, $variantStocks);
                app(ProductPublicationService::class)->synchronize($product->fresh());
            });
            // La sincronización de publicación opera sobre una instancia fresh.
            // Devolver también el estado persistido, no el is_active inicial.
            $product = $product->fresh();
            $product->load('sizes', 'colors', 'variantStocks');
            return response()->json($product, 201);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'No se pudo crear el producto. Intenta nuevamente o revisa los logs del servidor.',
            ], 500);
        }
    }


    public function show(Product $product): JsonResponse
    {
        if (!$product->is_active || (float) $product->price <= 0
            || !$product->brand()->where('is_active', true)->exists()
            || !$product->category()->where('is_active', true)->exists()) {
            abort(404);
        }

        $product->load('brand:id,name', 'category:id,name,slug', 'subcategory:id,name', 'sizes', 'colors', 'variantStocks');
        return response()->json($product);
    }


    public function update(Request $request, Product $product): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'product_code' => 'nullable|string|max:100|unique:products,product_code,' . $product->id,
            'product_type' => 'nullable|in:simple,variable',
            'gender' => 'nullable|in:mujer,hombre,ninos,unisex',
            'barcode' => 'nullable|string|max:120|unique:products,barcode,' . $product->id,
            'collection_name' => 'nullable|string|max:120',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'name' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:120',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
            'price' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'auto_publish' => 'sometimes|boolean',
            'discounted_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'material' => 'nullable|string|max:100',
            'img' => 'nullable',
            'img_file' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,webp|max:20480',
            'new_images.*' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,webp|max:20480',
            'description' => 'nullable|string|max:5000',
            'discount' => 'nullable|numeric|min:0|max:100',
            'status' => 'sometimes|in:normal,oferta,nuevo',
            'color_images' => 'sometimes|array',
            'color_sizes' => 'sometimes|json',
            'variant_stocks' => 'sometimes|json',
            'color_prices' => 'sometimes|json',
            'size_stocks' => 'sometimes|json',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $colorPrices = $request->has('color_prices')
            ? $this->normalizeColorPrices($request->input('color_prices')) : null;
        if ($request->has('is_active')) {
            // El administrador puede detener la publicación automática.
            $validated['auto_publish'] = $request->boolean('is_active');
            $validated['is_active'] = false;
        } elseif (array_key_exists('auto_publish', $validated) && !$validated['auto_publish']) {
            $validated['is_active'] = false;
        }
        $variantStocks = $request->has('variant_stocks')
            ? $this->normalizeVariantStocks($request->input('variant_stocks'), $product->id)
            : null;
        $sizeStocks = $request->has('size_stocks')
            ? $this->normalizeSizeStocks($request->input('size_stocks'))
            : null;
        $resetVariantStocks = $request->has('size_stocks') && !empty($sizeStocks) && !$request->has('variant_stocks');

        if (($validated['product_type'] ?? $product->product_type) === 'simple'
            && ((is_array($variantStocks) && $variantStocks)
                || trim((string) (array_key_exists('size', $validated)
                    ? $validated['size'] : $product->size)) !== ''
                || trim((string) (array_key_exists('color', $validated)
                    ? $validated['color'] : $product->color)) !== '')
        ) {
            return response()->json(['errors' => [
                'product_type' => ['Un producto simple no admite variantes ni tallas/colores.'],
            ]], 422);
        }

        if (is_array($variantStocks) && $request->has('variant_stocks')
            && (($validated['product_type'] ?? $product->product_type) !== 'simple')) {
            $validated['stock'] = array_sum(array_column($variantStocks, 'stock'));
        } elseif (is_array($sizeStocks) && !empty($sizeStocks) && $request->has('size_stocks')) {
            $validated['stock'] = array_sum(array_column($sizeStocks, 'stock'));
        }

        $price = array_key_exists('price', $validated) ? (float) $validated['price'] : (float) $product->price;
        $discount = array_key_exists('discount', $validated) ? (float) $validated['discount'] : (float) $product->discount;
        $validated['discounted_price'] = $discount > 0 && $price > 0
            ? round($price * (1 - min($discount, 100) / 100), 2)
            : null;
        $validated['discount'] = $discount > 0 ? (string) $discount : null;

        $colors = collect(preg_split('/[,\/|]+/', (string) (array_key_exists('color', $validated)
            ? $validated['color'] : $product->color)))
            ->map(fn ($color) => trim($color))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $validated['color_images'] = $this->processColorImages($request, $colors, $product->color_images);
        $validated['color_sizes'] = $this->normalizeColorSizes($request->input('color_sizes')) ?? $product->color_sizes;

        $imageSlots = $request->input('image_slots');
        if (is_string($imageSlots)) {
            $imageSlots = json_decode($imageSlots, true);
        }

        $finalImages = [];
        $newFiles = $request->file('new_images') ?? [];
        $newFiles = array_values($newFiles);
        $fileIndex = 0;

        if (is_array($imageSlots) && count($imageSlots) > 0) {
            foreach ($imageSlots as $i => $slot) {
                if ($slot === '__NEW__') {
                    if (isset($newFiles[$fileIndex])) {
                        $url = $this->optimizeImage($newFiles[$fileIndex]);
                        $finalImages[] = $url;
                        $fileIndex++;
                    }
                } elseif ($slot && $slot !== '__EMPTY__' && $slot !== 'null' && is_string($slot)) {
                    $finalImages[] = $slot;
                }
            }
        } else {
            $currentImages = is_array($product->images) ? $product->images : [];
            $finalImages = $currentImages;
            if (count($newFiles) > 0) {
                foreach ($newFiles as $file) {
                    if (count($finalImages) >= 5)
                        break;
                    $finalImages[] = $this->optimizeImage($file);
                }
            }
        }

        $validated['images'] = $finalImages;

        if (count($finalImages) > 0) {
            $validated['img'] = $finalImages[0];
        } else {
            $validated['img'] = null;
        }

        unset($validated['img_file']);

        try {
            $previousImages = is_array($product->images) ? $product->images : [];
            if ($product->img) {
                $previousImages[] = $product->img;
            }
            $previousColorImages = $this->flattenImageValues($product->color_images);
            $keptImages = array_merge($finalImages, $this->flattenImageValues($validated['color_images'] ?? null));

            $fields = ['name', 'size', 'color', 'material', 'description', 'discount'];
            foreach ($fields as $field) {
                if (isset($validated[$field])) {
                    $validated[$field] = htmlspecialchars($validated[$field], ENT_QUOTES, 'UTF-8');
                }
            }

            $existingSizeStock = $product->sizes()
                ->pluck('stock', 'size')
                ->mapWithKeys(fn ($stock, $size) => [trim((string) $size) => (int) $stock])
                ->all();

            $originalSizes = collect(preg_split('/[,|]+/', (string) $product->size))
                ->map(fn ($size) => trim($size))
                ->filter()
                ->values()
                ->all();
            $originalColors = collect(preg_split('/[,\/|]+/', (string) $product->color))
                ->map(fn ($color) => trim($color))
                ->filter()
                ->values()
                ->all();

            DB::transaction(function () use ($product, $validated, $existingSizeStock, $originalSizes, $originalColors, $variantStocks, $sizeStocks, $resetVariantStocks, $colorPrices) {
                $product->update($validated);
                $freshProduct = $product->fresh();

                $updatedSizes = collect(preg_split('/[,|]+/', (string) $freshProduct->size))
                    ->map(fn ($size) => trim($size))
                    ->filter()
                    ->values()
                    ->all();
                $updatedColors = collect(preg_split('/[,\/|]+/', (string) $freshProduct->color))
                    ->map(fn ($color) => trim($color))
                    ->filter()
                    ->values()
                    ->all();

                sort($originalSizes);
                sort($updatedSizes);
                sort($originalColors);
                sort($updatedColors);

                // Editar precio, imágenes, descripción u otros datos no debe
                // reconstruir tallas ni reponer stock. Solo sincronizamos cuando
                // cambió realmente la estructura de tallas/colores.
                if ($originalSizes !== $updatedSizes || $originalColors !== $updatedColors) {
                    $this->syncVariants($freshProduct, $existingSizeStock);
                }
                if (is_array($colorPrices)) {
                    $this->syncColorPrices($freshProduct, $colorPrices);
                }

                if (is_array($sizeStocks) && !empty($sizeStocks)) {
                    $this->syncSizeStocks($freshProduct, $sizeStocks);
                }

                if ($resetVariantStocks) {
                    $freshProduct->variantStocks()->delete();
                }

                if (is_array($variantStocks)) {
                    $this->syncVariantStocks($freshProduct, $variantStocks);
                }
                app(ProductPublicationService::class)->synchronize($freshProduct->fresh());
            });
            $this->deleteUploadImages(array_merge($previousImages, $previousColorImages), $keptImages);
            $product->refresh();
            $product->load('sizes', 'colors', 'variantStocks');
            return response()->json($product);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'No se pudo actualizar el producto. Intenta nuevamente o revisa los logs del servidor.',
            ], 500);
        }
    }

    /**
     * Registra un único precio MXN para un modelo en distintos colores.
     * Únicamente rellena precios en cero: nunca reemplaza precios confirmados.
     */
    public function assignModelPrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'model' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'gt:0'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $products = Product::query()
                ->where('brand_id', $validated['brand_id'])
                ->where('category_id', $validated['category_id'])
                ->where('model', trim($validated['model']))
                ->where('auto_publish', true)
                ->where('price', '<=', 0)
                ->lockForUpdate()->get();
            $published = 0;
            $drafts = 0;
            foreach ($products as $product) {
                $product->price = (float) $validated['price'];
                $product->discounted_price = null;
                $product->discount = null;
                $product->save();
                app(ProductPublicationService::class)->synchronize($product);
                if ($product->fresh()->is_active) {
                    $published++;
                } else {
                    $drafts++;
                }
            }
            return [
                'updated' => $products->count(),
                'published' => $published,
                'drafts' => $drafts,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Precio aplicado al modelo; los productos incompletos siguen en borrador.',
            'summary' => $result,
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        try {
            return DB::transaction(function () use ($product): JsonResponse {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);

                // Nunca eliminar el historial comercial o los movimientos por cascada.
                foreach (['order_items', 'inventory_movements'] as $table) {
                    if (Schema::hasTable($table) && DB::table($table)->where('product_id', $product->id)->exists()) {
                        return $this->productDeletionConflict();
                    }
                }

                $images = $this->flattenImageValues($product->images);
                if ($product->img) {
                    $images[] = $product->img;
                }
                $images = array_merge($images, $this->flattenImageValues($product->color_images));
                $productId = $product->id;
                $product->delete();

                // El filesystem no participa del rollback: limpiar solo tras confirmar la BD.
                DB::afterCommit(function () use ($images, $productId): void {
                    try {
                        $this->deleteUploadImages($images);
                    } catch (\Throwable $e) {
                        Log::warning('PRODUCT_IMAGE_CLEANUP_FAILED', [
                            'product_id' => $productId,
                            'exception' => get_class($e),
                        ]);
                    }
                });

                return response()->json(null, 204);
            });
        } catch (QueryException $e) {
            // Incluye relaciones históricas que no aparecen en el esquema actual.
            $state = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($state === '23503' || ($state === '23000' && in_array($driverCode, [1451, 19], true))) {
                return $this->productDeletionConflict();
            }
            throw $e;
        }
    }

    private function productDeletionConflict(): JsonResponse
    {
        return response()->json([
            'message' => 'Este producto tiene registros relacionados y no se puede eliminar. Desactívalo para conservar el historial.',
            'error_code' => 'PRODUCT_HAS_RELATED_RECORDS',
        ], 409);
    }
}
