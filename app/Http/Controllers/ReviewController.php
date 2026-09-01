<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    /**
     * Listar reseñas de un producto (públicas, aprobadas)
     * GET /api/products/{product}/reviews
     */
    public function publicIndex(Product $product): JsonResponse
    {
        $reviews = Review::approved()
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        $avgRating = $reviews->avg('rating');
        $ratingCount = $reviews->count();

        return response()->json([
            'average' => $avgRating ? round($avgRating, 1) : 0,
            'total' => $ratingCount,
            'reviews' => $reviews,
        ]);
    }

    /**
     * Listar todas las reseñas (admin)
     * GET /api/reviews
     * GET /api/reviews?product_id=1&pending=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = Review::with(['product:id,name,img', 'user:id,name']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->has('pending')) {
            $query->where('is_approved', (bool) $request->input('pending') === false);
        }

        if ($request->has('approved')) {
            $query->where('is_approved', (bool) $request->input('approved'));
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    /**
     * Crear una reseña (usuario autenticado)
     * POST /api/reviews
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        try {
            $review = Review::create([
                'product_id' => $validated['product_id'],
                'user_id' => $user->id,
                'name' => $user->name,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'is_approved' => false,
            ]);

            return response()->json($review, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear la reseña: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Aprobar/desaprobar una reseña (admin)
     * PUT /api/reviews/{review}
     */
    public function update(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate([
            'is_approved' => 'required|boolean',
        ]);

        $review->update($validated);
        return response()->json($review);
    }

    /**
     * Eliminar una reseña (admin)
     * DELETE /api/reviews/{review}
     */
    public function destroy(Review $review): JsonResponse
    {
        $review->delete();
        return response()->json(null, 204);
    }
}