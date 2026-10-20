<?php

namespace App\Services;

use App\Models\Product;

class ProductPublicationService
{
    /**
     * El producto puede publicarse solo con datos comerciales verificables.
     * Las imágenes son opcionales: la publicación se decide por precio,
     * stock, datos del modelo y catálogos activos.
     */
    public function isReady(Product $product): bool
    {
        $isSimple = $product->product_type === 'simple';
        if ((float) $product->price <= 0
            || (int) $product->stock <= 0
            || trim((string) $product->product_code) === ''
            || trim((string) $product->name) === ''
            || trim((string) $product->model) === ''
            || (!$isSimple && trim((string) $product->size) === '')
            || (!$isSimple && trim((string) $product->color) === '')
            || !$product->brand_id
            || !$product->category_id
        ) {
            return false;
        }

        // Los productos simples requieren alta explícita y no pueden conservar
        // variantes al pasar a stock maestro. Nunca cambia productos heredados.
        if ($isSimple && (
            trim((string) $product->size) !== ''
            || trim((string) $product->color) !== ''
            || $product->variantStocks()->exists()
        )) {
            return false;
        }

        // Las unidades importadas sin talla/color asignados no son vendibles.
        // Solo se aplica al inventario importado mediante V3 para mantener
        // compatibilidad con productos históricos que no usan variantes.
        if ($product->import_pending_data !== null
            && (int) $product->variantStocks()->sum('stock') !== (int) $product->stock
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
