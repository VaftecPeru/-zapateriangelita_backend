<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetAudit extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'admin_id', 'action', 'ip_address'];
}
