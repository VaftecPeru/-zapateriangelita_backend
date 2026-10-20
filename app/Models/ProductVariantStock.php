<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariantStock extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'color', 'size', 'stock', 'article_model', 'sku'];

    protected $casts = ['stock' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
