<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subcategory extends Model
{
    use HasFactory;

    protected $table = 'subcategories';

    protected $fillable = [
        'category_id',
        'name',
        'talla',        // Solo para niños (Bebé, Preescolar, Niña, Niño)
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    /**
     * Categoría padre (Mujer, Hombre, Niños)
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Solo subcategorías activas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Ordenadas por orden
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }

    /**
     * Filtrar por talla (para niños)
     */
    public function scopeByTalla($query, $talla)
    {
        return $query->where('talla', $talla);
    }
}