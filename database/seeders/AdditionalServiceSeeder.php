<?php

namespace Database\Seeders;

use App\Models\AdditionalService;
use Illuminate\Database\Seeder;

class AdditionalServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'Limpieza', 'description' => 'Servicio de limpieza profunda para tu hogar.', 'price' => 50],
            ['name' => 'Mudanza', 'description' => 'Transporte seguro de tus pertenencias.', 'price' => 150],
            ['name' => 'Mantenimiento', 'description' => 'Reparación y cuidado preventivo.', 'price' => 80],
        ];

        foreach ($services as $service) {
            AdditionalService::create($service);
        }
    }
}
