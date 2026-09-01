<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    /**
     * Listar banners públicos (activos) para el storefront
     * GET /api/public/banners
     * GET /api/public/banners?position=home
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $query = Banner::active()->ordered();

        if ($request->has('position')) {
            $query->where('position', $request->input('position'));
        }

        return response()->json($query->get());
    }

    /**
     * Listar todos los banners (admin)
     * GET /api/banners
     */
    public function index(): JsonResponse
    {
        return response()->json(Banner::orderBy('order', 'asc')->get());
    }

    /**
     * Crear un banner (admin)
     * POST /api/banners
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'subtitle' => 'nullable|string|max:200',
            'image' => 'nullable|string|max:500',
            'link' => 'nullable|string|max:500',
            'position' => 'nullable|string|max:20',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $banner = Banner::create($validated);
            return response()->json($banner, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear el banner: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un banner (admin)
     * PUT /api/banners/{banner}
     */
    public function update(Request $request, Banner $banner): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'subtitle' => 'nullable|string|max:200',
            'image' => 'nullable|string|max:500',
            'link' => 'nullable|string|max:500',
            'position' => 'nullable|string|max:20',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $banner->update($validated);
            $banner->refresh();
            return response()->json($banner);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar el banner: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un banner (admin)
     * DELETE /api/banners/{banner}
     */
    public function destroy(Banner $banner): JsonResponse
    {
        $banner->delete();
        return response()->json(null, 204);
    }
}