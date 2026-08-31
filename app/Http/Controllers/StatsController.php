<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\AdditionalService;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatsController extends Controller
{
    public function __construct()
    {
        $this->middleware('admin');
    }

    public function index(): JsonResponse
    {
        try {
            $totalProducts = Product::count();
            $totalServices = AdditionalService::count();

            // Contar leads directamente desde la tabla para evitar problemas de modelo
            $totalLeads = DB::table('leads')->count();
            $totalViews = 0; // Replace views

            Log::info("Stats Debug: totalLeads={$totalLeads}, totalViews={$totalViews}");

            // Cálculos semanales para interacciones (leads)
            $leadsThisWeek = DB::table('leads')->where('created_at', '>=', Carbon::now()->subDays(7))->count();
            $leadsLastWeek = DB::table('leads')->whereBetween('created_at', [Carbon::now()->subDays(14), Carbon::now()->subDays(7)])->count();
            $leadChange = $leadsLastWeek > 0 ? (($leadsThisWeek - $leadsLastWeek) / $leadsLastWeek) * 100 : ($leadsThisWeek > 0 ? 100 : 0);
            $leadChangeStr = ($leadChange >= 0 ? '+' : '') . round($leadChange, 1) . '%';

            $viewChangeStr = '+0%';
            $occupied = Product::where('stock', '=', 0)->count(); // out of stock
            $available = Product::where('stock', '>', 0)->count(); // in stock
            $maintenance = 0;

            $monthlyLeads = [];
            $monthlyLabels = [];
            $monthNames = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $monthStart = $date->copy()->startOfMonth();
                $monthEnd = $date->copy()->endOfMonth();

                $count = DB::table('leads')
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->count();

                $monthlyLeads[] = $count;
                $monthlyLabels[] = $monthNames[$date->month - 1] . '.';
            }

            Log::info("Stats Debug monthly:", ['leads' => $monthlyLeads, 'labels' => $monthlyLabels]);

            $recentLeads = DB::table('leads')
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get()
                ->map(function ($lead) {
                    $name = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
                    $type = $lead->type == 'property' ? 'Propiedad' : ($lead->type == 'service' ? 'Servicio' : 'Portada');
                    $title = $lead->property_title ?? '';

                    $text = "Interés en {$type}";
                    if ($title)
                        $text .= ": {$title}";
                    if ($name && trim($name) !== '')
                        $text .= " por {$name}";

                    return [
                        'text' => $text,
                        'time' => Carbon::parse($lead->created_at)->diffForHumans(),
                        'user' => $name
                    ];
                });

            $topViewed = Product::orderBy('rating', 'desc')
                ->take(3)
                ->get()
                ->map(function ($prop) {
                    return [
                        'text' => "Alta valoración en \"{$prop->name}\"",
                        'time' => 'acumulado',
                        'user' => ''
                    ];
                });

            $recentActivity = $recentLeads->merge($topViewed);

            // Consolidar listas completas para el Dashboard
            $allUsers = \App\Models\User::orderBy('created_at', 'desc')->get();
            // $allLeads = DB::table('leads')->orderBy('created_at', 'desc')->get();
            $allLeads = Lead::orderBy('created_at', 'desc')->get();

            return response()->json([
                'all_users' => $allUsers,
                'all_leads' => $allLeads,
                'revenue' => [
                    'total' => $totalLeads,
                    'change' => $leadChangeStr,
                    'expenses' => $totalViews,
                    'expensesChange' => $viewChangeStr
                ],
                'inventory' => [
                    'properties' => $totalProducts,
                    'services' => $totalServices,
                    'rooms' => 0
                ],
                'charts' => [
                    'monthlyRevenue' => $monthlyLeads,
                    'monthlyLabels' => $monthlyLabels,
                    'benefitsDistribution' => [
                        'total' => $totalProducts,
                        'costs' => $available,
                        'taxes' => $occupied,
                        'maintenance' => $maintenance
                    ]
                ],
                'recentActivity' => $recentActivity
            ]);
        } catch (\Throwable $e) {
            Log::error('Stats Error: ' . $e->getMessage() . ' Line: ' . $e->getLine());
            return response()->json([
                'error' => 'Error al cargar estadísticas',
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
