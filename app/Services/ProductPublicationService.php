<?php

namespace App\Services;

use App\Models\Product;

class ProductPublicationService
{
    /**
     * El producto puede publicarse solo con datos comerciales verificables.
     * Solo se publican productos completos: fotografía, precio,
     * variante vendible, identidad original y catálogos activos.
     */
    public function isReady(Product $product): bool
    {
        if ((float) $product->price <= 0
            || (int) $product->stock <= 0
            || trim((string) $product->product_code) === ''
            || trim((string) $product->name) === ''
            || trim((string) $product->model) === ''
            || trim((string) $product->size) === ''
            || trim((string) $product->color) === ''
            || !$product->brand_id
            || !$product->category_id
            || (trim((string) $product->img) === '' && empty($product->images))
        ) {
            return false;
        }

        if (!$product->brand()->where('is_active', true)->exists()
            || !$product->category()->where('is_active', true)->exists()
        ) {
            return false;
        }

        if ($product->subcategory_id
            && !$product->subcategory()->where('is_active', true)
                ->where('category_id', $product->category_id)->exists()
        ) {
            return false;
        }

        // Si el producto utiliza stock por variante, al menos una variante
        // debe tener existencias para ser publicable.
        if (!$product->variantStocks()->where('stock', '>', 0)->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Los catálogos históricos quedan intactos si auto_publish está deshabilitado.
     * El cambio no toca stock ni pedidos.
     */
    public function synchronize(Product $product): void
    {
        if (!$product->auto_publish) {
            return;
        }

        $ready = $this->isReady($product);
        if ((bool) $product->is_active !== $ready) {
            $product->is_active = $ready;
            $product->save();
        }
    }
}
