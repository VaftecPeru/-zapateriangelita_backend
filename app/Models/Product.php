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

    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discounted_price' => 'decimal:2',
        'stock' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
        'images' => 'array',
    ];
}