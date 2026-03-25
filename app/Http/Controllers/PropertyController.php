<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
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
            'img_file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:5120', 
            'images' => 'sometimes|nullable|array', 
            'new_images' => 'sometimes|nullable|array', 
            'new_images.*' => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:5120',
            'amenities' => 'nullable|string|max:1000',
        ]);

        // Limpiar discounted_price si está vacío
        if (empty($validated['discounted_price'])) {
            $validated['discounted_price'] = null;
        }

        $imageUrls = [];
        if ($request->hasFile('new_images')) {
            foreach ($request->file('new_images') as $file) {
                $path = $file->store('properties', 'uploads');
                $imageUrls[] = asset('uploads/' . $path);
            }
            $validated['images'] = $imageUrls;
        }

        if ($request->hasFile('img_file')) {
            $path = $request->file('img_file')->store('properties', 'uploads');
            $validated['img'] = asset('uploads/' . $path);
        } elseif (!$request->hasFile('img_file') && count($imageUrls) > 0 && empty($validated['img'])) {
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
        // ✅ Transacción para evitar race conditions
        \DB::transaction(function () use ($property) {
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
            'img_file' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg|max:5120',
            'images' => 'sometimes|nullable|array',
            'new_images' => 'sometimes|nullable|array',
            'new_images.*' => 'sometimes|nullable|file|image|mimes:jpeg,png,jpg|max:5120',
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

        $imageUrls = $property->images ?? [];
        if ($request->hasFile('new_images')) {
            
            $imageUrls = []; 
            foreach ($request->file('new_images') as $file) {
                $path = $file->store('properties', 'uploads');
                $imageUrls[] = asset('uploads/' . $path);
            }
            $validated['images'] = $imageUrls;
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
