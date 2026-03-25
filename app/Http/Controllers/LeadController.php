<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'           => 'required|string',
            'item_id'        => 'required|integer',
            'first_name'     => 'nullable|string|max:255',
            'last_name'      => 'nullable|string|max:255',
            'phone'          => 'required|regex:/^[0-9\-\+\s\(\)]{7,}$/',
            'check_in'       => 'required|date|after_or_equal:today',
            'check_out'      => 'required|date|after:check_in|before_or_equal:' . now()->addMonths(1)->format('Y-m-d'),
            'guests'         => 'nullable|integer|min:1|max:20',
            'property_title' => 'nullable|string|max:255',
        ]);

        $lead = Lead::create($validated);

        return response()->json(['success' => true, 'lead' => $lead], 201);
    }

    public function index(): JsonResponse
    {
        $leads = Lead::orderBy('created_at', 'desc')->get();
        return response()->json($leads);
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'is_read' => 'boolean',
        ]);

        $lead->update($validated);
        return response()->json($lead);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();
        return response()->json(null, 204);
    }
}
