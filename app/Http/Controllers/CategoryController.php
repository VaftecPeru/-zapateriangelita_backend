<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Listar todas las categorías (admin, con subcategorías)
     * GET /api/categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::with('subcategories')
            ->ordered()
            ->get();

        return response()->json($categories);
    }

    /**
     * Listar categorías públicas (solo activas, para el storefront)
     * GET /api/public/categories
     */
    public function publicIndex(): JsonResponse
    {
        $categories = Category::active()
            ->ordered()
            ->withCount('products')
            ->get();

        return response()->json($categories);
    }

    /**
     * Mostrar una categoría específica
     * GET /api/categories/{category}
     */
    public function show(Category $category): JsonResponse
    {
        $category->load('subcategories');
        return response()->json($category);
    }

    /**
     * Crear una nueva categoría
     * POST /api/categories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'image' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:1000',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $category = Category::create($validated);
            $category->load('subcategories');
            return response()->json($category, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear la categoría: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una categoría existente
     * PUT /api/categories/{category}
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:50',
            'image' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:1000',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $category->update($validated);
            $category->refresh();
            $category->load('subcategories');
            return response()->json($category);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar la categoría: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una categoría
     * DELETE /api/categories/{category}
     */
    public function destroy(Category $category): JsonResponse
    {
        try {
            // Verificar si tiene productos
            if ($category->products()->count() > 0) {
                return response()->json([
                    'error' => 'No se puede eliminar la categoría porque tiene productos asociados.'
                ], 409);
            }

            // Verificar si tiene subcategorías
            if ($category->subcategories()->count() > 0) {
                return response()->json([
                    'error' => 'No se puede eliminar la categoría porque tiene subcategorías asociadas.'
                ], 409);
            }

            $category->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se puede eliminar la categoría porque tiene productos o subcategorías asociadas.'
            ], 409);
        }
    }
}