<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'item_id' => 'required|integer'
        ]);

        $lead = Lead::create($validated);
        
        return response()->json(['success' => true, 'lead' => $lead], 201);
    }
}
