<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $table = 'coupons';

    protected $fillable = [
        'code',
        'type',
        'value',
        'min_amount',
        'starts_at',
        'expires_at',
        'max_uses',
        'used',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'used' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Verificar si el cupón es válido (activo, vigente y con usos disponibles)
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->starts_at && now()->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && now()->gt($this->expires_at)) {
            return false;
        }

        if ($this->max_uses && $this->used >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Calcular el descuento sobre un monto
     */
    public function applyDiscount($amount): float
    {
        if (!$this->isValid() || (float) $this->min_amount > (float) $amount) {
            return 0;
        }

        if ($this->type === 'fixed') {
            return min((float) $this->value, (float) $amount);
        }

        return round(((float) $amount * (float) $this->value) / 100, 2);
    }
}