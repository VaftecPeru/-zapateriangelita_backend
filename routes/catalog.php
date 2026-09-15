<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// Reseñas públicas de producto.
Route::middleware('throttle:100,1')
    ->get('/products/{product}/reviews', [ReviewController::class, 'publicIndex']);

// Marcas: lectura para usuario autenticado y mantenimiento para administrador.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/brands/{brand}', [BrandController::class, 'show']);

    Route::middleware('admin')->group(function () {
        Route::post('/brands', [BrandController::class, 'store']);
        Route::put('/brands/{brand}', [BrandController::class, 'update']);
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);
    });
});
