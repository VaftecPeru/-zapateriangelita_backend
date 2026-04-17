<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'item_id', 'first_name', 'last_name', 'phone', 'check_in', 'check_out', 'guests', 'property_title', 'email', 'user_id', 'additional_services',
        'property_id',
    ];

    protected $casts = [
        'additional_services' => 'array',
        'item_id' => 'integer',
        'guests' => 'integer',
        'user_id' => 'integer',
        'property_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }
}

