<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\AdditionalService;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class StatsController extends Controller
{
    // ✅ Ahora requiere ser admin
    public function __construct()
    {
        $this->middleware('admin');
    }

    public function index(): JsonResponse
    {
       
        $totalProperties = Property::count();
        $totalServices = AdditionalService::count();
        
        $totalLeads = Lead::count();
        $totalViews = Property::sum('views');
        
        // Leads calculations
        $leadsThisWeek = Lead::where('created_at', '>=', Carbon::now()->subDays(7))->count();
        $leadsLastWeek = Lead::whereBetween('created_at', [Carbon::now()->subDays(14), Carbon::now()->subDays(7)])->count();
        $leadChange = $leadsLastWeek > 0 ? (($leadsThisWeek - $leadsLastWeek) / $leadsLastWeek) * 100 : ($leadsThisWeek > 0 ? 100 : 0);
        $leadChangeStr = ($leadChange >= 0 ? '+' : '') . round($leadChange, 1) . '%';
        
        // Views calculations (approximated for change tracking)
        // Since we only store total views in properties table, we can't easily track views per week unless we have a history table.
        // For now, I'll return +0% or a more realistic placeholder until a view_logs table is implemented.
        // But for "Interacciones" (Leads), we have the full history.
        $viewChangeStr = '+0%'; // Placeholder for now, but improved from '+--%'
        
        $occupied = Property::where('status', 'Ocupado')->count();
        $available = Property::where('status', 'Disponible')->count();
        $maintenance = Property::where('status', 'Mantenimiento')->count();
        
        $monthlyLeads = [];
        $monthlyLabels = [];
        for ($i = 6; $i >= 0; $i--) {
            $monthStart = Carbon::now()->startOfMonth()->subMonths($i);
            $monthEnd = Carbon::now()->endOfMonth()->subMonths($i);
            $monthlyLeads[] = Lead::whereBetween('created_at', [$monthStart, $monthEnd])->count();
            $monthlyLabels[] = strtoupper($monthStart->locale('es')->translatedFormat('M'));
        }

        $recentActivity = Lead::orderBy('created_at', 'desc')->take(4)->get()->map(function($lead) {
            $itemType = $lead->type == 'property' ? 'Propiedad' : ($lead->type == 'service' ? 'Servicio' : 'General');
            return [
                'text' => "Interés vía WhatsApp ($itemType)",
                'time' => $lead->created_at->diffForHumans()
            ];
        });

        
        return response()->json([
            'revenue' => [
                'total' => $totalLeads, 
                'change' => $leadChangeStr, 
                'expenses' => $totalViews, 
                'expensesChange' => $viewChangeStr 
            ],
            'inventory' => [
                'properties' => $totalProperties,
                'services' => $totalServices,
                'rooms' => Property::where('type', 'like', '%Habitación%')->count()
            ],
            'charts' => [
                'monthlyRevenue' => $monthlyLeads,
                'monthlyLabels' => $monthlyLabels,
                'benefitsDistribution' => [
                    'total' => $totalProperties,
                    'costs' => $available,
                    'taxes' => $occupied, 
                    'maintenance' => $maintenance
                ]
            ],
            'recentActivity' => $recentActivity
        ]);
    }
}
