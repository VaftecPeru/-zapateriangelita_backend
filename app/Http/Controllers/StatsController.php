<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreInteraction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StatsController extends Controller
{
    public function __construct()
    {
        $this->middleware('admin');
    }

    public function index(): JsonResponse
    {
        try {
            $now = Carbon::now();
            $weekStart = $now->copy()->subDays(7);
            $previousWeekStart = $now->copy()->subDays(14);

            $leadCount = Schema::hasTable('leads') ? DB::table('leads')->count() : 0;
            $trackedInteractions = StoreInteraction::count();
            $totalInteractions = $trackedInteractions + $leadCount;
            $whatsappInteractions = StoreInteraction::where('type', 'whatsapp_click')->count();
            $productViews = StoreInteraction::where('type', 'product_view')->count();

            $currentInteractions = StoreInteraction::where('created_at', '>=', $weekStart)->count()
                + (Schema::hasTable('leads') ? DB::table('leads')->where('created_at', '>=', $weekStart)->count() : 0);
            $previousInteractions = StoreInteraction::whereBetween('created_at', [$previousWeekStart, $weekStart])->count()
                + (Schema::hasTable('leads') ? DB::table('leads')->whereBetween('created_at', [$previousWeekStart, $weekStart])->count() : 0);

            $currentViews = StoreInteraction::where('type', 'product_view')->where('created_at', '>=', $weekStart)->count();
            $previousViews = StoreInteraction::where('type', 'product_view')->whereBetween('created_at', [$previousWeekStart, $weekStart])->count();
            $currentWhatsapp = StoreInteraction::where('type', 'whatsapp_click')->where('created_at', '>=', $weekStart)->count();
            $previousWhatsapp = StoreInteraction::where('type', 'whatsapp_click')->whereBetween('created_at', [$previousWeekStart, $weekStart])->count();

            $totalProducts = Product::count();
            $availableProducts = Product::where('stock', '>', 0)->count();
            $outOfStockProducts = Product::where('stock', '<=', 0)->count();
            $lowStockProducts = Product::whereBetween('stock', [1, 5])->count();
            $totalUnits = (int) Product::sum('stock');

            if (Schema::hasTable('product_variant_stocks')) {
                $variantTotal = (int) DB::table('product_variant_stocks')->sum('stock');
                if ($variantTotal > 0) {
                    $totalUnits = $variantTotal;
                }
            }

            $monthlyInteractions = [];
            $monthlyWhatsapp = [];
            $monthlyProductViews = [];
            $monthlyLabels = [];
            $monthNames = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

            for ($i = 6; $i >= 0; $i--) {
                $date = $now->copy()->subMonths($i);
                $start = $date->copy()->startOfMonth();
                $end = $date->copy()->endOfMonth();

                $tracked = StoreInteraction::whereBetween('created_at', [$start, $end])->count();
                $leads = Schema::hasTable('leads')
                    ? DB::table('leads')->whereBetween('created_at', [$start, $end])->count()
                    : 0;

                $monthlyInteractions[] = $tracked + $leads;
                $monthlyWhatsapp[] = StoreInteraction::where('type', 'whatsapp_click')
                    ->whereBetween('created_at', [$start, $end])->count();
                $monthlyProductViews[] = StoreInteraction::where('type', 'product_view')
                    ->whereBetween('created_at', [$start, $end])->count();
                $monthlyLabels[] = $monthNames[$date->month - 1] . '.';
            }

            $recentTracked = StoreInteraction::with('product:id,name')
                ->latest()
                ->take(12)
                ->get()
                ->map(function ($item) {
                    $labels = [
                        'product_view' => 'Visita a producto',
                        'whatsapp_click' => 'Interacción de WhatsApp',
                        'newsletter_subscribe' => 'Nueva suscripción',
                        'add_to_cart' => 'Producto agregado al carrito',
                        'checkout_start' => 'Inicio de compra',
                    ];

                    $text = $labels[$item->type] ?? 'Interacción';
                    if ($item->product?->name) {
                        $text .= ': ' . $item->product->name;
                    }

                    return [
                        'text' => $text,
                        'time' => Carbon::parse($item->created_at)->diffForHumans(),
                        'created_at' => $item->created_at,
                    ];
                });

            $recentOrders = Order::latest()
                ->take(8)
                ->get(['id', 'code', 'status', 'customer_name', 'total', 'created_at'])
                ->map(fn ($order) => [
                    'text' => "Pedido {$order->code} · " . ($order->customer_name ?: 'Cliente') . " · {$order->status}",
                    'time' => Carbon::parse($order->created_at)->diffForHumans(),
                    'created_at' => $order->created_at,
                ]);

            $recentActivity = $recentTracked
                ->concat($recentOrders)
                ->sortByDesc('created_at')
                ->take(12)
                ->values()
                ->map(fn ($item) => [
                    'text' => $item['text'],
                    'time' => $item['time'],
                ]);

            return response()->json([
                'all_users' => User::latest()->get(),
                'all_leads' => Lead::latest()->get(),
                'metrics' => [
                    'interactions' => $totalInteractions,
                    'whatsapp_interactions' => $whatsappInteractions,
                    'product_views' => $productViews,
                    'orders' => Order::count(),
                    'paid_orders' => Order::where('payment_status', 'paid')->count(),
                    'inventory_units' => $totalUnits,
                    'low_stock_products' => $lowStockProducts,
                    'out_of_stock_products' => $outOfStockProducts,
                ],
                'revenue' => [
                    'total' => $totalInteractions,
                    'change' => $this->percentageChange($currentInteractions, $previousInteractions),
                    'expenses' => $productViews,
                    'expensesChange' => $this->percentageChange($currentViews, $previousViews),
                    'whatsapp' => $whatsappInteractions,
                    'whatsappChange' => $this->percentageChange($currentWhatsapp, $previousWhatsapp),
                ],
                'inventory' => [
                    'properties' => $totalProducts,
                    'services' => 0,
                    'rooms' => 0,
                    'units' => $totalUnits,
                    'low_stock' => $lowStockProducts,
                ],
                'charts' => [
                    'monthlyRevenue' => $monthlyInteractions,
                    'monthlyWhatsapp' => $monthlyWhatsapp,
                    'monthlyProductViews' => $monthlyProductViews,
                    'monthlyLabels' => $monthlyLabels,
                    'benefitsDistribution' => [
                        'total' => $totalProducts,
                        'costs' => $availableProducts,
                        'taxes' => $outOfStockProducts,
                        'maintenance' => $lowStockProducts,
                    ],
                ],
                'recentActivity' => $recentActivity,
                'generated_at' => $now->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Stats Error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => 'Error al cargar estadísticas',
                'message' => 'No fue posible calcular el dashboard.',
            ], 500);
        }
    }

    private function percentageChange(int $current, int $previous): string
    {
        if ($previous === 0) {
            return $current > 0 ? '+100%' : '+0%';
        }

        $change = (($current - $previous) / $previous) * 100;
        return ($change >= 0 ? '+' : '') . round($change, 1) . '%';
    }
}
