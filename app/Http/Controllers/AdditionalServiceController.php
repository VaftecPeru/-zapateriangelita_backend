<?php

namespace App\Http\Controllers;

use App\Models\AdditionalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdditionalServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(AdditionalService::all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'price' => 'required|numeric',
            'tag' => 'nullable|string',
        ]);

        $service = AdditionalService::create($validated);
        return response()->json($service, 201);
    }

    public function show(AdditionalService $service): JsonResponse
    {
        return response()->json($service);
    }

    public function update(Request $request, AdditionalService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string',
            'description' => 'nullable|string',
            'price' => 'numeric',
            'tag' => 'nullable|string',
        ]);

        $service->update($validated);
        return response()->json($service);
    }

    public function destroy(AdditionalService $service): JsonResponse
    {
        $service->delete();
        return response()->json(null, 204);
    }
}
