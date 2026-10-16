<?php

namespace App\Http\Controllers;

use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SubcategoryController extends Controller
{
    /**
     * Listar todas las subcategorías (admin)
     * GET /api/subcategories
     * GET /api/subcategories?category_id=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = Subcategory::with('category')->ordered();

        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        return response()->json($query->get());
    }

    /**
     * Listar subcategorías públicas (solo activas, para el storefront)
     * GET /api/public/subcategories
     * GET /api/public/subcategories?category_id=1
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $query = Subcategory::active()->ordered();

        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        return response()->json($query->get());
    }

    /**
     * Mostrar una subcategoría específica
     * GET /api/subcategories/{subcategory}
     */
    public function show(Subcategory $subcategory): JsonResponse
    {
        $subcategory->load('category');
        return response()->json($subcategory);
    }

    /**
     * Crear una nueva subcategoría
     * POST /api/subcategories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'name'        => 'required|string|max:255',
            'talla'       => 'nullable|string|max:100',
            'order'       => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        $normalized = \\Illuminate\\Support\\Str::slug(trim($validated['name']));
        if (Subcategory::query()->where('category_id', $validated['category_id'])
            ->get(['name'])->contains(fn ($item) => \\Illuminate\\Support\\Str::slug($item->name) === $normalized)) {
            return response()->json(['message' => 'La subcategoría ya existe dentro de esta categoría.'], 422);
        }
        $validated['name'] = trim($validated['name']);

        try {
            $subcategory = Subcategory::create($validated);
            $subcategory->load('category');
            return response()->json($subcategory, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear la subcategoría: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una subcategoría existente
     * PUT /api/subcategories/{subcategory}
     */
    public function update(Request $request, Subcategory $subcategory): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
            'name'        => 'nullable|string|max:255',
            'talla'       => 'nullable|string|max:100',
            'order'       => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        try {
            $subcategory->update($validated);
            $subcategory->refresh();
            $subcategory->load('category');
            return response()->json($subcategory);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar la subcategoría: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una subcategoría
     * DELETE /api/subcategories/{subcategory}
     */
    public function destroy(Subcategory $subcategory): JsonResponse
    {
        if ($subcategory->products()->exists()) {
            return response()->json([
                'error' => 'No se puede eliminar la subcategoría porque tiene productos asociados.',
            ], 409);
        }

        $subcategory->delete();
        return response()->json(null, 204);
    }
}
