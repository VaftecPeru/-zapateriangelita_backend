<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FavoriteController extends Controller
{
    /**
     * Listar favoritos del usuario autenticado
     * GET /api/favorites
     */
    public function index(Request $request): JsonResponse
    {
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with('product')
            ->latest()
            ->get();

        return response()->json($favorites);
    }

    /**
     * Agregar o quitar un favorito (toggle)
     * POST /api/favorites  { "product_id": 1 }
     */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $user = $request->user();
        $favorite = Favorite::where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->first();

        try {
            if ($favorite) {
                $favorite->delete();
                return response()->json([
                    'active' => false,
                    'message' => 'Producto eliminado de favoritos.',
                ]);
            }

            Favorite::create([
                'user_id' => $user->id,
                'product_id' => $validated['product_id'],
            ]);

            return response()->json([
                'active' => true,
                'message' => 'Producto agregado a favoritos.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar favoritos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Estado de favorito de un producto para el usuario
     * GET /api/favorites/status?product_id=1
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $active = Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $validated['product_id'])
            ->exists();

        return response()->json(['active' => $active]);
    }
}