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
        'rating',
        'reviews',
        'img',
    ];

    protected $casts = [
        'beds' => 'integer',
        'baths' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
