<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index()
    {
        return response()->json(Property::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'status' => 'required|string',
            'title' => 'required|string',
            'location' => 'required|string',
            'beds' => 'integer',
            'baths' => 'integer',
            'area' => 'string',
            'price' => 'required|numeric',
            'rating' => 'numeric',
            'reviews' => 'integer',
            'img' => 'required', // Can be string (URL) or file
        ]);

        if ($request->hasFile('img')) {
            $path = $request->file('img')->store('properties', 'public');
            $validated['img'] = asset('storage/' . $path);
        }

        $property = Property::create($validated);
        return response()->json($property, 201);
    }

    public function show(Property $property)
    {
        return response()->json($property->load('rooms'));
    }

    public function trackView(Property $property)
    {
        $property->increment('views');
        return response()->json(['success' => true]);
    }

    public function update(Request $request, Property $property)
    {
        $validated = $request->validate([
            'type' => 'string',
            'status' => 'string',
            'title' => 'string',
            'location' => 'string',
            'beds' => 'integer',
            'baths' => 'integer',
            'area' => 'string',
            'price' => 'numeric',
            'rating' => 'numeric',
            'reviews' => 'integer',
            'img' => 'nullable',
        ]);

        if ($request->hasFile('img')) {
            $path = $request->file('img')->store('properties', 'public');
            $validated['img'] = asset('storage/' . $path);
        }

        $property->update($validated);
        return response()->json($property);
    }

    public function destroy(Property $property)
    {
        $property->delete();
        return response()->json(null, 204);
    }
}
