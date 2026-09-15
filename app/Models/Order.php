<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'user_id',
        'code',
        'status',
        'subtotal',
        'discount',
        'shipping_cost',
        'total',
        'payment_method',
        'shipping_address',
        'shipping_city',
        'shipping_country',
        'shipping_state',
        'shipping_municipality',
        'shipping_postal_code',
        'shipping_phone',
        'customer_name',
        'customer_email',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * Mantiene compatibilidad con estados históricos en inglés mientras
     * el flujo actual de pedidos trabaja con estados en español.
     */
    public function setStatusAttribute($value): void
    {
        $statusMap = [
            'pending' => 'sin_preparar',
            'confirmed' => 'preparado',
            'shipped' => 'enviado',
            'delivered' => 'entregado',
            'cancelled' => 'cancelado',
        ];

        $this->attributes['status'] = $statusMap[$value] ?? $value;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Recalcular subtotal y total en base a los items
     */
    public function recalculateTotals(): void
    {
        $subtotal = (float) DB::table('order_items')
            ->where('order_id', $this->id)
            ->selectRaw('SUM(quantity * unit_price) as total')
            ->value('total') ?? 0;

        $this->subtotal = round($subtotal, 2);
        $this->total = round($subtotal - (float) $this->discount + (float) $this->shipping_cost, 2);
        $this->save();
    }
}
