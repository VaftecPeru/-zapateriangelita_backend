<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Nike Air Max 90 - Mujer (Category 1), Tenis deportivos (Subcategory 12)
        Product::create([
            'name' => 'Nike Air Max 90',
            'category_id' => 1,
            'subcategory_id' => 12,
            'brand' => 'Nike',
            'price' => 549.99,
            'discounted_price' => 449.99,
            'stock' => 45,
            'size' => '8 US',
            'color' => 'Blanco/Negro',
            'material' => 'Cuero y Malla',
            'rating' => 4.5,
            'reviews' => 127,
            'img' => 'https://via.placeholder.com/400?text=Nike+Air+Max+90',
            'images' => json_encode([
                'https://via.placeholder.com/400?text=Nike+Air+Max+90+View1',
                'https://via.placeholder.com/400?text=Nike+Air+Max+90+View2',
            ]),
            'description' => 'Icónico tenis Nike con tecnología Air Max. Comodidad superior para uso diario y deportivo.',
            'discount' => '-18%',
        ]);

        // Adidas Ultraboost 23 - Mujer (Category 1), Tenis urbanos (Subcategory 13)
        Product::create([
            'name' => 'Adidas Ultraboost 23',
            'category_id' => 1,
            'subcategory_id' => 13,
            'brand' => 'adidas',
            'price' => 629.99,
            'discounted_price' => 529.99,
            'stock' => 38,
            'size' => '6 US',
            'color' => 'Gris/Rosa',
            'material' => 'Tela Técnica y Malla',
            'rating' => 4.8,
            'reviews' => 214,
            'img' => 'https://via.placeholder.com/400?text=Adidas+Ultraboost+23',
            'images' => json_encode([
                'https://via.placeholder.com/400?text=Adidas+Ultraboost+View1',
                'https://via.placeholder.com/400?text=Adidas+Ultraboost+View2',
                'https://via.placeholder.com/400?text=Adidas+Ultraboost+View3',
            ]),
            'description' => 'Máximo confort con tecnología Boost. Ideal para runners y uso casual de alto rendimiento.',
            'discount' => '-16%',
        ]);

        // CAT Colorado Boot - Hombre (Category 2), Botas (Subcategory 16)
        Product::create([
            'name' => 'Cat Caterpillar Colorado',
            'category_id' => 2,
            'subcategory_id' => 16,
            'brand' => 'CAT',
            'price' => 799.99,
            'discounted_price' => null,
            'stock' => 22,
            'size' => '42 EU',
            'color' => 'Marrón Oscuro',
            'material' => 'Cuero Premium',
            'rating' => 4.7,
            'reviews' => 89,
            'img' => 'https://via.placeholder.com/400?text=Cat+Colorado+Boot',
            'images' => json_encode([
                'https://via.placeholder.com/400?text=Cat+Colorado+View1',
                'https://via.placeholder.com/400?text=Cat+Colorado+View2',
                'https://via.placeholder.com/400?text=Cat+Colorado+View3',
                'https://via.placeholder.com/400?text=Cat+Colorado+View4',
            ]),
            'description' => 'Bota de trabajo premium en cuero genuino. Durabilidad y confort para todo el día.',
            'discount' => null,
        ]);
    }
}
