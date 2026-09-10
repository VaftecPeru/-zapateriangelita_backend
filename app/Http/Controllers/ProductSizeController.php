<?php

namespace App\Http\Controllers;

use App\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductSizeController extends Controller
{
    /**
     * Listar tallas de un producto
     * GET /api/products/{product}/sizes  (por el front normal se obtienen con el producto)
     * GET /api/product-sizes?product_id=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductSize::with('product');

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        return response()->json($query->orderBy('size', 'asc')->get());
    }

    /**
     * Crear/agregar una talla a un producto
     * POST /api/product-sizes
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'size' => 'required|string|max:10',
            'stock' => 'nullable|integer|min:0',
        ]);

        $validated['stock'] = $validated['stock'] ?? 0;

        try {
            $size = ProductSize::firstOrCreate(
                ['product_id' => $validated['product_id'], 'size' => $validated['size']],
                ['stock' => $validated['stock']]
            );
            $size->load('product');
            return response()->json($size, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al agregar la talla: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar stock/talla
     * PUT /api/product-sizes/{productSize}
     */
    public function update(Request $request, ProductSize $productSize): JsonResponse
    {
        $validated = $request->validate([
            'size' => 'nullable|string|max:10',
            'stock' => 'nullable|integer|min:0',
        ]);

        try {
            $productSize->update($validated);
            $productSize->refresh();
            return response()->json($productSize);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar la talla: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una talla
     * DELETE /api/product-sizes/{productSize}
     */
    public function destroy(ProductSize $productSize): JsonResponse
    {
        $productSize->delete();
        return response()->json(null, 204);
    }
}