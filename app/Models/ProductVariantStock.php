<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariantStock extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'color', 'size', 'stock'];

    protected $casts = ['stock' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
