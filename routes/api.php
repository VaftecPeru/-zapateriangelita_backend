<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MailHealthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AdditionalServiceController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SubcategoryController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ProductSizeController;
use App\Http\Controllers\ProductColorController;
use App\Http\Controllers\Api\OpenpayController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InventoryExportController;

// Diagnóstico mínimo para cPanel. No usa throttle ni expone secretos.
Route::get('/health', HealthController::class);

// Autenticación
Route::middleware('throttle:60,1')
    ->post('/register', [AuthController::class, 'register']);

Route::middleware('login.throttle')
    ->post('/login', [AuthController::class, 'login']);

// Google Identity Services. El client_id es público y se entrega al frontend en tiempo de ejecución.
// Este endpoint de solo lectura no usa throttle por IP para evitar falsos 429 detrás de proxies/hosting compartido.
Route::get('/auth/google/config', [GoogleAuthController::class, 'config']);

Route::middleware('throttle:20,1')
    ->post('/auth/google/login', [GoogleAuthController::class, 'login']);

Route::middleware('throttle:10,1')
    ->post('/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('throttle:10,1')
    ->post('/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/login', function () {
    return response()->json([
        'message' => 'Unauthenticated.',
    ], 401);
})->name('login');

// Imágenes persistentes de catálogo. No depende de public/storage ni de symlinks de cPanel.
Route::middleware('throttle:240,1')
    ->get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*');

// Rutas públicas
Route::middleware('throttle:100,1')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    Route::get('/services', [AdditionalServiceController::class, 'index']);
    Route::get('/checkout/delivery-cost', [AdditionalServiceController::class, 'delivery']);

    Route::post('/leads', [LeadController::class, 'store']);

    Route::get('/settings', [SettingController::class, 'index']);
    Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe']);
    Route::post('/interactions', [InteractionController::class, 'store']);

    Route::get('/public/categories', [
        CategoryController::class,
        'publicIndex',
    ]);

    Route::get('/public/subcategories', [
        SubcategoryController::class,
        'publicIndex',
    ]);
});

// Openpay: configuración pública.
Route::get('/openpay/config', [
    OpenpayController::class,
    'config',
])->middleware('throttle:30,1');

// Openpay: recepción de notificaciones del proveedor.
Route::post('/openpay/webhook', [
    OpenpayController::class,
    'webhook',
])->name('openpay.webhook');

// Checkout de tienda. Se permite comprar como invitado; cada pedido emite un
// token cifrado y temporal que autoriza únicamente su propio flujo de pago.
Route::middleware('throttle:checkout')->group(function () {
    Route::post('/checkout/orders', [CheckoutController::class, 'createOrder']);
    Route::post('/checkout/payments/openpay/charge', [CheckoutController::class, 'charge']);
    Route::get('/checkout/payments/openpay/verify', [CheckoutController::class, 'verify']);
});

// Callbacks internos del microservicio Spring Boot. Nunca deben exponerse sin
// firma HMAC; el microservicio no escribe directamente en la base Laravel.
Route::prefix('/internal/payment')
    ->middleware('payment.service')
    ->group(function () {
        Route::post('/orders/{order}/complete', [CheckoutController::class, 'completeFromPaymentService']);
        Route::post('/orders/{order}/fail', [CheckoutController::class, 'failFromPaymentService']);
    });

