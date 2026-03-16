<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Room;
use App\Models\AdditionalService;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function index()
    {
        $totalProperties = Property::count();
        $totalRooms = Room::count();
        $totalServices = AdditionalService::count();
        
        
        $totalRevenue = Property::sum('price') * 1.5; 
        $operationalExpenses = $totalRevenue * 0.35;
        
   
        $monthlyRevenue = [
            rand(1000, 2000), 
            rand(2000, 3000), 
            rand(2500, 3500), 
            rand(3000, 4500), 
            rand(4000, 5000), 
            rand(4500, 6000),
            $totalRevenue / 12
        ];

        return response()->json([
            'revenue' => [
                'total' => round($totalRevenue, 2),
                'change' => '+15%', 
                'expenses' => round($operationalExpenses, 2),
                'expensesChange' => '-3%'
            ],
            'inventory' => [
                'properties' => $totalProperties,
                'rooms' => $totalRooms,
                'services' => $totalServices
            ],
            'charts' => [
                'monthlyRevenue' => $monthlyRevenue,
                'benefitsDistribution' => [
                    'total' => round($totalRevenue, 0),
                    'costs' => round($operationalExpenses, 0),
                    'taxes' => round($totalRevenue * 0.18, 0)
                ]
            ],
            'recentActivity' => [
                ['text' => "Nueva reserva: Habitación Premium", 'time' => "Hace un momento"],
                ['text' => "Check-out: Suite Familiar", 'time' => "Hoy"],
                ['text' => "Solicitud de limpieza unidad 302", 'time' => "Ahora"]
            ]
        ]);
    }
}
