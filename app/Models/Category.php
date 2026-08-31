<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $table = 'categories';

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'image',
        'description',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    // ============================================
    // ACCESORES & MUTADORES
    // ============================================

    // Generar slug automáticamente
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;
        $this->attributes['slug'] = Str::slug($value);
    }

    // ============================================
    // RELACIONES
    // ============================================

    // Productos que pertenecen a esta categoría
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    // Subcategorías que pertenecen a esta categoría
    public function subcategories()
    {
        return $this->hasMany(Subcategory::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    // Solo categorías activas
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Ordenadas
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }

    // ============================================
    // MÉTODOS HELPER
    // ============================================

    public function hasProducts()
    {
        return $this->products()->count() > 0;
    }

    public function getProductCountAttribute()
    {
        return $this->products()->count();
    }
}