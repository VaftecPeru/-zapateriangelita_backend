<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\AdditionalServiceController;
use App\Http\Controllers\StatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Rutas Públicas (Búsqueda y Catálogo)
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::post('/properties/{property}/view', [PropertyController::class, 'trackView']);
Route::get('/services', [AdditionalServiceController::class, 'index']);
Route::post('/leads', [\App\Http\Controllers\LeadController::class, 'store']);
Route::get('/settings', [\App\Http\Controllers\SettingController::class, 'index']);

// Rutas Privadas (Requieren Token)
Route::middleware('auth:sanctum')->group(function () {
    // Gestión de Propiedades
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
    
    // Gestión de Servicios
    Route::post('/services', [AdditionalServiceController::class, 'store']);
    Route::put('/services/{service}', [AdditionalServiceController::class, 'update']);
    Route::delete('/services/{service}', [AdditionalServiceController::class, 'destroy']);
    
    // Estadísticas y Ajustes
    Route::get('/stats', [StatsController::class, 'index']);
    Route::put('/settings/{key}', [\App\Http\Controllers\SettingController::class, 'update']);

    // Usuario y Logout
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);
});
