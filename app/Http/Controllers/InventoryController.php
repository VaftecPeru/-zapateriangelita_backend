<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ProductVariantStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    private const LOW_STOCK_THRESHOLD = 5;

    public function index(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $products = Product::with([
            'brand:id,name',
            'category:id,name',
            'sizes',
            'variantStocks',
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
                        'stock_minimum' => self::LOW_STOCK_THRESHOLD,
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
                        'stock_minimum' => self::LOW_STOCK_THRESHOLD,
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
                'stock_minimum' => self::LOW_STOCK_THRESHOLD,
                'status' => $this->stockStatus((int) $product->stock),
                'source' => 'product',
            ];
        }

        $collection = collect($items);

        return response()->json([
            'summary' => [
                'total_stock' => (int) Product::sum('stock'),
                'available' => $collection->where('stock', '>', self::LOW_STOCK_THRESHOLD)->count(),
                'low_stock' => $collection->whereBetween('stock', [1, self::LOW_STOCK_THRESHOLD])->count(),
                'out_of_stock' => $collection->where('stock', '<=', 0)->count(),
            ],
            'items' => $items,
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variant_stocks,id'],
            'color' => ['nullable', 'string', 'max:50'],
            'size' => ['nullable', 'string', 'max:10'],
            'type' => ['required', 'in:entrada,salida,ajuste'],
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (
            in_array($validated['type'], ['entrada', 'salida'], true)
            && (int) $validated['quantity'] < 1
        ) {
            return response()->json(['message' => 'La cantidad debe ser mayor a cero.'], 422);
        }

        if (
            $validated['type'] === 'ajuste'
            && trim((string) ($validated['reason'] ?? '')) === ''
        ) {
            return response()->json([
                'message' => 'Indica el motivo del ajuste manual para conservar la trazabilidad.',
            ], 422);
        }

        try {
            $movement = DB::transaction(function () use ($validated, $request) {
                $product = Product::whereKey($validated['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $color = trim((string) ($validated['color'] ?? ''));
                $size = trim((string) ($validated['size'] ?? ''));
                $variantId = isset($validated['variant_id']) ? (int) $validated['variant_id'] : null;
                $previousStock = 0;
                $newStock = 0;
                $movementVariantId = null;

                if ($variantId !== null || ($color !== '' && $size !== '')) {
                    $variantQuery = ProductVariantStock::where('product_id', $product->id);

                    if ($variantId !== null) {
                        $variantQuery->whereKey($variantId);
                    } else {
                        $variantQuery
                            ->where('color', $color)
                            ->where('size', $size);
                    }

                    $variant = $variantQuery->lockForUpdate()->first();

                    if (!$variant) {
                        abort(422, 'La variante seleccionada no existe para este producto.');
                    }

                    $movementVariantId = $variant->id;
                    $color = (string) $variant->color;
                    $size = (string) $variant->size;
                    $previousStock = (int) $variant->stock;
                    $newStock = $this->calculateStock(
                        $previousStock,
                        $validated['type'],
                        (int) $validated['quantity'],
                    );

                    $variant->stock = $newStock;
                    $variant->save();

                    $sizeStock = (int) ProductVariantStock::where('product_id', $product->id)
                        ->where('size', $size)
                        ->sum('stock');

                    ProductSize::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'size' => $size,
                        ],
                        [
                            'stock' => $sizeStock,
                        ],
                    );

                    $product->stock = (int) ProductVariantStock::where('product_id', $product->id)
                        ->sum('stock');
                    $product->save();
                } elseif ($size !== '') {
                    if (ProductVariantStock::where('product_id', $product->id)->exists()) {
                        abort(422, 'Este producto maneja stock por color y talla. Selecciona una variante.');
                    }

                    $sizeRow = ProductSize::where('product_id', $product->id)
                        ->where('size', $size)
                        ->lockForUpdate()
                        ->first();

                    if (!$sizeRow) {
                        abort(422, 'La talla seleccionada no existe para este producto.');
                    }

                    $previousStock = (int) $sizeRow->stock;
                    $newStock = $this->calculateStock(
                        $previousStock,
                        $validated['type'],
                        (int) $validated['quantity'],
                    );

                    $sizeRow->stock = $newStock;
                    $sizeRow->save();

                    $product->stock = (int) ProductSize::where('product_id', $product->id)->sum('stock');
                    $product->save();
                } else {
                    if (ProductVariantStock::where('product_id', $product->id)->exists()) {
                        abort(422, 'Este producto maneja variantes. Debes seleccionar color y talla.');
                    }

                    if (ProductSize::where('product_id', $product->id)->exists()) {
                        abort(422, 'Este producto maneja stock por talla. Debes seleccionar una talla.');
                    }

                    $previousStock = (int) $product->stock;
                    $newStock = $this->calculateStock(
                        $previousStock,
                        $validated['type'],
                        (int) $validated['quantity'],
                    );

                    $product->stock = $newStock;
                    $product->save();
                }

                return InventoryMovement::create([
                    'product_id' => $product->id,
                    'variant_id' => $movementVariantId,
                    'user_id' => $request->user()?->id,
                    'color' => $color !== '' ? $color : null,
                    'size' => $size !== '' ? $size : null,
                    'type' => $validated['type'],
                    'quantity_change' => $newStock - $previousStock,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'reason' => trim((string) ($validated['reason'] ?? '')) ?: null,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Inventario actualizado correctamente.',
                'movement' => $movement->load([
                    'product:id,name,product_code',
                    'user:id,name,email',
                ]),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('Inventory adjustment failed', [
                'product_id' => $validated['product_id'] ?? null,
                'user_id' => $request->user()?->id,
                'type' => $validated['type'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo actualizar el inventario.',
            ], 500);
        }
    }

    public function movements(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = InventoryMovement::with([
            'product:id,name,product_code,img',
            'user:id,name,email',
        ])->latest();

        if (!empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        return response()->json(
            $query->limit((int) ($validated['limit'] ?? 100))->get(),
        );
    }

    private function calculateStock(int $currentStock, string $type, int $quantity): int
    {
        if ($type === 'entrada') {
            return $currentStock + $quantity;
        }

        if ($type === 'salida') {
            if ($quantity > $currentStock) {
                abort(422, "Stock insuficiente. Disponible: {$currentStock}.");
            }

            return $currentStock - $quantity;
        }

        return $quantity;
    }

    private function stockStatus(int $stock): string
    {
        if ($stock <= 0) {
            return 'agotado';
        }

        if ($stock <= self::LOW_STOCK_THRESHOLD) {
            return 'stock_bajo';
        }

        return 'disponible';
    }

    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()?->role, ['admin', 'superadmin'], true);
    }
}
