<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $fillable = [
        'product_code',
        'name',
        'brand_id',
        'price',
        'discounted_price',
        'stock',
        'size',
        'color',
        'material',
        'rating',
        'reviews',
        'img',
        'images',
        'color_images',
        'color_sizes',
        'description',
        'discount',
        'status',
        'category_id',
        'subcategory_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discounted_price' => 'decimal:2',
        'stock' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
        'images' => 'array',
        'color_images' => 'array',
        'color_sizes' => 'array',
    ];

    
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
        return 'S/ ' . number_format($this->price, 2);
    }

    
    public function getFormattedDiscountedPrice(): ?string
    {
        if (!$this->isOnSale()) {
            return null;
        }
        return 'S/ ' . number_format($this->discounted_price, 2);
    }
}