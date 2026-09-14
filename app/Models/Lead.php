<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'item_id', 'email', 'first_name', 'last_name', 'phone',
        'product_interest', 'shoe_size', 'contact_preference', 'message',
        'property_title', 'additional_services', 'is_read',
    ];

    protected $casts = [
        'additional_services' => 'array',
        'is_read' => 'boolean',
    ];
}
