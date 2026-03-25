<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'item_id', 'first_name', 'last_name', 'phone', 'check_in', 'check_out', 'guests', 'property_title'];
}

