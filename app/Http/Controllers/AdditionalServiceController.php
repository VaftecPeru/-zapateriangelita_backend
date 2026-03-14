<?php

namespace App\Http\Controllers;

use App\Models\AdditionalService;
use Illuminate\Http\Request;

class AdditionalServiceController extends Controller
{
    public function index()
    {
        return response()->json(AdditionalService::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'price' => 'required|numeric',
        ]);

        $service = AdditionalService::create($validated);
        return response()->json($service, 201);
    }

    public function show(AdditionalService $additionalService)
    {
        return response()->json($additionalService);
    }

    public function update(Request $request, AdditionalService $additionalService)
    {
        $validated = $request->validate([
            'name' => 'string',
            'description' => 'nullable|string',
            'price' => 'numeric',
        ]);

        $additionalService->update($validated);
        return response()->json($additionalService);
    }

    public function destroy(AdditionalService $additionalService)
    {
        $additionalService->delete();
        return response()->json(null, 204);
    }
}
