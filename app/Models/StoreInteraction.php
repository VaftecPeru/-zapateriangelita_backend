<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreInteraction extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'product_id', 'type', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}
