<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use App\Services\MediaStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
        $product->sizes()->delete();
        $product->colors()->delete();

        $sizes = collect(preg_split('/[,|]+/', (string) $product->size))
            ->map(fn ($size) => trim($size))
            ->filter(fn ($size) => $size !== '' && strlen($size) <= 10)
            ->unique();

        $sizeCount = max(1, $sizes->count());
        $masterStock = max(0, (int) $product->stock);
        $baseStock = intdiv($masterStock, $sizeCount);
        $remainder = $masterStock % $sizeCount;

        foreach ($sizes->values() as $index => $size) {
            ProductSize::create([
                'product_id' => $product->id,
                'size' => $size,
                // Si ya existe inventario por talla, se conserva. Para productos
                // nuevos/importados sin desglose distribuimos el stock maestro
                // sin duplicar unidades entre todas las tallas.
                'stock' => array_key_exists($size, $existingSizeStock)
                    ? max(0, (int) $existingSizeStock[$size])
                    : $baseStock + ($index < $remainder ? 1 : 0),
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
            ]);
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

    private function normalizeVariantStocks(?string $value): array
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

        foreach ($decoded as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $color = trim((string) ($variant['color'] ?? ''));
            $size = trim((string) ($variant['size'] ?? ''));
            $stock = max(0, (int) ($variant['stock'] ?? 0));

            if ($color === '' || $size === '' || mb_strlen($color) > 50 || mb_strlen($size) > 10) {
                continue;
            }

            $key = mb_strtolower($color) . '|' . mb_strtolower($size);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = [
                'color' => $color,
                'size' => $size,
                'stock' => $stock,
            ];
        }

        return $result;
    }

    private function syncVariantStocks(Product $product, array $variants): void
    {
        $product->variantStocks()->delete();

        if (!$variants) {
            return;
        }

        $sizeTotals = [];
        $totalStock = 0;

        foreach ($variants as $variant) {
            ProductVariantStock::create([
                'product_id' => $product->id,
                'color' => $variant['color'],
                'size' => $variant['size'],
                'stock' => $variant['stock'],
            ]);

            $sizeTotals[$variant['size']] = ($sizeTotals[$variant['size']] ?? 0) + $variant['stock'];
            $totalStock += $variant['stock'];
        }

        $product->sizes()->whereNotIn('size', array_keys($sizeTotals))->delete();

        foreach ($sizeTotals as $size => $stock) {
            ProductSize::updateOrCreate(
                ['product_id' => $product->id, 'size' => $size],
                ['stock' => $stock],
            );
        }

        $product->forceFill(['stock' => $totalStock])->saveQuietly();
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
        )->where('is_active', true);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('product_code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('model', 'like', '%' . $search . '%')
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
            $query->orderByDesc('updated_at')->paginate((int) ($validated['per_page'] ?? 20))
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
            $query->orderByDesc('updated_at')->paginate((int) ($validated['per_page'] ?? 200))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_code' => 'nullable|string|max:100|unique:products,product_code',
            'name' => 'required|string|max:255',
            'model' => 'nullable|string|max:120',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'category_id' => 'required|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
            'price' => 'required|numeric|min:0.01',
            'discounted_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'material' => 'nullable|string|max:100',
            'rating' => 'required|numeric|min:0|max:5',
            'reviews' => 'required|integer|min:0',
            'img' => 'nullable',
            'img_file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            'new_images.*' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            'description' => 'nullable|string|max:5000',
            'discount' => 'nullable|numeric|min:0|max:100',
            'status' => 'required|in:normal,oferta,nuevo',
            'color_sizes' => 'nullable|json',
            'variant_stocks' => 'nullable|json',
            'size_stocks' => 'nullable|json',
        ]);

        $variantStocks = $this->normalizeVariantStocks($request->input('variant_stocks'));
        $sizeStocks = $this->normalizeSizeStocks($request->input('size_stocks'));

        if ($variantStocks) {
            $validated['stock'] = array_sum(array_column($variantStocks, 'stock'));
        } elseif ($sizeStocks) {
            $validated['stock'] = array_sum(array_column($sizeStocks, 'stock'));
        }

        $discount = (float) ($validated['discount'] ?? 0);
        $validated['discounted_price'] = $discount > 0
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

            DB::transaction(function () use (&$product, $validated, $variantStocks, $sizeStocks) {
                $product = Product::create($validated);
                $this->syncVariants($product);

                if ($sizeStocks) {
                    $this->syncSizeStocks($product, $sizeStocks);
                }

                $this->syncVariantStocks($product, $variantStocks);
            });
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
        if (!$product->is_active) {
            abort(404);
        }

        $product->load('brand:id,name', 'category:id,name,slug', 'subcategory:id,name', 'sizes', 'colors', 'variantStocks');
        return response()->json($product);
    }


    public function update(Request $request, Product $product): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'product_code' => 'nullable|string|max:100|unique:products,product_code,' . $product->id,
            'name' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:120',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
            'price' => 'nullable|numeric|min:0.01',
            'discounted_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'material' => 'nullable|string|max:100',
            'rating' => 'nullable|numeric|min:0|max:5',
            'reviews' => 'nullable|integer|min:0',
            'img' => 'nullable',
            'img_file' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,webp|max:20480',
            'new_images.*' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,webp|max:20480',
            'description' => 'nullable|string|max:5000',
            'discount' => 'nullable|numeric|min:0|max:100',
            'status' => 'sometimes|in:normal,oferta,nuevo',
            'color_images' => 'sometimes|array',
            'color_sizes' => 'sometimes|json',
            'variant_stocks' => 'sometimes|json',
            'size_stocks' => 'sometimes|json',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $variantStocks = $request->has('variant_stocks')
            ? $this->normalizeVariantStocks($request->input('variant_stocks'))
            : null;
        $sizeStocks = $request->has('size_stocks')
            ? $this->normalizeSizeStocks($request->input('size_stocks'))
            : null;
        $resetVariantStocks = $request->has('size_stocks') && !empty($sizeStocks) && !$request->has('variant_stocks');

        if (is_array($variantStocks) && $request->has('variant_stocks')) {
            $validated['stock'] = array_sum(array_column($variantStocks, 'stock'));
        } elseif (is_array($sizeStocks) && !empty($sizeStocks) && $request->has('size_stocks')) {
            $validated['stock'] = array_sum(array_column($sizeStocks, 'stock'));
        }

        $price = array_key_exists('price', $validated) ? (float) $validated['price'] : (float) $product->price;
        $discount = array_key_exists('discount', $validated) ? (float) $validated['discount'] : (float) $product->discount;
        $validated['discounted_price'] = $discount > 0
            ? round($price * (1 - min($discount, 100) / 100), 2)
            : null;
        $validated['discount'] = $discount > 0 ? (string) $discount : null;

        $colors = collect(preg_split('/[,\/|]+/', (string) ($validated['color'] ?? $product->color)))
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

            DB::transaction(function () use ($product, $validated, $existingSizeStock, $originalSizes, $originalColors, $variantStocks, $sizeStocks, $resetVariantStocks) {
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

                if (is_array($sizeStocks) && !empty($sizeStocks)) {
                    $this->syncSizeStocks($freshProduct, $sizeStocks);
                }

                if ($resetVariantStocks) {
                    $freshProduct->variantStocks()->delete();
                }

                if (is_array($variantStocks)) {
                    $this->syncVariantStocks($freshProduct, $variantStocks);
                }
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

    public function destroy(Product $product): JsonResponse
    {
        $images = is_array($product->images) ? $product->images : [];
        if ($product->img) {
            $images[] = $product->img;
        }
        $images = array_merge($images, $this->flattenImageValues($product->color_images));
        $this->deleteUploadImages($images);
        $product->delete();
        return response()->json(null, 204);
    }
}