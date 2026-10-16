<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Brand::orderBy('name')->get());
    }

    public function show(Brand $brand): JsonResponse
    {
        return response()->json($brand);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'slug' => 'nullable|string|max:255|unique:brands,slug',
            'is_active' => 'nullable|boolean',
        ]);

        $normalized = \Illuminate\Support\Str::slug(trim($validated['name']));
        if (Brand::query()->get(['name'])->contains(fn ($item) =>
            \Illuminate\Support\Str::slug($item->name) === $normalized
        )) {
            return response()->json(['message' => 'La marca ya existe. Selecciona la existente o actívala.'], 422);
        }
        $validated['name'] = trim($validated['name']);
        $validated['slug'] = $validated['slug'] ?? $normalized;

        $brand = Brand::create($validated);
        return response()->json($brand, 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255|unique:brands,name,' . $brand->id,
            'slug' => 'nullable|string|max:255|unique:brands,slug,' . $brand->id,
            'is_active' => 'nullable|boolean',
        ]);

        $brand->update($validated);
        return response()->json($brand);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        if ($brand->products()->count() > 0) {
            return response()->json([
                'error' => 'No se puede eliminar la marca porque tiene productos asociados.'
            ], 409);
        }

        $brand->delete();
        return response()->json(null, 204);
    }
}
