<?php

use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdditionalServiceController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SubcategoryController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ProductSizeController;
use App\Http\Controllers\ProductColorController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\ShippingMethodController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\OrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;



Route::middleware('throttle:60,1')->post('/register', [AuthController::class, 'register']);
Route::middleware('throttle:60,1')->post('/login', [AuthController::class, 'login']);
Route::middleware('throttle:10,1')->post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::middleware('throttle:10,1')->post('/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');


Route::middleware('throttle:100,1')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    Route::get('/services', [AdditionalServiceController::class, 'index']);

    Route::post('/leads', [LeadController::class, 'store']);

    Route::get('/settings', [SettingController::class, 'index']);

    Route::get('/public/categories', [CategoryController::class, 'publicIndex']);
    Route::get('/public/subcategories', [SubcategoryController::class, 'publicIndex']);

    // Catálogo público
    Route::get('/public/brands', [BrandController::class, 'publicIndex']);
    Route::get('/public/banners', [BannerController::class, 'publicIndex']);
    Route::get('/public/shipping-methods', [ShippingMethodController::class, 'publicIndex']);

    // Reseñas públicas de un producto
    Route::get('/products/{product}/reviews', [ReviewController::class, 'publicIndex']);

    // Validar cupón en checkout
    Route::get('/coupons/check', [CouponController::class, 'check']);

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
            'state' => 'nullable|string|max:100',
            'municipality' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
        ]);
        $user->update($validated);
        return response()->json($user);
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/my-bookings', [LeadController::class, 'clientLeads']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/subcategories', [SubcategoryController::class, 'index']);
    Route::get('/subcategories/{subcategory}', [SubcategoryController::class, 'show']);

    // Favoritos (wishlist)
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::get('/favorites/status', [FavoriteController::class, 'status']);
    Route::post('/favorites', [FavoriteController::class, 'toggle']);

    // Direcciones de envío
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{address}', [AddressController::class, 'update']);
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

    // Reseñas (crear)
    Route::post('/reviews', [ReviewController::class, 'store']);

    // Pedidos del cliente
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/my-orders', [OrderController::class, 'myOrders']);

    Route::middleware(['admin'])->group(function () {

        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::post('/products/{product}', [ProductController::class, 'update']); // Para FormData con archivos
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::post('/services', [AdditionalServiceController::class, 'store']);
        Route::put('/services/{service}', [AdditionalServiceController::class, 'update']);
        Route::delete('/services/{service}', [AdditionalServiceController::class, 'destroy']);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/subcategories', [SubcategoryController::class, 'store']);
        Route::put('/subcategories/{subcategory}', [SubcategoryController::class, 'update']);
        Route::delete('/subcategories/{subcategory}', [SubcategoryController::class, 'destroy']);

        Route::get('/stats', [StatsController::class, 'index']);

        Route::put('/settings/{key}', [SettingController::class, 'update']);

        Route::get('/leads', [LeadController::class, 'index']);
        Route::put('/leads/{lead}', [LeadController::class, 'update']);
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        // Marcas
        Route::get('/brands', [BrandController::class, 'index']);
        Route::get('/brands/{brand}', [BrandController::class, 'show']);
        Route::post('/brands', [BrandController::class, 'store']);
        Route::put('/brands/{brand}', [BrandController::class, 'update']);
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);

        // Tallas y colores de productos
        Route::get('/product-sizes', [ProductSizeController::class, 'index']);
        Route::post('/product-sizes', [ProductSizeController::class, 'store']);
        Route::put('/product-sizes/{productSize}', [ProductSizeController::class, 'update']);
        Route::delete('/product-sizes/{productSize}', [ProductSizeController::class, 'destroy']);

        Route::get('/product-colors', [ProductColorController::class, 'index']);
        Route::post('/product-colors', [ProductColorController::class, 'store']);
        Route::put('/product-colors/{productColor}', [ProductColorController::class, 'update']);
        Route::delete('/product-colors/{productColor}', [ProductColorController::class, 'destroy']);

        // Reseñas (moderación)
        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::put('/reviews/{review}', [ReviewController::class, 'update']);
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

        // Cupones
        Route::get('/coupons', [CouponController::class, 'index']);
        Route::post('/coupons', [CouponController::class, 'store']);
        Route::put('/coupons/{coupon}', [CouponController::class, 'update']);
        Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy']);

        // Banners
        Route::get('/banners', [BannerController::class, 'index']);
        Route::post('/banners', [BannerController::class, 'store']);
        Route::put('/banners/{banner}', [BannerController::class, 'update']);
        Route::delete('/banners/{banner}', [BannerController::class, 'destroy']);

        // Métodos de envío
        Route::get('/shipping-methods', [ShippingMethodController::class, 'index']);
        Route::post('/shipping-methods', [ShippingMethodController::class, 'store']);
        Route::put('/shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'update']);
        Route::delete('/shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'destroy']);

        // Pedidos (gestión)
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    });
});

