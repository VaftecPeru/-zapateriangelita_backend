<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'user_id',
        'color',
        'size',
        'type',
        'quantity_change',
        'previous_stock',
        'new_stock',
        'reason',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'previous_stock' => 'integer',
        'new_stock' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariantStock::class, 'variant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
