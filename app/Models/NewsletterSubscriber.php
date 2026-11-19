<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    use HasFactory;

    protected $fillable = ['email', 'status', 'subscribed_at', 'unsubscribed_at', 'welcome_due_at'];

    protected $casts = [
        'welcome_due_at' => 'datetime',
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];
}
