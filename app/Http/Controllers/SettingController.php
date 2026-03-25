<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    
    public function index(): JsonResponse
    {
        // Solo permitimos exponer llaves que no sean sensibles
        $publicKeys = ['whatsapp_number', 'company_name', 'logo_url', 'contact_email'];
        
        $settings = Setting::whereIn('key', $publicKeys)
            ->get()
            ->pluck('value', 'key')
            ->all();
        
        return response()->json([
            'success' => true,
            'data' => (object)$settings
        ]);
    }

    
    public function update(Request $request, string $key): JsonResponse
    {
        // ✅ Solo admin puede cambiar configuración
        if (auth('sanctum')->user()?->role !== 'admin') {
            return response()->json(['message' => 'No tienes permisos para cambiar la configuración'], 403);
        }

        $request->validate([
            'value' => 'required'
        ]);

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $request->value]
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada',
            'data' => $setting
        ]);
    }
}
