<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $fillable = [
        'name',
        'category',
        'brand',
        'brand_id',      // ✅ Marca normalizada (FK a brands)
        'price',
        'discounted_price',
        'stock',
        'size',
        'color',
        'material',
        'gender',
        'rating',
        'reviews',
        'img',
        'images',
        'description',
        'discount',
        'category_id',  // ✅ Agregar clave foránea
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discounted_price' => 'decimal:2',
        'stock' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
        'images' => 'array',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    /**
     * Relación con la categoría (Category)
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relación con la marca (Brand)
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Tallas y stock por talla
     */
    public function sizes()
    {
        return $this->hasMany(ProductSize::class);
    }

    /**
     * Colores disponibles
     */
    public function colors()
    {
        return $this->hasMany(ProductColor::class);
    }

    /**
     * Reseñas del producto
     */
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Favoritos (usuarios que lo marcaron)
     */
    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Filtrar productos por categoría
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Filtrar productos por marca
     */
    public function scopeByBrand($query, $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    /**
     * Filtrar productos por talla (a través de product_sizes)
     */
    public function scopeBySize($query, $size)
    {
        return $query->whereHas('sizes', function ($q) use ($size) {
            $q->where('size', $size)->where('stock', '>', 0);
        });
    }

    /**
     * Filtrar productos en stock
     */
    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    /**
     * Filtrar productos en oferta
     */
    public function scopeOnSale($query)
    {
        return $query->whereNotNull('discounted_price')->where('discounted_price', '>', 0);
    }

    /**
     * Filtrar productos por género
     */
    public function scopeByGender($query, $gender)
    {
        return $query->where('gender', $gender);
    }

    // ============================================
    // MÉTODOS HELPER
    // ============================================

    /**
     * Verificar si el producto está en oferta
     */
    public function isOnSale(): bool
    {
        return $this->discounted_price !== null && $this->discounted_price > 0;
    }

    /**
     * Calcular porcentaje de descuento
     */
    public function getDiscountPercentage(): ?float
    {
        if (!$this->isOnSale() || $this->price <= 0) {
            return null;
        }
        return round((($this->price - $this->discounted_price) / $this->price) * 100, 0);
    }

    /**
     * Obtener precio formateado
     */
    public function getFormattedPrice(): string
    {
        return 'S/ ' . number_format($this->price, 2);
    }

    /**
     * Obtener precio con descuento formateado
     */
    public function getFormattedDiscountedPrice(): ?string
    {
        if (!$this->isOnSale()) {
            return null;
        }
        return 'S/ ' . number_format($this->discounted_price, 2);
    }
}