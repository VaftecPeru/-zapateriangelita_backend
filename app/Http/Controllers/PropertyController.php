<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    private function optimizeImage($file, $subfolder = 'properties')
    {
        $mime = $file->getMimeType();
        $sourcePath = $file->getPathname();
        $filename = uniqid('prop_') . '.webp';
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
            // Fallback: Si no se puede procesar, guardar el original como JPG
            $path = $file->store($subfolder, 'uploads');
            return asset('uploads/' . $path);
        }

        // 1. Redimensionar si es muy grande (máx 1600px)
        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 1600;

        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = ($height / $width) * $newWidth;
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Mantener transparencia si es PNG originalmente (opcional pero bueno)
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $newImage;
        }

        // 2. Guardar como WebP si es posible, si no JPG optimizado
        $finalPath = $uploadPath . '/' . $filename;
        if (function_exists('imagewebp')) {
            imagewebp($image, $finalPath, 80); // 80% calidad
        } else {
            $filename = str_replace('.webp', '.jpg', $filename);
            $finalPath = $uploadPath . '/' . $filename;
            imagejpeg($image, $finalPath, 80);
        }

        imagedestroy($image);
        return asset('uploads/' . $subfolder . '/' . $filename);
    }

    public function index(): JsonResponse
    {
        return response()->json(Property::paginate(20));  // ✅ Paginación: 20 por página
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:100',
            'status' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'beds' => 'nullable|integer|min:0',
            'baths' => 'nullable|integer|min:0',
            'area' => 'nullable|string|max:50',
            'price' => 'required|numeric|min:0.01',
            'discounted_price' => 'nullable|numeric|min:0',
            'rating' => 'required|numeric|min:0|max:5',
            'reviews' => 'required|integer|min:0',
            'img' => 'nullable', 
            'img_file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480', 
            'new_images.*' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            'amenities' => 'nullable|string|max:1000',
        ]);

        // Limpiar discounted_price si está vacío
        if (empty($validated['discounted_price'])) {
            $validated['discounted_price'] = null;
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
            // ✅ Sanitizar strings HTML
            if (isset($validated['amenities'])) {
                $validated['amenities'] = htmlspecialchars($validated['amenities'], ENT_QUOTES, 'UTF-8');
            }
            if (isset($validated['title'])) {
                $validated['title'] = htmlspecialchars($validated['title'], ENT_QUOTES, 'UTF-8');
            }
            if (isset($validated['description'])) {
                $validated['description'] = htmlspecialchars($validated['description'], ENT_QUOTES, 'UTF-8');
            }
            
            $property = Property::create($validated);
            return response()->json($property, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al crear la propiedad'], 500);
        }
    }

    public function show(Property $property): JsonResponse
    {
        return response()->json($property->load(['rooms']));
    }

    public function trackView(Property $property): JsonResponse
    {
        DB::transaction(function () use ($property) {
            $property->lockForUpdate()->increment('views');
        });
        return response()->json(['success' => true]);
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'type' => 'nullable|string',
            'status' => 'nullable|string',
            'title' => 'nullable|string',
            'location' => 'nullable|string',
            'beds' => 'nullable|integer',
            'baths' => 'nullable|integer',
            'area' => 'nullable|string',
            'price' => 'nullable|numeric',
            'discounted_price' => 'nullable|numeric',
            'rating' => 'nullable|numeric',
            'reviews' => 'nullable|integer',
            'img' => 'nullable',
            'img_file' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg|max:20480',
            'new_images.*' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg|max:20480',
            'amenities' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        
        // Limpiar valores vacíos/nulos de discounted_price
        if (!isset($validated['discounted_price']) || $validated['discounted_price'] === null || $validated['discounted_price'] == 0) {
            $validated['discounted_price'] = null;
        }

        $imageUrls = is_array($property->images) ? $property->images : [];
        if ($request->hasFile('new_images')) {
            $files = $request->file('new_images');
            foreach ($files as $file) {
                if (count($imageUrls) >= 5) break;
                $imageUrls[] = $this->optimizeImage($file);
            }
            $validated['images'] = $imageUrls;
        }

        if ($request->hasFile('img_file')) {
            $validated['img'] = $this->optimizeImage($request->file('img_file'));
        } elseif (empty($property->img) && count($imageUrls) > 0) {
            $validated['img'] = $imageUrls[0];
        }

        if ($request->hasFile('img_file')) {
            $path = $request->file('img_file')->store('properties', 'uploads');
            $validated['img'] = asset('uploads/' . $path);
        }

        try {
            // ✅ Sanitizar strings HTML
            if (isset($validated['amenities'])) {
                $validated['amenities'] = htmlspecialchars($validated['amenities'], ENT_QUOTES, 'UTF-8');
            }
            
            $property->update($validated);
            return response()->json($property);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar la propiedad'], 500);
        }
    }

    public function destroy(Property $property): JsonResponse
    {
        $property->delete();
        return response()->json(null, 204);
    }
}
