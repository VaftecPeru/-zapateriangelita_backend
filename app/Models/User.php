<?php

namespace App\Models;

use App\Mail\PasswordResetLinkMail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'temporary_password_set_at',
        'gender',
        'birthdate',
        'role',
        'phone',
        'google_sub',
        'google_email',
        'google_linked_at',
        'welcome_email_method',
        'welcome_email_sent_at',
        'welcome_email_attempts',
        'welcome_email_next_attempt_at',
        'welcome_email_last_error',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_sub',
        'welcome_email_method',
        'welcome_email_sent_at',
        'welcome_email_attempts',
        'welcome_email_next_attempt_at',
        'welcome_email_last_error',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'must_change_password' => 'boolean',
        'temporary_password_set_at' => 'datetime',
        'birthdate' => 'date',
        'google_linked_at' => 'datetime',
        'welcome_email_sent_at' => 'datetime',
        'welcome_email_attempts' => 'integer',
        'welcome_email_next_attempt_at' => 'datetime',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function sendPasswordResetNotification($token): void
    {
        try {
            Mail::to($this->email)->send(new PasswordResetLinkMail($this, (string) $token));
        } catch (\Throwable $e) {
            Log::error('PASSWORD_RESET_EMAIL_FAILED', [
                'user_id' => $this->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
