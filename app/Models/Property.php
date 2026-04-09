<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

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
        'description',
    ];

    protected $casts = [
        'beds' => 'integer',
        'baths' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
        'images' => 'array',
    ];

    /**
     * ✅ Accesors modernos para corregir URLs locales
     */
    protected function img(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (is_string($value) && str_contains($value, 'localhost:8000')) {
                    return str_replace('http://localhost:8000', config('app.url'), $value);
                }
                return $value;
            }
        );
    }

    protected function images(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $images = is_string($value) ? json_decode($value, true) : $value;
                if (!is_array($images)) return [];

                return array_map(function ($img) {
                    if (is_string($img) && str_contains($img, 'localhost:8000')) {
                        return str_replace('http://localhost:8000', config('app.url'), $img);
                    }
                    return $img;
                }, $images);
            }
        );
    }
}
