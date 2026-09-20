<?php

namespace App\Http\Controllers;

use App\Models\AdditionalService;
use App\Services\DeliveryCostService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdditionalServiceController extends Controller
{
    public function index(): JsonResponse
    {
        $query = AdditionalService::query();

        if (Schema::hasColumn('additional_services', 'code')) {
            $query->orderByRaw("CASE WHEN code = 'delivery' THEN 0 ELSE 1 END");
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function delivery(DeliveryCostService $deliveryCost): JsonResponse
    {
        $service = Schema::hasColumn('additional_services', 'code')
            ? AdditionalService::query()->where('code', 'delivery')->first()
            : null;

        return response()->json([
            'code' => 'delivery',
            'name' => $service?->name ?? 'Costo de delivery',
            'price' => $deliveryCost->current(),
            'is_active' => $service ? $service->is_active !== false : false,
            'description' => $service?->description,
            'updated_at' => $service?->updated_at,
        ]);
    }

    public function updateDelivery(Request $request): JsonResponse
    {
        if (
            !Schema::hasColumn('additional_services', 'code') ||
            !Schema::hasColumn('additional_services', 'is_active')
        ) {
            return response()->json([
                'message' => 'La base de datos todavía no tiene aplicada la migración del costo de delivery.',
            ], 409);
        }

        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $service = DB::transaction(function () use ($validated) {
            $service = AdditionalService::query()
                ->where('code', 'delivery')
                ->lockForUpdate()
                ->first();

            if (!$service) {
                $service = AdditionalService::query()
                    ->where(function ($query) {
                        $query
                            ->whereRaw('LOWER(name) LIKE ?', ['%delivery%'])
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%envio%']);
                    })
                    ->lockForUpdate()
                    ->first();
            }

            if (!$service) {
                $service = new AdditionalService();
            }

            $service->fill([
                'name' => 'Costo de delivery',
                'code' => 'delivery',
                'description' => $validated['description']
                    ?? 'Costo de entrega aplicado automáticamente al total de la compra.',
                'price' => round((float) $validated['price'], 2),
                'tag' => 'Delivery',
                'is_active' => (bool) $validated['is_active'],
            ]);
            $service->save();

            return $service->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => $service->is_active
                ? 'Costo de delivery actualizado correctamente.'
                : 'Costo de delivery desactivado. El checkout cobrará MXN 0.00.',
            'service' => $service,
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
