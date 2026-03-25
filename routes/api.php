<?php

use App\Http\Controllers\LeadController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdditionalServiceController;
use App\Http\Controllers\StatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ✅ Rate limiting específico por tipo de endpoint
Route::middleware('throttle:60,1')->post('/register', [AuthController::class, 'register']);  // 60/min
Route::middleware('throttle:60,1')->post('/login', [AuthController::class, 'login']);        // 60/min

// Rutas Públicas (Búsqueda y Catálogo) - Rate limit normal
Route::middleware('throttle:100,1')->group(function () {
    Route::get('/properties', [PropertyController::class, 'index']);
    Route::get('/properties/{property}', [PropertyController::class, 'show']);
    Route::post('/properties/{property}/view', [PropertyController::class, 'trackView']);
    Route::get('/services', [AdditionalServiceController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::get('/settings', [SettingController::class, 'index']);
});

// Rutas Privadas (Requieren Token + Admin)
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // Gestión de Propiedades
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
    
    // Gestión de Servicios
    Route::post('/services', [AdditionalServiceController::class, 'store']);
    Route::put('/services/{service}', [AdditionalServiceController::class, 'update']);
    Route::delete('/services/{service}', [AdditionalServiceController::class, 'destroy']);
    
    // Gestión de Categorías

    // Estadísticas y Ajustes
    Route::get('/stats', [StatsController::class, 'index']);
    Route::put('/settings/{key}', [SettingController::class, 'update']);

    // Mensajes / Leads
    Route::get('/leads', [LeadController::class, 'index']);
    Route::put('/leads/{lead}', [LeadController::class, 'update']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

    // Usuario y Logout
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);
});
