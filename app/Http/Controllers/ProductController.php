<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{

    private function deleteUploadImages(array $images, array $keptImages = []): void
    {
        $keptPaths = array_map(fn ($image) => $this->uploadPath($image), $keptImages);
        foreach (array_unique($images) as $image) {
            $path = $this->uploadPath($image);
            if ($path && !in_array($path, $keptPaths, true) && is_file($path)) {
                @unlink($path);
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

    private function uploadPath(?string $image): ?string
    {
        if (!$image) {
            return null;
        }

        $path = parse_url($image, PHP_URL_PATH) ?: $image;
        $path = '/' . ltrim($path, '/');

        $relativePath = null;
        if (str_starts_with($path, '/api/media/')) {
            $relativePath = ltrim(substr($path, strlen('/api/media/')), '/');
        } elseif (str_starts_with($path, '/storage/')) {
            $relativePath = ltrim(substr($path, strlen('/storage/')), '/');
        } elseif (str_starts_with($path, '/uploads/')) {
            $relativePath = ltrim($path, '/');
        }

        if ($relativePath) {
            $storageRoot = realpath(storage_path('app/public'));
            $storageFile = realpath(storage_path('app/public/' . $relativePath));
            if ($storageRoot && $storageFile && str_starts_with($storageFile, $storageRoot . DIRECTORY_SEPARATOR)) {
                return $storageFile;
            }
        }

        // Compatibilidad con imágenes antiguas guardadas en public/uploads.
        if (str_starts_with($path, '/uploads/')) {
            $legacyRoot = realpath(public_path('uploads'));
            $legacyFile = realpath(public_path(ltrim($path, '/')));
            if ($legacyRoot && $legacyFile && str_starts_with($legacyFile, $legacyRoot . DIRECTORY_SEPARATOR)) {
                return $legacyFile;
            }
        }

        return null;
    }

    private function optimizeImage($file, $subfolder = 'products')
    {
        $mime = $file->getMimeType();
        $sourcePath = $file->getPathname();
        $filename = uniqid('prod_', true) . '.webp';

        // Persistir fuera de public/ evita perder imágenes cuando se actualiza el código en cPanel.
        $uploadPath = storage_path('app/public/uploads/' . $subfolder);

        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $image = null;
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $image = imagecreatefromjpeg($sourcePath);
        } elseif ($mime === 'image/png') {
            $image = imagecreatefrompng($sourcePath);
        } elseif ($mime === 'image/webp') {
            $image = imagecreatefromwebp($sourcePath);
        }

        if (!$image) {
            $path = $file->store($subfolder, 'uploads');
            return '/api/media/uploads/' . ltrim($path, '/');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 1200;

        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) round(($height / $width) * $newWidth);
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $newImage;
        }

        $finalPath = $uploadPath . '/' . $filename;
        if (function_exists('imagewebp')) {
            imagewebp($image, $finalPath, 82);
        } else {
            $filename = str_replace('.webp', '.jpg', $filename);
            $finalPath = $uploadPath . '/' . $filename;
            imagejpeg($image, $finalPath, 82);
        }

        imagedestroy($image);
        return '/api/media/uploads/' . $subfolder . '/' . $filename;
    }

    private function syncVariants(Product $product): void
    {
        $product->sizes()->delete();
        $product->colors()->delete();

        $sizes = collect(preg_split('/[,|]+/', (string) $product->size))
            ->map(fn ($size) => trim($size))
            ->filter(fn ($size) => $size !== '' && strlen($size) <= 10)
            ->unique();

        foreach ($sizes as $size) {
            ProductSize::create([
                'product_id' => $product->id,
                'size' => $size,
                'stock' => (int) $product->stock,
            ]);
        }

        $hexByColor = [
            'blanco' => '#ffffff', 'negro' => '#111111', 'rojo' => '#d62828',
            'azul' => '#2563eb', 'verde' => '#2e8b57', 'amarillo' => '#facc15',
            'rosa' => '#f472b6', 'morado' => '#7c3aed', 'gris' => '#9ca3af',
            'cafe' => '#8b5e3c', 'marron' => '#8b5e3c', 'beige' => '#d6c2a1',
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


    public function index(): JsonResponse
    {
        return response()->json(
            Product::with('brand:id,name', 'category:id,name,slug', 'subcategory:id,name', 'sizes', 'colors')
                ->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_code' => 'nullable|string|max:100',
            'name' => 'required|string|max:255',
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
        ]);

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
            $fields = ['name', 'size', 'color', 'material', 'description', 'discount'];
            foreach ($fields as $field) {
                if (isset($validated[$field])) {
                    $validated[$field] = htmlspecialchars($validated[$field], ENT_QUOTES, 'UTF-8');
                }
            }

            DB::transaction(function () use (&$product, $validated) {
                $product = Product::create($validated);
                $this->syncVariants($product);
            });
            $product->load('sizes', 'colors');
            return response()->json($product, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al crear el producto: ' . $e->getMessage()], 500);
        }
    }


    public function show(Product $product): JsonResponse
    {
        $product->load('brand:id,name', 'category:id,name,slug', 'subcategory:id,name', 'sizes', 'colors');
        return response()->json($product);
    }


    public function update(Request $request, Product $product): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'product_code' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
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
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

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

            DB::transaction(function () use ($product, $validated) {
                $product->update($validated);
                $this->syncVariants($product->fresh());
            });
            $this->deleteUploadImages(array_merge($previousImages, $previousColorImages), $keptImages);
            $product->refresh();
            $product->load('sizes', 'colors');
            return response()->json($product);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar el producto: ' . $e->getMessage()], 500);
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