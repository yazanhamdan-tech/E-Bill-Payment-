<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupportTicket;
use App\Models\User;

/**
 * Dashboard controller for role-specific web views and API payloads.
 * Notes: Keeps routing stable while branching on roles to serve distinct dashboards.
 */
class DashboardController extends Controller
{
    public function __construct()
    {
        // Authenticated users only; role checks are handled per method below.
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();
        // Role-based web routing: each role has its own dashboard view/data.
        // Duplication: this mirrors apiIndex() role selection logic.
        if ($user->hasRole('admin')) {
            return $this->adminDashboard();
        } elseif ($user->hasRole('service_provider')) {
            return $this->providerDashboard();
        } elseif ($user->hasRole('customer')) {
            return $this->customerDashboard();
        } elseif ($user->hasRole('support_agent')) {
            return $this->supportDashboard();
        }

        return view('dashboard.default');
    }

    /**
     * API endpoint for dashboard data
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $user = Auth::user();
        // Role-based API payloads; same branching as index().
        // Security: only checks role; consider policies/guards if dashboards are sensitive.
        if ($user->hasRole('admin')) {
            return response()->json($this->getAdminDashboardData());
        } elseif ($user->hasRole('service_provider')) {
            return response()->json($this->getProviderDashboardData($user));
        } elseif ($user->hasRole('customer')) {
            return response()->json($this->getCustomerDashboardData($user));
        } elseif ($user->hasRole('support_agent')) {
            return response()->json($this->getSupportDashboardData($user));
        }

        return response()->json(['message' => 'No dashboard data available'], 403);
    }

    private function adminDashboard()
    {
        // Admins are redirected to the dedicated admin dashboard route.
        return redirect()->route('admin.dashboard');
    }

    private function getAdminDashboardData(): array
    {
        // Admin stats are system-wide; multiple independent queries are used for clarity.
        // Performance: multiple full-table scans could be optimized via aggregate queries or caching.
        return [
            'type' => 'admin',
            'stats' => [
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
            ],
            'recent_invoices' => Invoice::with(['user', 'serviceProvider'])
                // Eager loading avoids N+1 when rendering invoice relationships.
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
            'recent_payments' => Payment::with(['invoice', 'user'])
                ->where('status', 'completed')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
        ];
    }

    private function providerDashboard()
    {
        $user = Auth::user();
        $serviceProvider = $user->serviceProvider;
        // If the provider profile is missing, return an empty view-friendly structure.
        if (!$serviceProvider) {
            return view('dashboard.provider', [
                'stats' => [],
                'recentInvoices' => collect(),
                'monthlyRevenue' => collect(),
            ]);
        }

        // Provider-specific stats limited to their own invoices and payments.
        $stats = [
            'total_invoices' => Invoice::where('service_provider_id', $serviceProvider->id)->count(),
            'pending_invoices' => Invoice::where('service_provider_id', $serviceProvider->id)
                ->where('status', 'pending')->count(),
            'paid_invoices' => Invoice::where('service_provider_id', $serviceProvider->id)
                ->where('status', 'paid')->count(),
            'total_revenue' => Payment::whereHas('invoice', function($q) use ($serviceProvider) {
                $q->where('service_provider_id', $serviceProvider->id);
            })->where('status', 'completed')->sum('amount') ?? 0,
        ];

        // Recent invoices for quick provider visibility.
        $recentInvoices = Invoice::where('service_provider_id', $serviceProvider->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Monthly revenue uses in-memory grouping for driver compatibility.
        // Performance: loads all payments for the year; can be optimized with DB aggregation.
        $monthlyRevenue = Payment::whereHas('invoice', function($q) use ($serviceProvider) {
            $q->where('service_provider_id', $serviceProvider->id);
        })
        ->where('status', 'completed')
        ->whereYear('created_at', now()->year)
        ->get()
        ->groupBy(function($payment) {
            return $payment->created_at->format('m');
        })
        ->map(function($payments) {
            return [
                'month' => (int) $payments->first()->created_at->format('m'),
                'total' => $payments->sum('amount') ?? 0
            ];
        })
        ->sortBy('month')
        ->values();

        return view('dashboard.provider', compact('stats', 'recentInvoices', 'monthlyRevenue'));
    }

    private function getProviderDashboardData($user): array
    {
        $serviceProvider = $user->serviceProvider;
        // API variant of providerDashboard(): duplication noted.
        if (!$serviceProvider) {
            return [
                'type' => 'provider',
                'stats' => [],
                'recent_invoices' => [],
                'monthly_revenue' => [],
            ];
        }

        // Provider-specific API data (same queries as providerDashboard()).
        return [
            'type' => 'provider',
            'stats' => [
                'total_invoices' => Invoice::where('service_provider_id', $serviceProvider->id)->count(),
                'pending_invoices' => Invoice::where('service_provider_id', $serviceProvider->id)
                    ->where('status', 'pending')->count(),
                'paid_invoices' => Invoice::where('service_provider_id', $serviceProvider->id)
                    ->where('status', 'paid')->count(),
                'total_revenue' => Payment::whereHas('invoice', function($q) use ($serviceProvider) {
                    $q->where('service_provider_id', $serviceProvider->id);
                })->where('status', 'completed')->sum('amount') ?? 0,
            ],
            'recent_invoices' => Invoice::where('service_provider_id', $serviceProvider->id)
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
            'monthly_revenue' => Payment::whereHas('invoice', function($q) use ($serviceProvider) {
                $q->where('service_provider_id', $serviceProvider->id);
            })
            ->where('status', 'completed')
            ->whereYear('created_at', now()->year)
            ->get()
            ->groupBy(function($payment) {
                return $payment->created_at->format('m');
            })
            ->map(function($payments) {
                // Uses first payment's month as label; assumes all in group share same month.
                return [
                    'month' => (int) $payments->first()->created_at->format('m'),
                    'total' => $payments->sum('amount') ?? 0
                ];
            })
            ->sortBy('month')
            ->values(),
        ];
    }

    private function customerDashboard()
    {
        $user = Auth::user();
        // Customer stats focus on the authenticated user's invoices and payments.
        $stats = [
            'total_invoices' => Invoice::where('user_id', $user->id)->count(),
            'pending_invoices' => Invoice::where('user_id', $user->id)
                ->where('status', 'pending')->count(),
            'overdue_invoices' => Invoice::where('user_id', $user->id)
                ->overdue()->count(),
            'paid_invoices' => Invoice::where('user_id', $user->id)
                ->where('status', 'paid')->count(),
            'total_paid' => Payment::where('user_id', $user->id)
                ->where('status', 'completed')->sum('amount') ?? 0,
            'pending_amount' => Invoice::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->sum('total_amount') ?? 0,
        ];

        // Recent invoices and payments for quick customer context.
        $recentInvoices = Invoice::where('user_id', $user->id)
            ->with('serviceProvider')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $recentPayments = Payment::where('user_id', $user->id)
            ->where('status', 'completed')
            ->with('invoice')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Upcoming due dates within the next 7 days.
        $upcomingDueDates = Invoice::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays(7))
            ->orderBy('due_date', 'asc')
            ->get();

        return view('dashboard.customer', compact('stats', 'recentInvoices', 'recentPayments', 'upcomingDueDates'));
    }

    private function getCustomerDashboardData($user): array
    {
        // API variant of customerDashboard(); duplicates queries.
        return [
            'type' => 'customer',
            'stats' => [
                'total_invoices' => Invoice::where('user_id', $user->id)->count(),
                'pending_invoices' => Invoice::where('user_id', $user->id)
                    ->where('status', 'pending')->count(),
                'overdue_invoices' => Invoice::where('user_id', $user->id)
                    ->overdue()->count(),
                'paid_invoices' => Invoice::where('user_id', $user->id)
                    ->where('status', 'paid')->count(),
                'total_paid' => Payment::where('user_id', $user->id)
                    ->where('status', 'completed')->sum('amount') ?? 0,
                'pending_amount' => Invoice::where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'overdue'])
                    ->sum('total_amount') ?? 0,
            ],
            'recent_invoices' => Invoice::where('user_id', $user->id)
                ->with('serviceProvider')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
            'recent_payments' => Payment::where('user_id', $user->id)
                ->where('status', 'completed')
                ->with('invoice')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
            'upcoming_due_dates' => Invoice::where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('due_date', '>=', now())
                ->where('due_date', '<=', now()->addDays(7))
                ->orderBy('due_date', 'asc')
                ->get(),
        ];
    }

    private function supportDashboard()
    {
        $user = Auth::user();
        // Support agent overview; counts are global or scoped to the agent.
        $stats = [
            'open_tickets' => SupportTicket::where('status', 'open')->count(),
            'assigned_tickets' => SupportTicket::where('assigned_to', $user->id)->count(),
            'resolved_tickets' => SupportTicket::where('status', 'resolved')
                ->where('assigned_to', $user->id)->count(),
        ];

        // Agent's assigned tickets, eager loading replies.
        $myTickets = SupportTicket::where('assigned_to', $user->id)
            ->with(['user', 'replies'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Open tickets not yet assigned, ordered by priority then recency.
        $unassignedTickets = SupportTicket::whereNull('assigned_to')
            ->where('status', 'open')
            ->with('user')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return view('dashboard.support', compact('stats', 'myTickets', 'unassignedTickets'));
    }

    private function getSupportDashboardData($user): array
    {
        // API variant of supportDashboard(); duplicates queries.
        return [
            'type' => 'support',
            'stats' => [
                'open_tickets' => SupportTicket::where('status', 'open')->count(),
                'assigned_tickets' => SupportTicket::where('assigned_to', $user->id)->count(),
                'resolved_tickets' => SupportTicket::where('status', 'resolved')
                    ->where('assigned_to', $user->id)->count(),
            ],
            'my_tickets' => SupportTicket::where('assigned_to', $user->id)
                ->with(['user', 'replies'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
            'unassigned_tickets' => SupportTicket::whereNull('assigned_to')
                ->where('status', 'open')
                ->with('user')
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
        ];
    }

    /*
     * Suggested improvements (non-breaking refactor ideas):
     * - Extract role selection into a single private method to avoid duplication in index()/apiIndex().
     * - Create query scopes (e.g., Invoice::forUser(), Invoice::forProvider(), Payment::completed()).
     * - Move dashboard data builders into Service/Action classes for testability and reuse.
     * - Replace in-memory monthly revenue grouping with DB aggregation when supported; cache heavy stats.
     * - Add authorization policies or gates for role-based access to strengthen security guarantees.
     */
}
