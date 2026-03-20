<?php

namespace Database\Seeders;

use App\Models\Property;
use Illuminate\Database\Seeder;

class PropertySeeder extends Seeder
{
    
    public function run(): void
    {
        $properties = [
            [
                'type' => 'Apartamento',
                'status' => 'Disponible',
                'title' => 'Apartamento Moderno en el Centro',
                'location' => 'Centro, Ciudad Principal',
                'beds' => 2,
                'baths' => 2,
                'area' => '85m²',
                'price' => 'S/1,200',
                'rating' => 4.8,
                'reviews' => 124,
                'img' => 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&q=80&w=600',
            ],
            [
                'type' => 'Habitación',
                'status' => 'Disponible',
                'title' => 'Habitación Premium con Baño Privado',
                'location' => 'Zona Universitaria',
                'beds' => 1,
                'baths' => 1,
                'area' => '25m²',
                'price' => 'S/450',
                'rating' => 4.6,
                'reviews' => 89,
                'img' => 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?auto=format&fit=crop&q=80&w=600',
            ],
            [
                'type' => 'Apartamento',
                'status' => 'Disponible',
                'title' => 'Estudio Ejecutivo Vista al Mar',
                'location' => 'Zona Costera',
                'beds' => 1,
                'baths' => 1,
                'area' => '45m²',
                'price' => 'S/1,800',
                'rating' => 4.9,
                'reviews' => 156,
                'img' => 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&q=80&w=600',
            ],
        ];

        foreach ($properties as $property) {
            Property::create($property);
        }
    }
}
