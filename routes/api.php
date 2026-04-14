<?php

use App\Http\Controllers\LeadController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdditionalServiceController;
use App\Http\Controllers\StatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->post('/register', [AuthController::class, 'register']);  // 60/min
Route::middleware('throttle:60,1')->post('/login', [AuthController::class, 'login']);        // 60/min
Route::middleware('throttle:10,1')->post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::middleware('throttle:10,1')->post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('throttle:100,1')->group(function () {
    Route::get('/properties', [PropertyController::class, 'index']);
    Route::get('/properties/{property}', [PropertyController::class, 'show']);
    Route::post('/properties/{property}/view', [PropertyController::class, 'trackView']);
    Route::get('/services', [AdditionalServiceController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::get('/settings', [SettingController::class, 'index']);

    Route::get('/settings', [SettingController::class, 'index']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/user/update', function (Request $request) {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'birthdate' => 'nullable|date',
            'gender' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
        ]);
        $user->update($validated);
        return response()->json($user);
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/my-bookings', [LeadController::class, 'clientLeads']);

    Route::middleware(['admin'])->group(function () {
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::put('/properties/{property}', [PropertyController::class, 'update']);
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
        
        Route::post('/services', [AdditionalServiceController::class, 'store']);
        Route::put('/services/{service}', [AdditionalServiceController::class, 'update']);
        Route::delete('/services/{service}', [AdditionalServiceController::class, 'destroy']);
        
        Route::get('/stats', [StatsController::class, 'index']);
        Route::put('/settings/{key}', [SettingController::class, 'update']);

        Route::get('/leads', [LeadController::class, 'index']);
        Route::put('/leads/{lead}', [LeadController::class, 'update']);
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

        Route::get('/users', [\App\Http\Controllers\UserController::class, 'index']);
    });
});
