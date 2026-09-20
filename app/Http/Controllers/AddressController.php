<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AddressController extends Controller
{
    /**
     * Listar direcciones del usuario autenticado
     * GET /api/addresses
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = Address::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->get();

        return response()->json($addresses);
    }

    /**
     * Crear una dirección
     * POST /api/addresses
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:20',
            'country' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'municipality' => 'required|string|max:100',
            'address' => 'nullable|string',
            'city' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'reference' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ]);

        $user = $request->user();

        try {
            $duplicate = Address::where('user_id', $user->id)
                ->get()
                ->first(fn (Address $candidate) => $this->sameAddress($candidate, $validated));

            if ($duplicate) {
                $duplicate->update($validated);

                if (!empty($validated['is_default'])) {
                    $this->setDefault($duplicate);
                }

                return response()->json($duplicate->fresh());
            }

            $address = Address::create(array_merge($validated, ['user_id' => $user->id]));

            // Si es la primera dirección, hacerla default
            if ($address->is_default || Address::where('user_id', $user->id)->count() === 1) {
                $this->setDefault($address);
            }

            return response()->json($address, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear la dirección: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una dirección del usuario
     * PUT /api/addresses/{address}
     */
    public function update(Request $request, Address $address): JsonResponse
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'full_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:20',
            'country' => 'sometimes|required|string|max:100',
            'state' => 'sometimes|required|string|max:100',
            'municipality' => 'sometimes|required|string|max:100',
            'address' => 'nullable|string',
            'city' => 'sometimes|required|string|max:100',
            'postal_code' => 'sometimes|required|string|max:20',
            'reference' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ]);

        try {
            if (!empty($validated['is_default'])) {
                $this->setDefault($address);
            }

            $address->update($validated);
            $address->refresh();
            return response()->json($address);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar la dirección: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una dirección del usuario
     * DELETE /api/addresses/{address}
     */
    public function destroy(Request $request, Address $address): JsonResponse
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $address->delete();
        return response()->json(null, 204);
    }

    private function sameAddress(Address $address, array $data): bool
    {
        $fields = [
            'full_name',
            'phone',
            'country',
            'state',
            'municipality',
            'address',
            'city',
            'postal_code',
            'reference',
        ];

        foreach ($fields as $field) {
            $left = mb_strtolower(trim((string) ($address->{$field} ?? '')));
            $right = mb_strtolower(trim((string) ($data[$field] ?? '')));

            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }

    /**
     * Marcar una dirección como default (quita las demás)
     */
    private function setDefault(Address $address): void
    {
        Address::where('user_id', $address->user_id)
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);

        $address->update(['is_default' => true]);
    }
}