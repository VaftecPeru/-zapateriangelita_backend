<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    /**
     * Listar marcas (admin)
     * GET /api/brands
     */
    public function index(Request $request): JsonResponse
    {
        $query = Brand::withCount('products')->ordered();

        return response()->json($query->get());
    }

    /**
     * Listar marcas públicas (solo activas, para el storefront)
     * GET /api/public/brands
     */
    public function publicIndex(): JsonResponse
    {
        $brands = Brand::active()
            ->withCount('products')
            ->ordered()
            ->get();

        return response()->json($brands);
    }

    /**
     * Mostrar una marca
     * GET /api/brands/{brand}
     */
    public function show(Brand $brand): JsonResponse
    {
        $brand->loadCount('products');
        return response()->json($brand);
    }

    /**
     * Crear una marca
     * POST /api/brands
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $brand = Brand::create($validated);
            return response()->json($brand, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear la marca: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una marca
     * PUT /api/brands/{brand}
     */
    public function update(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $brand->update($validated);
            $brand->refresh();
            return response()->json($brand);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar la marca: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una marca
     * DELETE /api/brands/{brand}
     */
    public function destroy(Brand $brand): JsonResponse
    {
        try {
            if ($brand->products()->count() > 0) {
                return response()->json([
                    'error' => 'No se puede eliminar la marca porque tiene productos asociados.'
                ], 409);
            }

            $brand->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se puede eliminar la marca.'
            ], 409);
        }
    }
}