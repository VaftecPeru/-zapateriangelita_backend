<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    // ============================================
    // OPTIMIZAR IMAGEN
    // ============================================
    private function optimizeImage($file, $subfolder = 'products')
    {
        $mime = $file->getMimeType();
        $sourcePath = $file->getPathname();
        $filename = uniqid('prod_') . '.webp';
        $uploadPath = public_path('uploads/' . $subfolder);

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
            return asset('uploads/' . $path);
        }

        // Redimensionar si es muy grande (máx 1200px)
        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 1200;

        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = ($height / $width) * $newWidth;
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $newImage;
        }

        // Guardar como WebP o JPG
        $finalPath = $uploadPath . '/' . $filename;
        if (function_exists('imagewebp')) {
            imagewebp($image, $finalPath, 80);
        } else {
            $filename = str_replace('.webp', '.jpg', $filename);
            $finalPath = $uploadPath . '/' . $filename;
            imagejpeg($image, $finalPath, 80);
        }

        imagedestroy($image);
        return asset('uploads/' . $subfolder . '/' . $filename);
    }

    // ============================================
    // LISTAR PRODUCTOS
    // ============================================
    public function index(): JsonResponse
    {
        return response()->json(Product::paginate(20));
    }

    // ============================================
    // CREAR PRODUCTO
    // ============================================
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'brand' => 'required|string|max:100',
            'price' => 'required|numeric|min:0.01',
            'discounted_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'material' => 'nullable|string|max:100',
            'gender' => 'nullable|string|max:50',
            'rating' => 'required|numeric|min:0|max:5',
            'reviews' => 'required|integer|min:0',
            'img' => 'nullable',
            'img_file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            'new_images.*' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            'description' => 'nullable|string|max:5000',
            'discount' => 'nullable|string|max:50',
        ]);

        // Limpiar discounted_price si está vacío
        if (empty($validated['discounted_price'])) {
            $validated['discounted_price'] = null;
        }

        // Limpiar discount si está vacío
        if (empty($validated['discount'])) {
            $validated['discount'] = null;
        }

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
            // Sanitizar strings HTML
            $fields = ['name', 'category', 'brand', 'size', 'color', 'material', 'gender', 'description', 'discount'];
            foreach ($fields as $field) {
                if (isset($validated[$field])) {
                    $validated[$field] = htmlspecialchars($validated[$field], ENT_QUOTES, 'UTF-8');
                }
            }

            $product = Product::create($validated);
            return response()->json($product, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al crear el producto: ' . $e->getMessage()], 500);
        }
    }

    // ============================================
    // MOSTRAR PRODUCTO
    // ============================================
    public function show(Product $product): JsonResponse
    {
        return response()->json($product);
    }

    // ============================================
    // ACTUALIZAR PRODUCTO
    // ============================================
    public function update(Request $request, Product $product): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'brand' => 'nullable|string|max:100',
            'price' => 'nullable|numeric|min:0.01',
            'discounted_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'material' => 'nullable|string|max:100',
            'gender' => 'nullable|string|max:50',
            'rating' => 'nullable|numeric|min:0|max:5',
            'reviews' => 'nullable|integer|min:0',
            'img' => 'nullable',
            'img_file' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,webp|max:20480',
            'new_images.*' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg,webp|max:20480',
            'description' => 'nullable|string|max:5000',
            'discount' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Limpiar valores vacíos
        if (!isset($validated['discounted_price']) || $validated['discounted_price'] === null || $validated['discounted_price'] == 0) {
            $validated['discounted_price'] = null;
        }
        if (!isset($validated['discount']) || empty($validated['discount'])) {
            $validated['discount'] = null;
        }

        // Sincronización de Galería
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

        // Portada (primera imagen)
        if (count($finalImages) > 0) {
            $validated['img'] = $finalImages[0];
        } else {
            $validated['img'] = null;
        }

        unset($validated['img_file']);

        try {
            // Sanitizar strings HTML
            $fields = ['name', 'category', 'brand', 'size', 'color', 'material', 'gender', 'description', 'discount'];
            foreach ($fields as $field) {
                if (isset($validated[$field])) {
                    $validated[$field] = htmlspecialchars($validated[$field], ENT_QUOTES, 'UTF-8');
                }
            }

            $product->update($validated);
            $product->refresh();
            return response()->json($product);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar el producto: ' . $e->getMessage()], 500);
        }
    }

    // ============================================
    // ELIMINAR PRODUCTO
    // ============================================
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();
        return response()->json(null, 204);
    }
}