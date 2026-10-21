<?php

namespace App\Services;

use App\Models\Product;

/**
 * Fuente de verdad para importes cobrados por pedidos:
 * la selección de color NO confía en el precio proporcionado por el navegador.
 */
class ProductColorPricingService
{
    public function basePrice(Product $product, ?string $color): float
    {
        $fallback = (float) $product->price;
        if (!$color) {
            return $fallback;
        }
        $price = $product->colors()
            ->whereRaw('LOWER(color) = ?', [mb_strtolower(trim($color))])
            ->value('price');
        return $price === null ? $fallback : (float) $price;
    }

    public function salePrice(Product $product, ?string $color): float
    {
        $base = $this->basePrice($product, $color);
        $discount = max(0, min(100, (float) ($product->discount ?? 0)));
        if ($discount > 0) {
            return round($base * (1 - $discount / 100), 2);
        }
        // Respetar precios rebajados antiguos solo para el precio general.
        if (!$color || !$product->colors()
            ->whereRaw('LOWER(color) = ?', [mb_strtolower(trim($color))])
            ->whereNotNull('price')->exists()) {
            return (float) ($product->discounted_price ?? $base);
        }
        return $base;
    }
}
