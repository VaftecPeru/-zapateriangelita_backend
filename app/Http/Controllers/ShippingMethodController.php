<?php

namespace App\Http\Controllers;

use App\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShippingMethodController extends Controller
{
    /**
     * Listar métodos de envío públicos (activos)
     * GET /api/public/shipping-methods
     */
    public function publicIndex(): JsonResponse
    {
        return response()->json(ShippingMethod::active()->get());
    }

    /**
     * Listar todos los métodos de envío (admin)
     * GET /api/shipping-methods
     */
    public function index(): JsonResponse
    {
        return response()->json(ShippingMethod::get());
    }

    /**
     * Crear un método de envío (admin)
     * POST /api/shipping-methods
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $method = ShippingMethod::create($validated);
            return response()->json($method, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear el método de envío: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un método de envío (admin)
     * PUT /api/shipping-methods/{shippingMethod}
     */
    public function update(Request $request, ShippingMethod $shippingMethod): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $shippingMethod->update($validated);
            $shippingMethod->refresh();
            return response()->json($shippingMethod);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar el método de envío: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un método de envío (admin)
     * DELETE /api/shipping-methods/{shippingMethod}
     */
    public function destroy(ShippingMethod $shippingMethod): JsonResponse
    {
        $shippingMethod->delete();
        return response()->json(null, 204);
    }
}