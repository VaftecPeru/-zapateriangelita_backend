<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'title',
        'location',
        'beds',
        'baths',
        'area',
        'price',
        'discounted_price',
        'rating',
        'reviews',
        'img',
        'images',
        'amenities',
    ];


    protected $casts = [
        'beds' => 'integer',
        'baths' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
        'images' => 'array',
    ];
}
