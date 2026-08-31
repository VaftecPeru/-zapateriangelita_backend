<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Mujer',
                'slug' => 'mujer',
                'icon' => '👠',
                'order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Hombre',
                'slug' => 'hombre',
                'icon' => '👞',
                'order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Niños',
                'slug' => 'ninos',
                'icon' => '👟',
                'order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'Deportivos',
                'slug' => 'deportivos',
                'icon' => '🏃',
                'order' => 4,
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }

        $this->command->info('✅ Categorías creadas exitosamente!');
    }
}