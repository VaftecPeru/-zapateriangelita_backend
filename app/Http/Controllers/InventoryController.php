<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([ 'message' => 'No autorizado.' ], 403);
        }

        $products = Product::with([
            'brand:id,name',
            'category:id,name',
            'sizes',
            'variantStocks'
        ])
            ->orderBy('name')
            ->get();
        $items = [];

        foreach ($products as $product) {
            if ($product->variantStocks->isNotEmpty()) {

                foreach ($product->variantStocks as $variant) {
                    $items[] = [
                        'product_id' => $product->id,
                        'variant_id' => $variant->id,
                        'product_code' => $product->product_code,
                        'name' => $product->name,
                        'image' => $product->img,
                        'brand' => $product->brand?->name,
                        'category' => $product->category?->name,
                        'color' => $variant->color,
                        'size' => $variant->size,
                        'stock' => (int) $variant->stock,
                        'stock_minimum' => 5,
                        'status' => $this->stockStatus((int) $variant->stock),
                        'source' => 'variant',
                    ];
                }
                continue;
            }

            if ($product->sizes->isNotEmpty()) {

                foreach ($product->sizes as $size) {
                    $items[] = [
                        'product_id' => $product->id,
                        'variant_id' => null,
                        'product_code' => $product->product_code,
                        'name' => $product->name,
                        'image' => $product->img,
                        'brand' => $product->brand?->name,
                        'category' => $product->category?->name,
                        'color' => null,
                        'size' => $size->size,
                        'stock' => (int) $size->stock,
                        'stock_minimum' => 5,
                        'status' => $this->stockStatus((int) $size->stock),
                        'source' => 'size',
                    ];
                }
                continue;
            }

            $items[] = [
                'product_id' => $product->id,
                'variant_id' => null,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'image' => $product->img,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'color' => null,
                'size' => null,
                'stock' => (int) $product->stock,
                'stock_minimum' => 5,
                'status' => $this->stockStatus((int) $product->stock),
                'source' => 'product',
            ];
        }

        $collection = collect($items);

        return response()->json([
            'summary' => [
                'total_stock' => (int) $collection->sum('stock'),
                'available' => $collection
                    ->where('stock', '>', 0)
                    ->count(),
                'low_stock' => $collection
                    ->whereBetween('stock', [1, 5])
                    ->count(),
                'out_of_stock' => $collection
                    ->where('stock', 0)
                    ->count(),
            ],
            'items' => $items,
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([ 'message' => 'No autorizado.' ], 403);
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'color' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:10',
            'type' => 'required|in:entrada,salida,ajuste',
            'quantity' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:255|required_if:type,ajuste',
        ]);

        if (
            in_array($validated['type'], ['entrada', 'salida'], true)
            && (int) $validated['quantity'] < 1
        ) {
            return response()->json([ 'message' => 'La cantidad debe ser mayor a cero.' ], 422);
        }

        try {

            $movement = DB::transaction(function () use ($validated, $request) {

                $product = Product::whereKey($validated['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $color = trim((string) ($validated['color'] ?? ''));
                $size = trim((string) ($validated['size'] ?? ''));
                $previousStock = 0;
                $newStock = 0;

                if ($color !== '' && $size !== '') {

                    $variant = ProductVariantStock::where(
                        'product_id',
                        $product->id
                    )
                        ->where('color', $color)
                        ->where('size', $size)
                        ->lockForUpdate()
                        ->first();

                    if (!$variant) {
                        abort( 422, 'La variante seleccionada no existe para este producto.' );
                    }

                    $previousStock = (int) $variant->stock;

                    $newStock = $this->calculateStock(
                        $previousStock,
                        $validated['type'],
                        (int) $validated['quantity']
                    );

                    $variant->stock = $newStock;
                    $variant->save();
                    $sizeStock = (int) ProductVariantStock::where(
                        'product_id',
                        $product->id
                    )
                        ->where('size', $size)
                        ->sum('stock');

                    ProductSize::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'size' => $size,
                        ],
                        [
                            'stock' => $sizeStock,
                        ]
                    );

                    $product->stock = (int) ProductVariantStock::where(
                        'product_id',
                        $product->id
                    )->sum('stock');

                    $product->save();
                }

                elseif ($size !== '') {

                    if (
                        ProductVariantStock::where(
                            'product_id',
                            $product->id
                        )->exists()
                    ) {
                        abort( 422, 'Este producto maneja stock por color y talla. Selecciona una variante.' );
                    }

                    $sizeRow = ProductSize::where(
                        'product_id',
                        $product->id
                    )
                        ->where('size', $size)
                        ->lockForUpdate()
                        ->first();

                    if (!$sizeRow) {
                        abort( 422, 'La talla seleccionada no existe para este producto.' );
                    }

                    $previousStock = (int) $sizeRow->stock;
                    $newStock = $this->calculateStock(
                        $previousStock,
                        $validated['type'],
                        (int) $validated['quantity']
                    );

                    $sizeRow->stock = $newStock;
                    $sizeRow->save();
                    $product->stock = (int) ProductSize::where(
                        'product_id',
                        $product->id
                    )->sum('stock');

                    $product->save();
                }
                else {

                    if (
                        ProductVariantStock::where( 'product_id', $product->id )->exists()
                    ) {
                        abort( 422, 'Este producto maneja variantes. Debes seleccionar color y talla.' );
                    }

                    if (
                        ProductSize::where(
                            'product_id',
                            $product->id
                        )->exists()
                    ) {
                        abort( 422, 'Este producto maneja stock por talla. Debes seleccionar una talla.' );
                    }

                    $previousStock = (int) $product->stock;
                    $newStock = $this->calculateStock(
                        $previousStock,
                        $validated['type'],
                        (int) $validated['quantity']
                    );
                    $product->stock = $newStock;
                    $product->save();
                }
                $quantityChange = $newStock - $previousStock;

                return InventoryMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()?->id,
                    'color' => $color !== '' ? $color : null,
                    'size' => $size !== '' ? $size : null,
                    'type' => $validated['type'],
                    'quantity_change' => $quantityChange,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'reason' => $validated['reason'] ?? null,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Inventario actualizado correctamente.',
                'movement' => $movement->load([
                    'product:id,name,product_code',
                    'user:id,name,email'
                ]),
            ]);

        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([ 'message' => $e->getMessage(), ], $e->getStatusCode());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo actualizar el inventario.',
            ], 500);
        }
    }

    public function movements(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json([ 'message' => 'No autorizado.' ], 403);
        }

        $query = InventoryMovement::with([ 'product:id,name,product_code,img', 'user:id,name,email' ])
            ->latest();

        if ($request->filled('product_id')) {
            $query->where( 'product_id', $request->integer('product_id') );
        }

        return response()->json(
            $query->limit(200)->get()
        );
    }

    private function calculateStock( int $currentStock, string $type, int $quantity
    ): int {
        if ($type === 'entrada') {
            return $currentStock + $quantity;
        }
        if ($type === 'salida') {
            if ($quantity > $currentStock) {
                abort( 422, "Stock insuficiente. Disponible: {$currentStock}." );
            }

            return $currentStock - $quantity;
        }

        return $quantity;
    }

    private function stockStatus(int $stock): string
    {
        if ($stock <= 0) { return 'agotado'; }
        if ($stock <= 5) { return 'stock_bajo'; }
        return 'disponible';
    }

   
    private function isAdmin(Request $request): bool
    {
        return in_array(
            $request->user()?->role, ['admin', 'superadmin'], true
        );
    }
}