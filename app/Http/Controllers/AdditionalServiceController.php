<?php

namespace App\Http\Controllers;

use App\Models\AdditionalService;
use App\Services\DeliveryCostService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdditionalServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(AdditionalService::orderByRaw("CASE WHEN code = 'delivery' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get());
    }

    public function delivery(DeliveryCostService $deliveryCost): JsonResponse
    {
        return response()->json([
            'code' => 'delivery',
            'price' => $deliveryCost->current(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:additional_services,code',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'tag' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($validated['code'])) {
            $validated['code'] = strtolower(trim($validated['code']));
        }

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
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50|unique:additional_services,code,' . $service->id,
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'tag' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        if ($service->code === 'delivery') {
            $validated['code'] = 'delivery';
        } elseif (!empty($validated['code'])) {
            $validated['code'] = strtolower(trim($validated['code']));
        }

        $service->update($validated);
        return response()->json($service);
    }

    public function destroy(AdditionalService $service): JsonResponse
    {
        if ($service->code === 'delivery') {
            return response()->json([
                'message' => 'El concepto de delivery es parte del checkout y no se puede eliminar. Puedes desactivarlo o cambiar su costo.',
            ], 409);
        }

        $service->delete();
        return response()->json(null, 204);
    }
}
