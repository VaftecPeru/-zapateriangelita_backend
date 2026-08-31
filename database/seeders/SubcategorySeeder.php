<?php

namespace Database\Seeders;

use App\Models\Subcategory;
use App\Models\Category;
use Illuminate\Database\Seeder;

class SubcategorySeeder extends Seeder
{
    public function run(): void
    {
        // Obtener categorías
        $mujer = Category::where('slug', 'mujer')->first();
        $hombre = Category::where('slug', 'hombre')->first();
        $ninos = Category::where('slug', 'ninos')->first();

        // Subcategorías de Mujer
        $subcategoriasMujer = [
            'Alpargatas',
            'Botas',
            'Botines',
            'Casual',
            'Chunky',
            'Confort',
            'Flats y Balerinas',
            'Mocasín',
            'Pantuflas',
            'Sandalias',
            'Senderismo',
            'Tenis deportivos',
            'Tenis urbanos',
            'Zapatillas',
            'Zapatillas de fiesta'
        ];

        foreach ($subcategoriasMujer as $index => $name) {
            Subcategory::create([
                'category_id' => $mujer->id,
                'name' => $name,
                'order' => $index,
                'is_active' => true,
            ]);
        }

        // Subcategorías de Hombre
        $subcategoriasHombre = [
            'Botas',
            'Botines',
            'Casual',
            'Industrial',
            'Mocasín',
            'Pantufla',
            'Sandalia',
            'Senderismo',
            'Tacos de fútbol',
            'Tenis deportivo',
            'Tenis urbanos',
            'Zapatos de vestir'
        ];

        foreach ($subcategoriasHombre as $index => $name) {
            Subcategory::create([
                'category_id' => $hombre->id,
                'name' => $name,
                'order' => $index,
                'is_active' => true,
            ]);
        }

        // Subcategorías de Niños (con talla)
        $subcategoriasNinos = [
            ['talla' => 'Bebé (8-13)', 'items' => ['Botas', 'Casual', 'Sandalias', 'Tenis']],
            ['talla' => 'Preescolar (14-17)', 'items' => ['Balerinas', 'Botas', 'Botines', 'Casual', 'Tenis', 'Sandalias']],
            ['talla' => 'Niña (18-21)', 'items' => ['Alpargatas', 'Balerinas y flats', 'Botas', 'Casual', 'Graduación', 'Lluvia', 'Pantufla', 'Sandalia', 'Tenis deportivos', 'Tenis urbanos']],
            ['talla' => 'Niño (18-21)', 'items' => ['Botas', 'Casual', 'Sandalias', 'Tacos de fútbol', 'Tenis deportivos', 'Tenis urbanos']],
        ];

        foreach ($subcategoriasNinos as $group) {
            foreach ($group['items'] as $item) {
                Subcategory::create([
                    'category_id' => $ninos->id,
                    'name' => $item,
                    'talla' => $group['talla'],
                    'order' => 0,
                    'is_active' => true,
                ]);
            }
        }
    }
}