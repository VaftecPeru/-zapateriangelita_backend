<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type'           => 'required|string',
                'item_id'        => 'required|integer',
                'first_name'     => 'nullable|string|max:255',
                'last_name'      => 'nullable|string|max:255',
                'phone'          => 'required|string|max:50', // Más flexible
                'email'          => 'nullable|email|max:255',
                'check_in'       => 'nullable|date',
                'check_out'      => 'nullable|date',
                'guests'         => 'nullable|integer',
                'property_title' => 'nullable|string|max:255',
                'property_id'    => 'nullable|integer',
                'additional_services' => 'nullable|array',
            ]);

            $userId = null;
            if (!empty($validated['email'])) {
                try {
                    $user = \App\Models\User::where('email', $validated['email'])->first();
                    
                    if (!$user) {
                        $plainPassword = \Illuminate\Support\Str::random(10);
                        $user = \App\Models\User::create([
                            'email' => $validated['email'],
                            'name' => trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? '')),
                            'password' => \Illuminate\Support\Facades\Hash::make($plainPassword),
                            'role' => 'user'
                        ]);

                        try {
                            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\UserCredentialsMail($user, $plainPassword));
                        } catch (\Throwable $mailErr) {
                            \Illuminate\Support\Facades\Log::warning('Email skip: ' . $mailErr->getMessage());
                        }
                    }
                    $userId = $user->id;
                } catch (\Throwable $userErr) {
                    \Illuminate\Support\Facades\Log::error('User creation skip: ' . $userErr->getMessage());
                }
            }
            
            // Verificación dinámica de columnas para evitar Error 500 si la base de datos está desfazada
            $columns = \Illuminate\Support\Facades\Schema::getColumnListing('leads');
            $dataToSave = $validated;

            if (!in_array('user_id', $columns)) {
                unset($dataToSave['user_id']);
            } else {
                $dataToSave['user_id'] = $userId;
            }

            if (!in_array('additional_services', $columns)) {
                unset($dataToSave['additional_services']);
            }

            // También verificar campos de nombre por si acaso
            if (!in_array('first_name', $columns)) unset($dataToSave['first_name']);
            if (!in_array('last_name', $columns)) unset($dataToSave['last_name']);
            if (!in_array('property_title', $columns)) unset($dataToSave['property_title']);
            
            $lead = Lead::create($dataToSave);

            return response()->json([
                'success' => true, 
                'lead' => $lead,
                'plain_password' => isset($plainPassword) ? $plainPassword : null
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Datos inválidos',
                'messages' => $e->errors()
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Lead store fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'error' => 'Error interno del servidor',
                'message' => $e->getMessage(),
                'debug_info' => 'Check laravel.log for details'
            ], 500);
        }
    }

    public function index(): JsonResponse
    {
        $leads = Lead::with('property')->orderBy('created_at', 'desc')->get();
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
