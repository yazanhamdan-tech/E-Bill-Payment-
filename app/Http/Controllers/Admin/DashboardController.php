<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\SupportTicket;
use App\Models\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:admin']);
    }

    public function index()
    {
        $data = $this->getDashboardData();

        return view('admin.dashboard', $data);
    }

    /**
     * API endpoint for admin dashboard
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $data = $this->getDashboardData();

        return response()->json([
            'stats' => $data['stats'],
            'recent_invoices' => $data['recentInvoices'],
            'recent_payments' => $data['recentPayments'],
            'recent_tickets' => $data['recentTickets'],
            'monthly_revenue' => $data['monthlyRevenue'],
            'revenue_by_provider' => $data['revenueByProvider'],
            'payment_status_distribution' => $data['paymentStatusDistribution'],
            'invoice_status_distribution' => $data['invoiceStatusDistribution'],
            'new_users_this_month' => $data['newUsersThisMonth'],
            'revenue_this_month' => $data['revenueThisMonth'],
            'revenue_last_month' => $data['revenueLastMonth'],
            'revenue_growth' => $data['revenueGrowth'],
        ]);
    }

    private function getDashboardData(): array
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'total_invoices' => Invoice::count(),
            'pending_invoices' => Invoice::where('status', 'pending')->count(),
            'overdue_invoices' => Invoice::overdue()->count(),
            'paid_invoices' => Invoice::where('status', 'paid')->count(),
            'total_payments' => Payment::where('status', 'completed')->count(),
            'total_revenue' => Payment::where('status', 'completed')->sum('amount') ?? 0,
            'open_tickets' => SupportTicket::where('status', 'open')->count(),
            'resolved_tickets' => SupportTicket::where('status', 'resolved')->count(),
            'total_service_providers' => ServiceProvider::where('status', 'active')->count(),
        ];

        $recentInvoices = Invoice::with(['user', 'serviceProvider'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentPayments = Payment::with(['invoice', 'user'])
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentTickets = SupportTicket::with(['user', 'assignedTo'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $monthlyRevenue = $this->getMonthlyRevenue();
        $revenueByProvider = $this->getRevenueByProvider();

        $paymentStatusDistribution = Payment::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        $invoiceStatusDistribution = Invoice::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        $newUsersThisMonth = User::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $revenueThisMonth = Payment::where('status', 'completed')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount') ?? 0;

        $revenueLastMonthDate = now()->subMonth();
        $revenueLastMonth = Payment::where('status', 'completed')
            ->whereMonth('created_at', $revenueLastMonthDate->month)
            ->whereYear('created_at', $revenueLastMonthDate->year)
            ->sum('amount') ?? 0;

        $revenueGrowth = $this->calculateRevenueGrowth($revenueThisMonth, $revenueLastMonth);

        return [
            'stats' => $stats,
            'recentInvoices' => $recentInvoices,
            'recentPayments' => $recentPayments,
            'recentTickets' => $recentTickets,
            'monthlyRevenue' => $monthlyRevenue,
            'revenueByProvider' => $revenueByProvider,
            'paymentStatusDistribution' => $paymentStatusDistribution,
            'invoiceStatusDistribution' => $invoiceStatusDistribution,
            'newUsersThisMonth' => $newUsersThisMonth,
            'revenueThisMonth' => $revenueThisMonth,
            'revenueLastMonth' => $revenueLastMonth,
            'revenueGrowth' => $revenueGrowth,
        ];
    }

    private function getMonthlyRevenue()
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $monthExpression = $driver === 'pgsql'
                ? 'EXTRACT(MONTH FROM created_at)'
                : 'MONTH(created_at)';

            return Payment::where('status', 'completed')
                ->whereYear('created_at', now()->year)
                ->selectRaw($monthExpression . ' as month, COALESCE(SUM(amount), 0) as total')
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(function ($row) {
                    return [
                        'month' => (int) $row->month,
                        'total' => $row->total ?? 0,
                    ];
                })
                ->values();
        }

        return Payment::where('status', 'completed')
            ->whereYear('created_at', now()->year)
            ->get()
            ->groupBy(function ($payment) {
                return Carbon::parse($payment->created_at)->format('m');
            })
            ->map(function ($payments) {
                $first = $payments->first();
                return [
                    'month' => $first ? (int) $first->created_at->format('m') : 0,
                    'total' => $payments->sum('amount') ?? 0,
                ];
            })
            ->sortBy('month')
            ->values();
    }

    private function getRevenueByProvider()
    {
        $topProviders = DB::table('service_providers')
            ->leftJoin('invoices', 'service_providers.id', '=', 'invoices.service_provider_id')
            ->leftJoin('payments', function ($join) {
                $join->on('payments.invoice_id', '=', 'invoices.id')
                    ->where('payments.status', 'completed');
            })
            ->groupBy('service_providers.id')
            ->select('service_providers.id as service_provider_id', DB::raw('COALESCE(SUM(payments.amount), 0) as revenue'))
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $providers = ServiceProvider::with('user')
            ->whereIn('id', $topProviders->pluck('service_provider_id'))
            ->get()
            ->keyBy('id');

        return $topProviders->map(function ($row) use ($providers) {
            return [
                'provider' => $providers->get($row->service_provider_id),
                'revenue' => $row->revenue ?? 0,
            ];
        })->values();
    }

    private function calculateRevenueGrowth($revenueThisMonth, $revenueLastMonth): float
    {
        $current = (float) ($revenueThisMonth ?? 0);
        $previous = (float) ($revenueLastMonth ?? 0);

        if ($previous <= 0) {
            return 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
