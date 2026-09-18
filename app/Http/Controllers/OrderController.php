<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Coupon;
use App\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Crear un pedido desde el carrito (usuario autenticado)
     * POST /api/orders
     *
     * Body:
     * {
     *   "items": [{ "product_id": 1, "size": "38", "color": "Negro", "quantity": 2 }],
     *   "coupon_code": "BIENVENIDO10" (opcional),
     *   "shipping_method_id": 1 (opcional),
     *   "customer_name": "...", "shipping_address": "...", "shipping_phone": "...",
     *   "shipping_city": "...", "payment_method": "yape/plin/tarjeta", "notes": "..."
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.size' => 'nullable|string|max:10',
            'items.*.color' => 'nullable|string|max:50',
            'items.*.quantity' => 'required|integer|min:1',
            'coupon_code' => 'nullable|string|max:30',
            'shipping_method_id' => 'nullable|integer|exists:shipping_methods,id',
            'customer_name' => 'nullable|string|max:150',
            'shipping_address' => 'nullable|string',
            'shipping_city' => 'nullable|string|max:100',
            'shipping_country' => 'nullable|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_municipality' => 'nullable|string|max:100',
            'shipping_postal_code' => 'nullable|string|max:20',
            'shipping_phone' => 'nullable|string|max:20',
            'payment_method' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        try {
            DB::beginTransaction();

            $subtotal = 0;
            $orderItems = [];
            $coupon = null;
foreach ($validated['items'] as $item) {
                $product = Product::with('sizes')->find($item['product_id']);

                if (!$product) {
                    DB::rollBack();
                    return response()->json(['error' => 'Producto no encontrado.'], 404);
                }

                $quantity = $item['quantity'];
                $stock = $product->stock ?? 0;
                $price = $product->discounted_price ?? $product->price ?? 0;

                // Validar stock por talla si se especificó
                if (!empty($item['size'])) {
                    $colorSizes = $product->color_sizes ?? [];
                    if (!empty($item['color']) && !empty($colorSizes[$item['color']]) && !in_array($item['size'], $colorSizes[$item['color']], true)) {
                        DB::rollBack();
                        return response()->json([
                            'error' => "La talla '{$item['size']}' no está disponible para el color '{$item['color']}'."
                        ], 422);
                    }

                    $productSize = $product->sizes->firstWhere('size', $item['size']);
                    if (!$productSize) {
                        DB::rollBack();
                        return response()->json([
                            'error' => "El producto '{$product->name}' no tiene la talla '{$item['size']}'."
                        ], 422);
                    }
                    $stock = $productSize->stock;
                }

                if ($quantity > $stock) {
                    DB::rollBack();
                    return response()->json([
                        'error' => "Stock insuficiente para '{$product->name}'" . (!empty($item['size']) ? " (talla {$item['size']})" : '') . ". Disponible: {$stock}."
                    ], 422);
                }

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ];

                $subtotal += $price * $quantity;
            }

            // Descuento por cupón
            $discount = 0;
            if (!empty($validated['coupon_code'])) {
                $coupon = Coupon::where('code', strtoupper($validated['coupon_code']))->first();
                if (!$coupon || !$coupon->isValid()) {
                    DB::rollBack();
                    return response()->json(['error' => 'El cupón no es válido o está vencido.'], 422);
                }
                $discount = $coupon->applyDiscount($subtotal);
            }

            // Costo de envío
            $shippingCost = 0;
            if (!empty($validated['shipping_method_id'])) {
                $shippingMethod = ShippingMethod::find($validated['shipping_method_id']);
                if ($shippingMethod) {
                    $shippingCost = (float) $shippingMethod->cost;
                }
            }

            // Código único de pedido
            $code = $this->generateOrderCode();

            $order = Order::create([
                'user_id' => $user?->id,
                'code' => $code,
                'status' => 'sin_preparar',
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'shipping_cost' => round($shippingCost, 2),
                'total' => round($subtotal - $discount + $shippingCost, 2),
                'payment_method' => $validated['payment_method'] ?? null,
                'shipping_address' => $validated['shipping_address'] ?? null,
                'shipping_city' => $validated['shipping_city'] ?? null,
                'shipping_country' => $validated['shipping_country'] ?? null,
                'shipping_state' => $validated['shipping_state'] ?? null,
                'shipping_municipality' => $validated['shipping_municipality'] ?? null,
                'shipping_postal_code' => $validated['shipping_postal_code'] ?? null,
                'shipping_phone' => $validated['shipping_phone'] ?? null,
                'customer_name' => $validated['customer_name'] ?? $user?->name,
                'customer_email' => $validated['customer_email'] ?? $user?->email,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($orderItems as $item) {
                OrderItem::create(array_merge($item, ['order_id' => $order->id]));

                // Mantener sincronizado el stock maestro y el stock por talla.
                $product = Product::find($item['product_id']);
                if ($product) {
                    $product->decrement('stock', $item['quantity']);

                    if (!empty($item['size'])) {
                        $productSize = $product->sizes()->where('size', $item['size'])->first();
                        if ($productSize) {
                            $productSize->decrement('stock', $item['quantity']);
                        }
                    }
                }
            }

            // Incrementar usos del cupón
            if (!empty($validated['coupon_code']) && isset($coupon)) {
                $coupon->increment('used');
            }

            DB::commit();

            $order->load('items', 'items.product');

            return response()->json($order, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al crear el pedido: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateEmail(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'customer_email' => 'required|email|max:255',
        ]);

        $order->update(['customer_email' => $validated['customer_email']]);

        return response()->json($order);
    }
/**
     * Pedidos del usuario autenticado
     * GET /api/my-orders
     */
    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with('items', 'items.product')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }

    /**
     * Listar todos los pedidos (admin)
     * GET /api/orders
    * GET /api/orders?status=sin_preparar
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with('user:id,name,email', 'items', 'items.product');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(15));
    }

    /**
     * Mostrar un pedido (admin)
     * GET /api/orders/{order}
     */
    public function show(Order $order): JsonResponse
    {
        $order->load('user:id,name,email,phone', 'items', 'items.product');
        return response()->json($order);
    }

    /**
     * Actualizar el estado de un pedido (admin)
     * PUT /api/orders/{order}/status
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:sin_preparar,preparado,enviado,entregado,cancelado',
        ]);

        $order->update(['status' => $validated['status']]);
        return response()->json($order);
    }

    /**
     * Generar un código de pedido único tipo ZG-AAAA-MM-DD-XXXX
     */
    private function generateOrderCode(): string
    {
        do {
            $code = 'ZG-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        } while (Order::where('code', $code)->exists());

        return $code;
    }
}