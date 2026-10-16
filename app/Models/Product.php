<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $fillable = [
        'product_code',
        'name',
        'model',
        'brand_id',
        'price',
        'discounted_price',
        'stock',
        'size',
        'color',
        'material',
        'img',
        'images',
        'color_images',
        'color_sizes',
        'description',
        'discount',
        'status',
        'is_active',
        'auto_publish',
        'category_id',
        'subcategory_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discounted_price' => 'decimal:2',
        'stock' => 'integer',
        'images' => 'array',
        'color_images' => 'array',
        'color_sizes' => 'array',
        'is_active' => 'boolean',
        'auto_publish' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            $table = $product->getTable();
            $attributes = $product->getAttributes();

            // La BD histórica de producción conserva rating/reviews como NOT NULL
            // sin valor por defecto. Los flujos actuales ya no los capturan, así que
            // mantenemos compatibilidad hasta que la migración normalice el esquema.
            // Usamos el arreglo crudo porque `reviews` también es una relación Eloquent.
            if (Schema::hasColumn($table, 'rating') && !array_key_exists('rating', $attributes)) {
                $product->setAttribute('rating', 0);
            }

            if (Schema::hasColumn($table, 'reviews') && !array_key_exists('reviews', $attributes)) {
                $product->setAttribute('reviews', 0);
            }
        });
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

   
    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

   
    public function sizes()
    {
        return $this->hasMany(ProductSize::class);
    }

    
    public function colors()
    {
        return $this->hasMany(ProductColor::class);
    }

    public function variantStocks()
    {
        return $this->hasMany(ProductVariantStock::class);
    }

    
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    
    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    
    
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    
    public function scopeBySize($query, $size)
    {
        return $query->whereHas('sizes', function ($q) use ($size) {
            $q->where('size', $size)->where('stock', '>', 0);
        });
    }

   
    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    
    public function scopeOnSale($query)
    {
        return $query->whereNotNull('discounted_price')->where('discounted_price', '>', 0);
    }

    
    public function isOnSale(): bool
    {
        return $this->discounted_price !== null && $this->discounted_price > 0;
    }

    public function getDiscountPercentage(): ?float
    {
        if (!$this->isOnSale() || $this->price <= 0) {
            return null;
        }
        return round((($this->price - $this->discounted_price) / $this->price) * 100, 0);
    }

    public function getFormattedPrice(): string
    {
        return '$ ' . number_format($this->price, 2);
    }

    
    public function getFormattedDiscountedPrice(): ?string
    {
        if (!$this->isOnSale()) {
            return null;
        }
        return '$ ' . number_format($this->discounted_price, 2);
    }
}