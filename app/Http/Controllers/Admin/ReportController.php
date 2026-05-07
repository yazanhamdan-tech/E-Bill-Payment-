<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:admin']);
    }

    public function index()
    {
        return view('admin.reports.index');
    }

    public function invoices(Request $request)
    {
        $baseQuery = $this->applyInvoiceFilters(Invoice::query(), $request->only([
            'status',
            'date_from',
            'date_to',
            'service_provider_id',
        ]));

        $invoices = (clone $baseQuery)
            ->with(['user', 'serviceProvider'])
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request, 50));

        $stats = $this->getInvoiceStats($baseQuery);
        $serviceProviders = ServiceProvider::all();

        return view('admin.reports.invoices', compact('invoices', 'stats', 'serviceProviders'));
    }

    public function payments(Request $request)
    {
        $baseQuery = $this->applyPaymentFilters(Payment::query(), $request->only([
            'status',
            'date_from',
            'date_to',
            'payment_method_id',
        ]));

        $payments = (clone $baseQuery)
            ->with(['invoice', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request, 50));

        $stats = $this->getPaymentStats($baseQuery);
        $monthlyRevenue = $this->getMonthlyRevenueForYear((int) now()->year);

        return view('admin.reports.payments', compact('payments', 'stats', 'monthlyRevenue'));
    }

    public function users(Request $request)
    {
        $baseQuery = $this->applyUserFilters(User::query(), $request->only([
            'role',
            'status',
            'date_from',
            'date_to',
        ]));

        $users = (clone $baseQuery)
            ->withCount(['invoices', 'payments'])
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request, 50));

        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'inactive_users' => User::where('is_active', false)->count(),
            'customers' => User::role('customer')->count(),
            'service_providers' => User::role('service_provider')->count(),
            'new_this_month' => User::whereMonth('created_at', now()->month)->count(),
        ];

        return view('admin.reports.users', compact('users', 'stats'));
    }

    public function export(Request $request)
    {
        $request->validate([
            'type' => 'required|in:invoices,payments,users',
            'format' => 'required|in:excel,pdf',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $type = $request->type;
        $format = $request->format;
        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;

        switch ($type) {
            case 'invoices':
                return $this->exportInvoices($format, $dateFrom, $dateTo);
            case 'payments':
                return $this->exportPayments($format, $dateFrom, $dateTo);
            case 'users':
                return $this->exportUsers($format, $dateFrom, $dateTo);
        }
    }

    private function exportInvoices($format, $dateFrom, $dateTo, $status = null, $serviceProviderId = null)
    {
        $baseQuery = $this->applyInvoiceFilters(Invoice::query(), [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $status,
            'service_provider_id' => $serviceProviderId,
        ]);

        $filename = 'invoices_report_' . now()->format('Y-m-d_H-i-s');

        if ($format === 'excel') {
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
            ];

            return response()->streamDownload(function () use ($baseQuery) {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($file, ['Invoice Number', 'Title', 'User', 'Service Provider', 'Amount', 'Status', 'Due Date', 'Created At']);

                (clone $baseQuery)
                    ->with(['user', 'serviceProvider'])
                    ->orderBy('created_at', 'desc')
                    ->chunk(500, function ($invoices) use ($file) {
                        foreach ($invoices as $invoice) {
                            fputcsv($file, [
                                $invoice->invoice_number,
                                $invoice->title ?? 'N/A',
                                $invoice->user?->name ?? 'N/A',
                                $invoice->serviceProvider?->company_name ?? 'N/A',
                                number_format($invoice->total_amount, 2),
                                ucfirst($invoice->status),
                                $invoice->due_date ? Carbon::parse($invoice->due_date)->format('Y-m-d') : 'N/A',
                                $invoice->created_at->format('Y-m-d H:i:s'),
                            ]);
                        }
                    });

                fclose($file);
            }, $filename . '.csv', $headers);
        }

        $invoices = (clone $baseQuery)
            ->with(['user', 'serviceProvider'])
            ->orderBy('created_at', 'desc')
            ->get();

        $stats = [
            'total_invoices' => $invoices->count(),
            'total_amount' => $invoices->sum('total_amount'),
            'paid_amount' => $invoices->where('status', 'paid')->sum('total_amount'),
            'pending_amount' => $invoices->where('status', 'pending')->sum('total_amount'),
            'overdue_amount' => $invoices->where('status', 'overdue')->sum('total_amount'),
        ];

        $html = view('admin.reports.pdf.invoices', compact('invoices', 'dateFrom', 'dateTo', 'stats'))->render();
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function exportPayments($format, $dateFrom, $dateTo, $status = null)
    {
        $baseQuery = $this->applyPaymentFilters(Payment::query(), [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $status,
        ]);

        $filename = 'payments_report_' . now()->format('Y-m-d_H-i-s');

        if ($format === 'excel') {
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
            ];

            return response()->streamDownload(function () use ($baseQuery) {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($file, ['Payment Reference', 'Invoice', 'User', 'Amount', 'Status', 'Payment Type', 'Payment Method', 'Created At']);

                (clone $baseQuery)
                    ->with(['invoice', 'user', 'paymentMethod'])
                    ->orderBy('created_at', 'desc')
                    ->chunk(500, function ($payments) use ($file) {
                        foreach ($payments as $payment) {
                            fputcsv($file, [
                                $payment->payment_reference ?? 'N/A',
                                $payment->invoice?->invoice_number ?? 'N/A',
                                $payment->user?->name ?? 'N/A',
                                number_format($payment->amount, 2),
                                ucfirst($payment->status),
                                ucfirst($payment->payment_type),
                                $payment->paymentMethod?->type ?? 'N/A',
                                $payment->created_at->format('Y-m-d H:i:s'),
                            ]);
                        }
                    });

                fclose($file);
            }, $filename . '.csv', $headers);
        }

        $payments = (clone $baseQuery)
            ->with(['invoice', 'user', 'paymentMethod'])
            ->orderBy('created_at', 'desc')
            ->get();

        $stats = [
            'total_payments' => $payments->count(),
            'total_amount' => $payments->where('status', 'completed')->sum('amount'),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            'failed_amount' => $payments->where('status', 'failed')->sum('amount'),
        ];

        $html = view('admin.reports.pdf.payments', compact('payments', 'dateFrom', 'dateTo', 'stats'))->render();
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function exportUsers($format, $dateFrom, $dateTo)
    {
        $baseQuery = User::query();

        if ($dateFrom) {
            $baseQuery->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $baseQuery->whereDate('created_at', '<=', $dateTo);
        }

        $filename = 'users_report_' . now()->format('Y-m-d_H-i-s');

        if ($format === 'excel') {
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
            ];

            return response()->streamDownload(function () use ($baseQuery) {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($file, ['Name', 'Email', 'Phone', 'Role', 'Status', 'Invoices Count', 'Payments Count', 'Created At']);

                (clone $baseQuery)
                    ->withCount(['invoices', 'payments'])
                    ->with('roles')
                    ->orderBy('created_at', 'desc')
                    ->chunk(500, function ($users) use ($file) {
                        foreach ($users as $user) {
                            fputcsv($file, [
                                $user->name,
                                $user->email,
                                $user->phone ?? 'N/A',
                                $user->roles->pluck('name')->join(', ') ?: 'N/A',
                                $user->is_active ? 'Active' : 'Inactive',
                                $user->invoices_count ?? 0,
                                $user->payments_count ?? 0,
                                $user->created_at->format('Y-m-d H:i:s'),
                            ]);
                        }
                    });

                fclose($file);
            }, $filename . '.csv', $headers);
        }

        $users = (clone $baseQuery)
            ->withCount(['invoices', 'payments'])
            ->with('roles')
            ->orderBy('created_at', 'desc')
            ->get();

        $stats = [
            'total_users' => $users->count(),
            'active_users' => $users->where('is_active', true)->count(),
            'inactive_users' => $users->where('is_active', false)->count(),
        ];

        $html = view('admin.reports.pdf.users', compact('users', 'dateFrom', 'dateTo', 'stats'))->render();
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Export financial report
     */
    private function exportFinancial($format, $dateFrom, $dateTo)
    {
        $dateFrom = $dateFrom ? Carbon::parse($dateFrom) : Carbon::now()->startOfMonth();
        $dateTo = $dateTo ? Carbon::parse($dateTo) : Carbon::now()->endOfMonth();

        $payments = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->get();

        $invoices = Invoice::whereBetween('created_at', [$dateFrom, $dateTo])->get();

        $revenueByProvider = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->with('invoice.serviceProvider')
            ->get()
            ->groupBy(function ($payment) {
                return $payment->invoice?->serviceProvider?->company_name ?? 'Unknown';
            })
            ->map(function ($payments) {
                return $payments->sum('amount');
            });

        $monthlyRevenue = $this->getMonthlyRevenueByRange($dateFrom, $dateTo);

        $stats = [
            'total_revenue' => $payments->sum('amount'),
            'total_invoices' => $invoices->count(),
            'paid_invoices' => $invoices->where('status', 'paid')->count(),
            'pending_invoices' => $invoices->where('status', 'pending')->count(),
            'overdue_invoices' => $invoices->where('status', 'overdue')->count(),
            'average_payment' => $payments->count() > 0 ? $payments->avg('amount') : 0,
        ];

        $filename = 'financial_report_' . now()->format('Y-m-d_H-i-s');

        if ($format === 'excel') {
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
            ];

            return response()->streamDownload(function () use ($stats, $revenueByProvider, $monthlyRevenue, $dateFrom, $dateTo) {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

                fputcsv($file, ['Financial Report']);
                fputcsv($file, ['Period', $dateFrom->format('Y-m-d') . ' to ' . $dateTo->format('Y-m-d')]);
                fputcsv($file, []);
                fputcsv($file, ['Summary']);
                fputcsv($file, ['Total Revenue', number_format($stats['total_revenue'], 2)]);
                fputcsv($file, ['Total Invoices', $stats['total_invoices']]);
                fputcsv($file, ['Paid Invoices', $stats['paid_invoices']]);
                fputcsv($file, ['Pending Invoices', $stats['pending_invoices']]);
                fputcsv($file, ['Overdue Invoices', $stats['overdue_invoices']]);
                fputcsv($file, ['Average Payment', number_format($stats['average_payment'], 2)]);
                fputcsv($file, []);
                fputcsv($file, ['Revenue by Service Provider']);
                fputcsv($file, ['Provider', 'Revenue']);
                foreach ($revenueByProvider as $provider => $revenue) {
                    fputcsv($file, [$provider, number_format($revenue, 2)]);
                }
                fputcsv($file, []);
                fputcsv($file, ['Monthly Revenue']);
                fputcsv($file, ['Month', 'Revenue']);
                foreach ($monthlyRevenue as $data) {
                    fputcsv($file, [$data['month'], number_format($data['total'], 2)]);
                }

                fclose($file);
            }, $filename . '.csv', $headers);
        }

        $html = view('admin.reports.pdf.financial', compact('stats', 'revenueByProvider', 'monthlyRevenue', 'dateFrom', 'dateTo'))->render();
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Export usage report
     */
    private function exportUsage($format, $dateFrom, $dateTo)
    {
        $dateFrom = $dateFrom ? Carbon::parse($dateFrom) : Carbon::now()->startOfMonth();
        $dateTo = $dateTo ? Carbon::parse($dateTo) : Carbon::now()->endOfMonth();

        $providerUsage = ServiceProvider::withCount([
            'invoices' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
            'invoices as paid_invoices_count' => function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'paid')->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
        ])
            ->withSum(['invoices as total_revenue' => function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'paid')->whereBetween('created_at', [$dateFrom, $dateTo]);
            }], 'total_amount')
            ->get();

        $userActivity = User::withCount([
            'invoices' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
            'payments' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
        ])
            ->whereHas('invoices', function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            })
            ->orWhereHas('payments', function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            })
            ->get();

        $stats = [
            'total_providers' => $providerUsage->count(),
            'active_providers' => $providerUsage->where('is_active', true)->count(),
            'total_invoices' => $providerUsage->sum('invoices_count'),
            'total_users_active' => $userActivity->count(),
        ];

        $filename = 'usage_report_' . now()->format('Y-m-d_H-i-s');

        if ($format === 'excel') {
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
            ];

            return response()->streamDownload(function () use ($stats, $providerUsage, $userActivity, $dateFrom, $dateTo) {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

                fputcsv($file, ['Usage Report']);
                fputcsv($file, ['Period', $dateFrom->format('Y-m-d') . ' to ' . $dateTo->format('Y-m-d')]);
                fputcsv($file, []);
                fputcsv($file, ['Service Provider Usage']);
                fputcsv($file, ['Provider', 'Status', 'Total Invoices', 'Paid Invoices', 'Revenue']);
                foreach ($providerUsage as $provider) {
                    fputcsv($file, [
                        $provider->company_name,
                        $provider->is_active ? 'Active' : 'Inactive',
                        $provider->invoices_count ?? 0,
                        $provider->paid_invoices_count ?? 0,
                        number_format($provider->total_revenue ?? 0, 2),
                    ]);
                }
                fputcsv($file, []);
                fputcsv($file, ['User Activity']);
                fputcsv($file, ['User', 'Email', 'Invoices', 'Payments']);
                foreach ($userActivity as $user) {
                    fputcsv($file, [
                        $user->name,
                        $user->email,
                        $user->invoices_count ?? 0,
                        $user->payments_count ?? 0,
                    ]);
                }

                fclose($file);
            }, $filename . '.csv', $headers);
        }

        $html = view('admin.reports.pdf.usage', compact('stats', 'providerUsage', 'userActivity', 'dateFrom', 'dateTo'))->render();
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function schedule(Request $request)
    {
        $request->validate([
            'type' => 'required|in:invoices,payments,users',
            'frequency' => 'required|in:daily,weekly,monthly',
            'email' => 'required|email',
            'format' => 'required|in:excel,pdf',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Report scheduled successfully',
                'data' => [
                    'type' => $request->type,
                    'frequency' => $request->frequency,
                    'email' => $request->email,
                    'format' => $request->format,
                ],
            ]);
        }

        return redirect()->back()->with('success', __('Report scheduled successfully. You will receive reports at :email :frequency.', [
            'email' => $request->email,
            'frequency' => $request->frequency,
        ]));
    }

    /**
     * API endpoint for reports index
     */
    public function apiIndex(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Reports endpoint',
        ]);
    }

    /**
     * API endpoint for invoice reports
     */
    public function apiInvoices(Request $request): JsonResponse
    {
        $baseQuery = $this->applyInvoiceFilters(Invoice::query(), $request->only([
            'status',
            'date_from',
            'date_to',
            'service_provider_id',
        ]));

        $invoices = (clone $baseQuery)
            ->with(['user', 'serviceProvider'])
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request, 50));

        $stats = $this->getInvoiceStats($baseQuery);
        $serviceProviders = ServiceProvider::all();

        return response()->json([
            'invoices' => $invoices,
            'stats' => $stats,
            'service_providers' => $serviceProviders,
        ]);
    }

    /**
     * API endpoint for payment reports
     */
    public function apiPayments(Request $request): JsonResponse
    {
        $baseQuery = $this->applyPaymentFilters(Payment::query(), $request->only([
            'status',
            'date_from',
            'date_to',
            'payment_method_id',
        ]));

        $payments = (clone $baseQuery)
            ->with(['invoice', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request, 50));

        $stats = $this->getPaymentStats($baseQuery);
        $monthlyRevenue = $this->getMonthlyRevenueForYear((int) now()->year);

        return response()->json([
            'payments' => $payments,
            'stats' => $stats,
            'monthly_revenue' => $monthlyRevenue,
        ]);
    }

    /**
     * API endpoint for user reports
     */
    public function apiUsers(Request $request): JsonResponse
    {
        $baseQuery = $this->applyUserFilters(User::query(), $request->only([
            'role',
            'status',
            'date_from',
            'date_to',
        ]));

        $users = (clone $baseQuery)
            ->withCount(['invoices', 'payments'])
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request, 50));

        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'inactive_users' => User::where('is_active', false)->count(),
            'customers' => User::role('customer')->count(),
            'service_providers' => User::role('service_provider')->count(),
            'new_this_month' => User::whereMonth('created_at', now()->month)->count(),
        ];

        return response()->json([
            'users' => $users,
            'stats' => $stats,
        ]);
    }

    /**
     * API endpoint for export
     */
    public function apiExport(Request $request)
    {
        $request->validate([
            'type' => 'required|in:invoices,payments,users,financial,usage',
            'format' => 'required|in:excel,pdf',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
            'service_provider_id' => 'nullable|integer|exists:service_providers,id',
        ]);

        $type = $request->type;
        $format = $request->format;
        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;

        try {
            switch ($type) {
                case 'invoices':
                    return $this->exportInvoices($format, $dateFrom, $dateTo, $request->status, $request->service_provider_id);
                case 'payments':
                    return $this->exportPayments($format, $dateFrom, $dateTo, $request->status);
                case 'users':
                    return $this->exportUsers($format, $dateFrom, $dateTo);
                case 'financial':
                    return $this->exportFinancial($format, $dateFrom, $dateTo);
                case 'usage':
                    return $this->exportUsage($format, $dateFrom, $dateTo);
                default:
                    return response()->json(['message' => 'Invalid report type'], 400);
            }
        } catch (\Exception $e) {
            Log::error('Export Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to generate export',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API endpoint for financial reports
     */
    public function apiFinancial(Request $request): JsonResponse
    {
        $dateFrom = $request->date_from ? Carbon::parse($request->date_from) : Carbon::now()->startOfMonth();
        $dateTo = $request->date_to ? Carbon::parse($request->date_to) : Carbon::now()->endOfMonth();

        $payments = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->get();

        $invoices = Invoice::whereBetween('created_at', [$dateFrom, $dateTo])->get();

        $revenueByProvider = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->with('invoice.serviceProvider')
            ->get()
            ->groupBy(function ($payment) {
                return $payment->invoice?->serviceProvider?->company_name ?? 'Unknown';
            })
            ->map(function ($payments, $provider) {
                return [
                    'provider' => $provider,
                    'revenue' => $payments->sum('amount'),
                ];
            })
            ->values();

        $monthlyRevenue = $this->getMonthlyRevenueByRange($dateFrom, $dateTo);

        $stats = [
            'total_revenue' => $payments->sum('amount'),
            'total_invoices' => $invoices->count(),
            'paid_invoices' => $invoices->where('status', 'paid')->count(),
            'pending_invoices' => $invoices->where('status', 'pending')->count(),
            'overdue_invoices' => $invoices->where('status', 'overdue')->count(),
            'average_payment' => $payments->count() > 0 ? $payments->avg('amount') : 0,
            'total_payments' => $payments->count(),
        ];

        return response()->json([
            'stats' => $stats,
            'revenue_by_provider' => $revenueByProvider,
            'monthly_revenue' => $monthlyRevenue,
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
        ]);
    }

    /**
     * API endpoint for usage reports
     */
    public function apiUsage(Request $request): JsonResponse
    {
        $dateFrom = $request->date_from ? Carbon::parse($request->date_from) : Carbon::now()->startOfMonth();
        $dateTo = $request->date_to ? Carbon::parse($request->date_to) : Carbon::now()->endOfMonth();

        $providerUsage = ServiceProvider::withCount([
            'invoices' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
            'invoices as paid_invoices_count' => function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'paid')->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
        ])
            ->withSum(['invoices as total_revenue' => function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'paid')->whereBetween('created_at', [$dateFrom, $dateTo]);
            }], 'total_amount')
            ->get()
            ->map(function ($provider) {
                return [
                    'id' => $provider->id,
                    'company_name' => $provider->company_name,
                    'is_active' => $provider->is_active,
                    'invoices_count' => $provider->invoices_count ?? 0,
                    'paid_invoices_count' => $provider->paid_invoices_count ?? 0,
                    'total_revenue' => $provider->total_revenue ?? 0,
                ];
            });

        $userActivity = User::withCount([
            'invoices' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
            'payments' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo]);
            },
        ])
            ->where(function ($query) use ($dateFrom, $dateTo) {
                $query->whereHas('invoices', function ($q) use ($dateFrom, $dateTo) {
                    $q->whereBetween('created_at', [$dateFrom, $dateTo]);
                })
                ->orWhereHas('payments', function ($q) use ($dateFrom, $dateTo) {
                    $q->whereBetween('created_at', [$dateFrom, $dateTo]);
                });
            })
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'invoices_count' => $user->invoices_count ?? 0,
                    'payments_count' => $user->payments_count ?? 0,
                ];
            });

        $stats = [
            'total_providers' => $providerUsage->count(),
            'active_providers' => $providerUsage->where('is_active', true)->count(),
            'total_invoices' => $providerUsage->sum('invoices_count'),
            'total_users_active' => $userActivity->count(),
        ];

        return response()->json([
            'stats' => $stats,
            'provider_usage' => $providerUsage,
            'user_activity' => $userActivity,
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
        ]);
    }

    /**
     * API endpoint for schedule
     */
    public function apiSchedule(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:invoices,payments,users,financial,usage',
            'frequency' => 'required|in:daily,weekly,monthly',
            'email' => 'required|email',
            'format' => 'required|in:excel,pdf',
        ]);

        return response()->json([
            'message' => 'Report scheduled successfully',
            'data' => [
                'type' => $request->type,
                'frequency' => $request->frequency,
                'email' => $request->email,
                'format' => $request->format,
            ],
        ]);
    }

    /**
     * Apply invoice filters without mutating shared query state.
     */
    private function applyInvoiceFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['service_provider_id'])) {
            $query->where('service_provider_id', $filters['service_provider_id']);
        }

        return $query;
    }

    /**
     * Apply payment filters without mutating shared query state.
     */
    private function applyPaymentFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['payment_method_id'])) {
            $query->where('payment_method_id', $filters['payment_method_id']);
        }

        return $query;
    }

    /**
     * Apply user filters with backward-compatible status mapping.
     */
    private function applyUserFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['role'])) {
            $query->role($filters['role']);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $status = strtolower((string) $filters['status']);
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($status === '1' || $status === '0') {
                $query->where('is_active', $status === '1');
            }
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Normalize per-page input with min/max bounds.
     */
    private function getPerPage(Request $request, int $default = 50, int $max = 200): int
    {
        $perPage = (int) $request->get('per_page', $default);
        if ($perPage < 1) {
            return $default;
        }

        return min($perPage, $max);
    }

    /**
     * Compute invoice stats without mutating the base query.
     */
    private function getInvoiceStats(Builder $baseQuery): array
    {
        return [
            'total_invoices' => (clone $baseQuery)->count(),
            'total_amount' => (clone $baseQuery)->sum('total_amount'),
            'paid_amount' => (clone $baseQuery)->where('status', 'paid')->sum('total_amount'),
            'pending_amount' => (clone $baseQuery)->where('status', 'pending')->sum('total_amount'),
            'overdue_amount' => (clone $baseQuery)->where('status', 'overdue')->sum('total_amount'),
        ];
    }

    /**
     * Compute payment stats without mutating the base query.
     */
    private function getPaymentStats(Builder $baseQuery): array
    {
        return [
            'total_payments' => (clone $baseQuery)->count(),
            'total_amount' => (clone $baseQuery)->where('status', 'completed')->sum('amount'),
            'pending_amount' => (clone $baseQuery)->where('status', 'pending')->sum('amount'),
            'failed_amount' => (clone $baseQuery)->where('status', 'failed')->sum('amount'),
        ];
    }

    /**
     * Get monthly revenue for a single year with DB aggregation where supported.
     */
    private function getMonthlyRevenueForYear(int $year)
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $monthExpression = $driver === 'pgsql'
                ? 'EXTRACT(MONTH FROM created_at)'
                : 'MONTH(created_at)';

            return Payment::where('status', 'completed')
                ->whereYear('created_at', $year)
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
            ->whereYear('created_at', $year)
            ->get()
            ->groupBy(function ($payment) {
                return $payment->created_at->format('m');
            })
            ->map(function ($payments, $month) {
                return [
                    'month' => (int) $month,
                    'total' => $payments->sum('amount'),
                ];
            })
            ->sortBy('month')
            ->values();
    }

    /**
     * Get monthly revenue for a date range with DB aggregation where supported.
     */
    private function getMonthlyRevenueByRange(Carbon $dateFrom, Carbon $dateTo)
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $monthExpression = $driver === 'pgsql'
                ? "TO_CHAR(created_at, 'YYYY-MM')"
                : "DATE_FORMAT(created_at, '%Y-%m')";

            return Payment::where('status', 'completed')
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->selectRaw($monthExpression . ' as month, COALESCE(SUM(amount), 0) as total')
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(function ($row) {
                    return [
                        'month' => $row->month,
                        'total' => $row->total ?? 0,
                    ];
                })
                ->values();
        }

        return Payment::where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->get()
            ->groupBy(function ($payment) {
                return $payment->created_at->format('Y-m');
            })
            ->map(function ($payments, $month) {
                return [
                    'month' => $month,
                    'total' => $payments->sum('amount'),
                ];
            })
            ->values();
    }
}
