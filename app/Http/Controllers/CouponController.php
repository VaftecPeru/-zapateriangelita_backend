<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CouponController extends Controller
{
    /**
     * Listar cupones (admin)
     * GET /api/coupons
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(Coupon::orderBy('created_at', 'desc')->get());
    }

    /**
     * Validar un cupón en el checkout (público)
     * GET /api/coupons/check?code=X&amount=100
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30',
            'amount' => 'required|numeric|min:0',
        ]);

        $coupon = Coupon::where('code', strtoupper($validated['code']))->first();

        if (!$coupon) {
            return response()->json(['valid' => false, 'message' => 'Cupón no encontrado.'], 404);
        }

        if (!$coupon->isValid()) {
            return response()->json(['valid' => false, 'message' => 'El cupón no es válido o está vencido.'], 422);
        }

        $discount = $coupon->applyDiscount($validated['amount']);

        return response()->json([
            'valid' => true,
            'coupon' => $coupon,
            'discount' => $discount,
        ]);
    }

    /**
     * Crear un cupón (admin)
     * POST /api/coupons
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:coupons,code',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'max_uses' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $validated['code'] = strtoupper($validated['code']);
            $coupon = Coupon::create($validated);
            return response()->json($coupon, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear el cupón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un cupón (admin)
     * PUT /api/coupons/{coupon}
     */
    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:30|unique:coupons,code,' . $coupon->id,
            'type' => 'nullable|in:percent,fixed',
            'value' => 'nullable|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'max_uses' => 'nullable|integer|min:0',
            'used' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            if (isset($validated['code'])) {
                $validated['code'] = strtoupper($validated['code']);
            }
            $coupon->update($validated);
            $coupon->refresh();
            return response()->json($coupon);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar el cupón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un cupón (admin)
     * DELETE /api/coupons/{coupon}
     */
    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();
        return response()->json(null, 204);
    }
}