// Rutas para usuarios autenticados
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/user/update', function (Request $request) {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[\pL]+(?:[\s\'-][\pL]+)*$/u'],
            'birthdate' => 'nullable|date',
            'gender' => 'nullable|string|max:50',
            'state' => 'nullable|string|max:100',
            'municipality' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'phone' => ['nullable', 'regex:/^\\+?[0-9]{7,20}$/'],
        ]);

        $user->update($validated);

        return response()->json($user);
    });

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/user/change-password', [UserController::class, 'changePassword']);
    Route::post('/user/change-temporary-password', [UserController::class, 'changeTemporaryPassword']);

    // Vinculación explícita de Google desde una sesión ya autenticada.
    Route::middleware('throttle:20,1')
        ->post('/auth/google/link', [GoogleAuthController::class, 'link']);

    // El correo de bienvenida se solicita después de crear la sesión para no
    // bloquear el registro Google mientras responde el servidor SMTP.
    Route::middleware('throttle:3,1')
        ->post('/auth/google/welcome', [GoogleAuthController::class, 'sendWelcome']);

    Route::get('/my-bookings', [LeadController::class, 'clientLeads']);

    // Categorías y subcategorías
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);

    Route::get('/subcategories', [SubcategoryController::class, 'index']);
    Route::get('/subcategories/{subcategory}', [
        SubcategoryController::class,
        'show',
    ]);

    // Favoritos
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::get('/favorites/status', [FavoriteController::class, 'status']);
    Route::post('/favorites', [FavoriteController::class, 'toggle']);

    // Direcciones de envío
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{address}', [AddressController::class, 'update']);
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

    // Reseñas
    Route::post('/reviews', [ReviewController::class, 'store']);

    // Pedidos del cliente. Se mantienen por compatibilidad con clientes ya autenticados.
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/my-orders', [OrderController::class, 'myOrders']);

    // Pagos autenticados históricos. El checkout nuevo utiliza /checkout/payments/...
    Route::post('/payments/openpay/charge', [
        OpenpayController::class,
        'charge',
    ]);

    Route::get('/payments/openpay/verify', [
        OpenpayController::class,
        'verify',
    ]);

    // Administración
    Route::middleware('admin')->group(function () {
        // Productos
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);

        // Actualización mediante FormData con archivos
        Route::post('/products/{product}', [ProductController::class, 'update']);

        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        // Exportación profesional del inventario actual (respeta filtros del catálogo)
        Route::get('/inventory/export/excel', [InventoryExportController::class, 'excel']);
        Route::get('/inventory/export/pdf', [InventoryExportController::class, 'pdf']);

        // Servicios
        Route::post('/services', [AdditionalServiceController::class, 'store']);
        Route::put('/services/delivery', [AdditionalServiceController::class, 'updateDelivery']);
        Route::put('/services/{service}', [
            AdditionalServiceController::class,
            'update',
        ]);
        Route::delete('/services/{service}', [
            AdditionalServiceController::class,
            'destroy',
        ]);

        // Categorías
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [
            CategoryController::class,
            'destroy',
        ]);

        // Subcategorías
        Route::post('/subcategories', [SubcategoryController::class, 'store']);
        Route::put('/subcategories/{subcategory}', [
            SubcategoryController::class,
            'update',
        ]);
        Route::delete('/subcategories/{subcategory}', [
            SubcategoryController::class,
            'destroy',
        ]);

        // Estadísticas, configuración y mantenimiento de media
        Route::get('/stats', [StatsController::class, 'index']);
        Route::put('/settings/{key}', [SettingController::class, 'update']);
        Route::post('/maintenance/media/migrate', [MediaController::class, 'migrateReferencedMedia']);

        // Leads
        Route::get('/leads', [LeadController::class, 'index']);
        Route::put('/leads/{lead}', [LeadController::class, 'update']);
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

        // Usuarios
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
        Route::put('/users/{id}/role', [UserController::class, 'updateRole']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        // Tallas
        Route::get('/product-sizes', [ProductSizeController::class, 'index']);
        Route::post('/product-sizes', [ProductSizeController::class, 'store']);
        Route::put('/product-sizes/{productSize}', [
            ProductSizeController::class,
            'update',
        ]);
        Route::delete('/product-sizes/{productSize}', [
            ProductSizeController::class,
            'destroy',
        ]);

        // Colores
        Route::get('/product-colors', [ProductColorController::class, 'index']);
        Route::post('/product-colors', [ProductColorController::class, 'store']);
        Route::put('/product-colors/{productColor}', [
            ProductColorController::class,
            'update',
        ]);
        Route::delete('/product-colors/{productColor}', [
            ProductColorController::class,
            'destroy',
        ]);

        // Moderación de reseñas
        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::put('/reviews/{review}', [ReviewController::class, 'update']);
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

        // Salud de correo transaccional (sin exponer secretos)
        Route::get('/mail/health', [MailHealthController::class, 'show']);

        // Gestión de pedidos
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders/reconcile-payments', [OrderController::class, 'reconcilePayments']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::put('/orders/{order}/status', [
            OrderController::class,
            'updateStatus',
        ]);
        Route::post('/orders/{order}/notifications/retry', [
            OrderController::class,
            'retryNotifications',
        ]);
    });
});
