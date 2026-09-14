<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:store,service,property',
            'item_id' => 'nullable|integer',
            'email' => 'required|email|max:255',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|min:2|max:100',
            'phone' => 'required|string|max:30',
            'product_interest' => 'required|string|max:100',
            'shoe_size' => 'required|string|max:20',
            'contact_preference' => 'required|string|max:30',
            'message' => 'required|string|min:10|max:2000',
        ]);

        $lead = Lead::create(array_merge($validated, [
            'item_id' => $validated['item_id'] ?? 0,
            'property_title' => $validated['product_interest'] ?? 'Consulta de tienda',
            'is_read' => false,
        ]));

        return response()->json($lead, 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(Lead::orderByDesc('created_at')->get());
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate(['is_read' => 'sometimes|boolean']);
        $lead->update($validated);
        return response()->json($lead);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();
        return response()->json(null, 204);
    }
}
