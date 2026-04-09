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
            'email'          => 'nullable|email|max:255',
            'check_in'       => 'nullable|required_if:type,property|date|after_or_equal:today',
            'check_out'      => 'nullable|required_if:type,property|date|after:check_in|before_or_equal:' . now()->addMonths(1)->format('Y-m-d'),
            'guests'         => 'nullable|integer|min:1|max:20',
            'property_title' => 'nullable|string|max:255',
            'additional_services' => 'nullable|array',
        ]);

        $userId = null;
        if (!empty($validated['email'])) {
            $user = \App\Models\User::where('email', $validated['email'])->first();
            
            if (!$user) {
                // Generate a random, readable password
                $plainPassword = \Illuminate\Support\Str::random(10);
                
                $user = \App\Models\User::create([
                    'email' => $validated['email'],
                    'name' => trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? '')),
                    'password' => \Illuminate\Support\Facades\Hash::make($plainPassword),
                    'role' => 'user'
                ]);

                try {
                    \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\UserCredentialsMail($user, $plainPassword));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send welcome credentials email: ' . $e->getMessage());
                }
            }
            
            $userId = $user->id;
        }
        
        $validated['user_id'] = $userId;
        $lead = Lead::create($validated);

        return response()->json(['success' => true, 'lead' => $lead], 201);
    }

    public function index(): JsonResponse
    {
        $leads = Lead::orderBy('created_at', 'desc')->get();
        return response()->json($leads);
    }

    public function clientLeads(Request $request): JsonResponse
    {
        $leads = Lead::where('user_id', $request->user()->id)->orderBy('created_at', 'desc')->get();
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
