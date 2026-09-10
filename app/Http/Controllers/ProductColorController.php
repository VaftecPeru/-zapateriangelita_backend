<?php

namespace App\Http\Controllers;

use App\Models\ProductColor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductColorController extends Controller
{
    /**
     * Listar colores de un producto
     * GET /api/product-colors?product_id=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductColor::with('product');

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        return response()->json($query->get());
    }

    /**
     * Crear/agregar un color a un producto
     * POST /api/product-colors
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'color' => 'required|string|max:50',
            'hex' => 'nullable|string|max:7',
        ]);

        try {
            $color = ProductColor::firstOrCreate(
                ['product_id' => $validated['product_id'], 'color' => $validated['color']],
                ['hex' => $validated['hex'] ?? null]
            );
            $color->load('product');
            return response()->json($color, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al agregar el color: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un color
     * PUT /api/product-colors/{productColor}
     */
    public function update(Request $request, ProductColor $productColor): JsonResponse
    {
        $validated = $request->validate([
            'color' => 'nullable|string|max:50',
            'hex' => 'nullable|string|max:7',
        ]);

        try {
            $productColor->update($validated);
            $productColor->refresh();
            return response()->json($productColor);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar el color: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un color
     * DELETE /api/product-colors/{productColor}
     */
    public function destroy(ProductColor $productColor): JsonResponse
    {
        $productColor->delete();
        return response()->json(null, 204);
    }
}