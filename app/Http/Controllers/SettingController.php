<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key')->all();
        
        return response()->json([
            'success' => true,
            'data' => (object)$settings
        ]);
    }

    
    public function update(Request $request, string $key)
    {
        $request->validate([
            'value' => 'required'
        ]);

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $request->value]
        );

        return response()->json([
            'success' => true,
            'message' => 'Setting updated successfully',
            'data' => $setting
        ]);
    }
}
