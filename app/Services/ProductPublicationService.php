<?php

namespace App\Services;

use App\Models\Product;

class ProductPublicationService
{
    /**
     * El producto puede publicarse solo con datos comerciales verificables.
     * Una referencia de imagen válida debe existir antes de marcarlo como visible.
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

        $images = is_array($product->images) ? $product->images : [];
        $hasImage = trim((string) $product->img) !== ''
            || collect($images)->contains(fn ($value) => is_string($value) && trim($value) !== '');
        if (!$hasImage) {
            return false;
        }

        // Si el producto utiliza stock por variante, al menos una variante
        // debe tener existencias para ser publicable.
        if ($product->variantStocks()->exists()
            && !$product->variantStocks()->where('stock', '>', 0)->exists()
        ) {
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
