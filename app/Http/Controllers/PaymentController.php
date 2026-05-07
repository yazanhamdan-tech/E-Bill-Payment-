<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Services\WebhookService;
use App\Services\ActivityLogService;
use App\Notifications\PaymentCompleted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;

class PaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    private function denyServiceProviderPayments(Request $request)
    {
        $user = Auth::user();

        if ($user && $user->hasRole('service_provider')) {
            $message = 'Service providers are not allowed to make payments.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            return redirect()->route('dashboard')->with('error', __($message));
        }

        return null;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Payment::with(['invoice', 'paymentMethod', 'user']);

        // Filter based on user role
        if ($user->hasRole('customer')) {
            $query->where('user_id', $user->id);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if ($serviceProvider) {
                $query->whereHas('invoice', function($q) use ($serviceProvider) {
                    $q->where('service_provider_id', $serviceProvider->id);
                });
            }
        }

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('payment_reference', 'like', "%{$search}%")
                  ->orWhere('gateway_transaction_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate(15);

        if ($request->expectsJson()) {
            return response()->json($payments);
        }

        return view('payments.index', compact('payments'));
    }

    /**
     * API endpoint for listing payments
     */
    public function apiIndex(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    /**
     * Get payment tracking information
     */
    public function track(Request $request, Payment $payment): JsonResponse
    {
        $user = Auth::user();

        // Check authorization
        if ($user->hasRole('customer') && $payment->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Load relationships
        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user', 'refunds']);

        // Get activity logs for this payment
        try {
            $activityLogs = \App\Models\ActivityLog::where('subject_type', Payment::class)
                ->where('subject_id', $payment->id)
                ->orderBy('created_at', 'desc')
                ->with('user')
                ->get();
        } catch (\Exception $e) {
            // Activity logs table might not exist, use empty array
            $activityLogs = collect([]);
        }

        // Build status timeline
        $timeline = [];
        
        // Payment created
        $timeline[] = [
            'status' => 'created',
            'label' => 'Payment Created',
            'description' => 'Payment request initiated',
            'timestamp' => $payment->created_at,
            'completed' => true,
        ];

        // Status changes
        if ($payment->status === 'processing') {
            $timeline[] = [
                'status' => 'processing',
                'label' => 'Processing',
                'description' => 'Payment is being processed',
                'timestamp' => $payment->updated_at,
                'completed' => false,
                'current' => true,
            ];
        } elseif ($payment->status === 'completed') {
            $timeline[] = [
                'status' => 'processing',
                'label' => 'Processing',
                'description' => 'Payment was processed',
                'timestamp' => $payment->updated_at,
                'completed' => true,
            ];
            $timeline[] = [
                'status' => 'completed',
                'label' => 'Completed',
                'description' => $payment->processed_at 
                    ? 'Payment completed on ' . $payment->processed_at->format('M d, Y H:i')
                    : 'Payment completed successfully',
                'timestamp' => $payment->processed_at ?? $payment->updated_at,
                'completed' => true,
                'current' => true,
            ];
        } elseif ($payment->status === 'failed') {
            $timeline[] = [
                'status' => 'failed',
                'label' => 'Failed',
                'description' => $payment->notes ?? 'Payment processing failed',
                'timestamp' => $payment->updated_at,
                'completed' => true,
                'current' => true,
            ];
        } elseif ($payment->status === 'refunded') {
            $timeline[] = [
                'status' => 'completed',
                'label' => 'Completed',
                'description' => 'Payment was completed',
                'timestamp' => $payment->processed_at ?? $payment->updated_at,
                'completed' => true,
            ];
            $timeline[] = [
                'status' => 'refunded',
                'label' => 'Refunded',
                'description' => 'Payment has been refunded',
                'timestamp' => $payment->refunds()->latest()->first()?->created_at ?? $payment->updated_at,
                'completed' => true,
                'current' => true,
            ];
        } else {
            // Pending
            $timeline[] = [
                'status' => 'pending',
                'label' => 'Pending',
                'description' => 'Waiting for payment processing',
                'timestamp' => $payment->updated_at,
                'completed' => false,
                'current' => true,
            ];
        }

        // Add refunds to timeline
        foreach ($payment->refunds as $refund) {
            $timeline[] = [
                'status' => 'refund',
                'label' => 'Refund ' . ucfirst($refund->status),
                'description' => $refund->refund_type === 'full' ? 'Full refund' : "Partial refund: $" . number_format($refund->amount, 2),
                'timestamp' => $refund->created_at,
                'completed' => $refund->status === 'completed',
                'refund' => true,
            ];
        }

        // Sort timeline by timestamp and convert to ISO strings
        usort($timeline, function($a, $b) {
            $timeA = $a['timestamp'] instanceof \Carbon\Carbon 
                ? $a['timestamp']->timestamp 
                : strtotime($a['timestamp']);
            $timeB = $b['timestamp'] instanceof \Carbon\Carbon 
                ? $b['timestamp']->timestamp 
                : strtotime($b['timestamp']);
            return $timeA - $timeB;
        });

        // Convert timestamps to ISO strings for JSON response
        $timeline = array_map(function($item) {
            if ($item['timestamp'] instanceof \Carbon\Carbon) {
                $item['timestamp'] = $item['timestamp']->toIso8601String();
            } elseif (is_string($item['timestamp'])) {
                // Already a string, keep it
            } else {
                $item['timestamp'] = now()->toIso8601String();
            }
            return $item;
        }, $timeline);

        return response()->json([
            'payment' => $payment,
            'timeline' => $timeline,
            'activity_logs' => $activityLogs,
            'estimated_completion' => $payment->status === 'processing' 
                ? now()->addMinutes(5)->toIso8601String() 
                : null,
        ]);
    }

    /**
     * API endpoint for payment tracking
     */
    public function apiTrack(Request $request, Payment $payment): JsonResponse
    {
        return $this->track($request, $payment);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        if ($response = $this->denyServiceProviderPayments($request)) {
            return $response;
        }

        $user = Auth::user();
        if (!$user->hasRole('customer')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            abort(403);
        }

        $invoiceId = $request->get('invoice_id');
        
        if (!$invoiceId) {
            return redirect()->route('invoices.index')
                           ->with('error', __('Please select an invoice to pay.'));
        }

        $invoice = Invoice::findOrFail($invoiceId);
        
        // Check authorization
        if ($user->hasRole('customer') -and $invoice->user_id -ne Auth::id()) {
            abort(403);
        }

        // Check if invoice is already paid
        if ($invoice->status -eq 'paid') {
            return redirect()->route('invoices.show', $invoice)
                           ->with('error', __('This invoice is already paid.'));
        }

        $paymentMethods = PaymentMethod::where('user_id', Auth::id())
                                     ->where('is_active', true)
                                     ->get();

        return view('payments.create', compact('invoice', 'paymentMethods'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($response = $this->denyServiceProviderPayments($request)) {
            return $response;
        }

        $user = Auth::user();
        if (!$user->hasRole('customer')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            abort(403);
        }

        // Log incoming request for debugging
        \Log::info('Payment creation request', [
            'data' => $request->all(),
            'data_types' => [
                'invoice_id' => gettype($request->input('invoice_id')),
                'payment_method_id' => gettype($request->input('payment_method_id')),
                'amount' => gettype($request->input('amount')),
                'payment_type' => gettype($request->input('payment_type')),
            ],
            'user_id' => Auth::id(),
        ]);

        try {
            $validated = $request->validate([
                'invoice_id' => 'required|integer|exists:invoices,id',
                'payment_method_id' => 'required|integer|exists:payment_methods,id',
                'amount' => 'required|numeric|min:0.01',
                'payment_type' => 'required|string|in:full,partial,installment',
                'installment_payment_id' => 'required_if:payment_type,installment|nullable|integer|exists:installment_payments,id',
                'gateway' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Payment validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all(),
                'request_data_types' => [
                    'invoice_id' => gettype($request->input('invoice_id')),
                    'payment_method_id' => gettype($request->input('payment_method_id')),
                    'amount' => gettype($request->input('amount')),
                    'payment_type' => gettype($request->input('payment_type')),
                ],
            ]);
            
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                    'request_data' => $request->all(), // Include request data for debugging
                ], 422);
            }
            throw $e;
        }

        $invoice = Invoice::with('payments')->findOrFail($validated['invoice_id']);
        
        // Check authorization
        if ($user->hasRole('customer') -and $invoice->user_id -ne Auth::id()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthorized',
                    'errors' => ['invoice_id' => ['You do not have permission to pay this invoice.']]
                ], 403);
            }
            abort(403);
        }

        // Check if invoice is cancelled
        if ($invoice->status -eq 'cancelled') {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Cannot pay a cancelled invoice.',
                    'errors' => ['invoice_id' => ['Cannot pay a cancelled invoice.']]
                ], 422);
            }
            return redirect()->back()->with('error', __('Cannot pay a cancelled invoice.'));
        }
        
        // Calculate remaining amount to check if invoice can be paid
        // Use remaining amount instead of status, as status might be incorrect
        $remainingAmount = $invoice->remaining_amount;
        
        // Log invoice status for debugging
        \Log::info('Invoice payment check', [
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'total_amount' => $invoice->total_amount,
            'total_paid' => $invoice->total_paid,
            'remaining_amount' => $remainingAmount,
            'payments_count' => $invoice->payments->count(),
        ]);
        
        // Check if invoice is already fully paid (by remaining amount, not just status)
        if ($remainingAmount -le 0) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This invoice is already fully paid.',
                    'errors' => ['invoice_id' => ['This invoice is already fully paid. Remaining amount: 
     */
    public function apiStore(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * Display the specified resource.
     */
    public function show(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                abort(403);
            }
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);

        if (request()->expectsJson()) {
            return response()->json($payment);
        }

        return view('payments.show', compact('payment'));
    }

    /**
     * API endpoint for showing payment
     */
    public function apiShow(Payment $payment): JsonResponse
    {
        return $this->show($payment);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payment $payment)
    {
        // Only allow deletion if payment is pending or failed
        if (!in_array($payment->status, ['pending', 'failed'])) {
            return redirect()->back()->with('error', __('Cannot delete completed payments.'));
        }

        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        $payment->delete();

        if (request()->expectsJson()) {
            return response()->json(['message' => 'Payment deleted successfully.']);
        }

        return redirect()->route('payments.index')
                        ->with('success', __('Payment deleted successfully.'));
    }

    /**
     * API endpoint for deleting payment
     */
    public function apiDestroy(Payment $payment): JsonResponse
    {
        return $this->destroy($payment);
    }

    /**
     * Download payment receipt as PDF
     */
    public function receipt(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        if ($payment->status !== 'completed') {
            return redirect()->back()->with('error', __('Receipt is only available for completed payments.'));
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);
        
        $pdf = Pdf::loadView('payments.receipt', compact('payment'));
        
        return $pdf->download("receipt-{$payment->payment_reference}.pdf");
    }

    /**
     * API endpoint for downloading payment receipt
     */
    public function apiReceipt(Payment $payment)
    {
        return $this->receipt($payment);
    }

    /**
     * Process/Complete a pending payment
     * This endpoint allows admins or service providers to manually complete payments
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }
        $user = Auth::user();

        // Check authorization - only admins and service providers can process payments
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Service providers can only process payments for their own invoices
        if ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment can be processed
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment cannot be processed',
                'error' => 'Only pending payments can be processed. Current status: ' . $payment->status
            ], 422);
        }

        try {
            // Process payment through gateway service
            // This handles gateway communication, balance updates, and payment completion
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            $result = $gatewayService->processPayment($payment, [
                'gateway_transaction_id' => $request->input('gateway_transaction_id'),
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Payment processing failed',
                    'error' => $result['message']
                ], 422);
            }

            // Load installment payment if exists
            $installmentPayment = null;
            if ($payment->payment_type === 'installment') {
                // Reload payment to get updated status
                $payment->refresh();
                
                // Find installment payment by payment_id (if already linked) or invoice
                $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                    ->where('status', 'pending')
                    ->where('amount', $payment->amount)
                    ->orderBy('due_date')
                    ->first();

                if ($installmentPayment) {
                    // Verify payment amount matches installment amount
                    $paymentAmount = (float) $payment->amount;
                    $installmentAmount = (float) $installmentPayment->amount;
                    
                    if (abs($paymentAmount - $installmentAmount) <= 0.01) {
                        $installmentPayment->markAsPaid($payment->id);
                        
                        \Log::info('Installment payment marked as paid during processing', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                            'amount' => $installmentAmount,
                        ]);
                    }
                }
            }

            // Reload payment with relationships (including fresh invoice data)
            $payment->refresh();
            // Reload invoice separately to ensure fresh calculated fields
            $invoice = $payment->invoice;
            if ($invoice) {
                $invoice->refresh();
                // Reload relationships to ensure calculated fields are up to date
                $invoice->load(['payments', 'refunds']);
                // Recalculate and refresh invoice status if needed
                // Use the model's accessors which now account for refunds
                $totalPaid = $invoice->total_paid;
                $totalRefunded = $invoice->total_refunded;
                $netPaid = $invoice->net_paid;
                $remainingAmount = $invoice->remaining_amount;
                
                // Use a small tolerance for floating-point comparison (0.01 cents)
                $tolerance = 0.01;
                
                // Ensure invoice status is correct
                if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                    \Log::info('Invoice marked as paid during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                    ]);
                    $invoice->markAsPaid();
                    $invoice->refresh();
                } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                    $invoice->update(['status' => 'pending', 'paid_date' => null]);
                    $invoice->refresh();
                } else {
                    // Log the current state for debugging
                    \Log::info('Invoice status check during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'current_status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                        'payment_amount' => $payment->amount,
                    ]);
                }
            }
            // Now load payment relationships with fresh invoice
            $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

            // Send notification to customer
            if ($payment->user) {
                try {
                    $payment->user->notify(new PaymentCompleted($payment));
                } catch (\Exception $e) {
                    \Log::error('Failed to send payment completed notification', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Dispatch webhook for payment completed
            if ($payment->invoice && $payment->invoice->serviceProvider) {
                try {
                    app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                } catch (\Exception $e) {
                    \Log::error('Failed to dispatch payment completed webhook', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Payment processed successfully',
                'payment' => $payment
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment processing failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $payment->markAsFailed($e->getMessage());
            } catch (\Exception $markFailedException) {
                \Log::error('Failed to mark payment as failed', [
                    'payment_id' => $payment->id ?? null,
                    'error' => $markFailedException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * API endpoint for processing payment
     */
    public function apiProcess(Request $request, Payment $payment): JsonResponse
    {
        return $this->process($request, $payment);
    }

    /**
     * Get redirect URL for payment gateway
     * This is called when user initiates payment to get the gateway URL
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }

        \ = Auth::user();
        if (!\->hasRole('customer')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment is in a valid state for redirect
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment is not in pending status',
                'status' => $payment->status,
            ], 422);
        }

        try {
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            
            $baseUrl = config('app.url', 'http://localhost:8000');
            $returnUrl = $request->input('return_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=success');
            $cancelUrl = $request->input('cancel_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=cancelled');

            $result = $gatewayService->getRedirectUrl($payment, [
                'base_url' => $baseUrl,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Failed to generate redirect URL',
                    'error' => $result['message'],
                ], 500);
            }

            return response()->json([
                'redirect_url' => $result['redirect_url'],
                'payment_id' => $payment->id,
                'message' => 'Redirect URL generated successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get payment redirect URL', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to generate redirect URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mock gateway confirmation page
     * This simulates the external payment gateway page where user confirms payment
     */
    public function gatewayConfirm(Request $request, Payment $payment)
    {
        // Verify payment exists and is pending
        if ($payment->status !== 'pending') {
            return view('payments.gateway-confirm', [
                'payment' => $payment,
                'error' => 'Payment is not in pending status',
            ]);
        }

        $returnUrl = $request->input('return_url');
        $cancelUrl = $request->input('cancel_url');

        return view('payments.gateway-confirm', [
            'payment' => $payment,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);
    }

    /**
     * Handle payment gateway callback
     * This is called after user confirms or cancels payment on the gateway
     * Can return JSON for API calls or redirect for web requests
     * This endpoint can be called without authentication (public route)
     */
    public function callback(Request $request, Payment $payment)
    {
        // Verify payment reference if provided (for security)
        $paymentReference = $request->input('payment_reference');
        if ($paymentReference && $payment->payment_reference !== $paymentReference) {
            \Log::warning('Payment callback verification failed', [
                'payment_id' => $payment->id,
                'expected_reference' => $payment->payment_reference,
                'provided_reference' => $paymentReference,
            ]);
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment reference',
                ], 403);
            }
            
            return redirect('/')->with('error', 'Invalid payment reference');
        }

        $status = $request->input('status'); // 'success' or 'cancelled'
        $transactionId = $request->input('transaction_id');
        $gatewayResponse = $request->all();

        \Log::info('Payment gateway callback received', [
            'payment_id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'status' => $status,
            'transaction_id' => $transactionId,
            'gateway_response' => $gatewayResponse,
        ]);

        try {
            if ($status === 'success' || $status === 'completed') {
                // Process payment through gateway service
                // This handles gateway communication, balance updates, and payment completion
                $gatewayService = app(\App\Services\PaymentGatewayService::class);
                $result = $gatewayService->processPayment($payment, [
                    'gateway_transaction_id' => $transactionId,
                    'gateway_response' => $gatewayResponse,
                ]);

                if (!$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment processing failed',
                        'error' => $result['message'],
                        'payment' => $payment,
                    ], 422);
                }

                // Load installment payment if exists
                $installmentPayment = null;
                if ($payment->payment_type === 'installment') {
                    // Reload payment to get updated status
                    $payment->refresh();
                    
                    // Find installment payment by payment_id (if already linked) or invoice
                    $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                        ->where('status', 'pending')
                        ->where('amount', $payment->amount)
                        ->orderBy('due_date')
                        ->first();

                    if ($installmentPayment) {
                        // Mark installment as paid
                        $installmentPayment->update([
                            'status' => 'paid',
                            'paid_at' => now(),
                            'payment_id' => $payment->id,
                        ]);
                        \Log::info('Installment payment marked as paid', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                        ]);
                    }
                }

                // Ensure invoice status is updated correctly
                $invoice = $payment->invoice;
                if ($invoice) {
                    $invoice->refresh();
                    // Reload relationships to ensure calculated fields are up to date
                    $invoice->load(['payments', 'refunds']);
                    // Recalculate and refresh invoice status if needed
                    // Use the model's accessors which now account for refunds
                    $totalPaid = $invoice->total_paid;
                    $totalRefunded = $invoice->total_refunded;
                    $netPaid = $invoice->net_paid;
                    $remainingAmount = $invoice->remaining_amount;
                    
                    // Use a small tolerance for floating-point comparison (0.01 cents)
                    $tolerance = 0.01;
                    
                    // Ensure invoice status is correct
                    if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                        \Log::info('Invoice marked as paid during payment callback', [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'total_amount' => $invoice->total_amount,
                            'total_paid' => $totalPaid,
                            'total_refunded' => $totalRefunded,
                            'net_paid' => $netPaid,
                            'remaining_amount' => $remainingAmount,
                            'payment_id' => $payment->id,
                        ]);
                        $invoice->markAsPaid();
                        $invoice->refresh();
                    } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                        $invoice->update(['status' => 'pending', 'paid_date' => null]);
                        $invoice->refresh();
                    }
                }
                
                // Reload payment with all relationships
                $payment->refresh();
                $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

                // Send notification to customer
                if ($payment->user) {
                    try {
                        $payment->user->notify(new PaymentCompleted($payment));
                    } catch (\Exception $e) {
                        \Log::error('Failed to send payment completed notification', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Dispatch webhook for payment completed
                if ($payment->invoice && $payment->invoice->serviceProvider) {
                    try {
                        app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                    } catch (\Exception $e) {
                        \Log::error('Failed to dispatch payment completed webhook', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // If this is a web request (from gateway redirect), redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=success');
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment completed successfully',
                    'payment' => $payment,
                ]);
            } else {
                // Payment was cancelled
                $payment->update(['status' => 'cancelled']);

                // If this is a web request, redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=cancelled');
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Payment was cancelled',
                    'payment' => $payment,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Payment callback processing error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If this is a web request, redirect to frontend with error
            if (!$request->expectsJson()) {
                $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=error&error=' . urlencode($e->getMessage()));
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment callback',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}



 . number_format($remainingAmount, 2)]]
                ], 422);
            }
            return redirect()->back()->with('error', __('This invoice is already fully paid.'));
        }

        // Validate payment method belongs to user
        $paymentMethod = PaymentMethod::where('id', $validated['payment_method_id'])
                                     ->where('user_id', Auth::id())
                                     ->where('is_active', true)
                                     ->first();

        if (!$paymentMethod) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Payment method not found or inactive.',
                    'errors' => ['payment_method_id' => ['Payment method not found or inactive.']]
                ], 422);
            }
            return redirect()->back()->with('error', __('Payment method not found or inactive.'));
        }

        // Calculate remaining amount
        $remainingAmount = $invoice->remaining_amount;

        // Handle installment payment
        $installmentPayment = null;
        if ($validated['payment_type'] === 'installment') {
            if (!isset($validated['installment_payment_id'])) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Installment payment ID is required for installment payments.',
                        'errors' => ['installment_payment_id' => ['Installment payment ID is required.']]
                    ], 422);
                }
                return redirect()->back()->with('error', __('Installment payment ID is required.'));
            }

            $installmentPayment = \App\Models\InstallmentPayment::find($validated['installment_payment_id']);
            
            if (!$installmentPayment) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Installment payment not found.',
                        'errors' => ['installment_payment_id' => ['Installment payment not found.']]
                    ], 422);
                }
                return redirect()->back()->with('error', __('Installment payment not found.'));
            }

            // Verify installment belongs to invoice
            if ($installmentPayment->invoice_id !== $invoice->id) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Installment payment does not belong to this invoice.',
                        'errors' => ['installment_payment_id' => ['Installment payment does not belong to this invoice.']]
                    ], 422);
                }
                return redirect()->back()->with('error', __('Installment payment does not belong to this invoice.'));
            }

            // Check if installment is already paid
            if ($installmentPayment->status === 'paid') {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'This installment is already paid.',
                        'errors' => ['installment_payment_id' => ['This installment is already paid.']]
                    ], 422);
                }
                return redirect()->back()->with('error', __('This installment is already paid.'));
            }

            // Use installment amount - ensure it's properly formatted as decimal
            $installmentAmount = (float) $installmentPayment->amount;
            $validated['amount'] = round($installmentAmount, 2);
            
            // Log for debugging
            \Log::info('Installment payment amount set', [
                'installment_payment_id' => $installmentPayment->id,
                'installment_amount' => $installmentAmount,
                'formatted_amount' => $validated['amount'],
                'installment_number' => $installmentPayment->installment_number,
            ]);
        } elseif ($validated['payment_type'] === 'full') {
            $validated['amount'] = $remainingAmount;
        } else {
            // Validate amount doesn't exceed remaining
            if ($validated['amount'] > $remainingAmount) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Payment amount cannot exceed remaining amount.',
                        'errors' => ['amount' => ['Payment amount cannot exceed remaining amount of 
     */
    public function apiStore(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * Display the specified resource.
     */
    public function show(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                abort(403);
            }
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);

        if (request()->expectsJson()) {
            return response()->json($payment);
        }

        return view('payments.show', compact('payment'));
    }

    /**
     * API endpoint for showing payment
     */
    public function apiShow(Payment $payment): JsonResponse
    {
        return $this->show($payment);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payment $payment)
    {
        // Only allow deletion if payment is pending or failed
        if (!in_array($payment->status, ['pending', 'failed'])) {
            return redirect()->back()->with('error', __('Cannot delete completed payments.'));
        }

        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        $payment->delete();

        if (request()->expectsJson()) {
            return response()->json(['message' => 'Payment deleted successfully.']);
        }

        return redirect()->route('payments.index')
                        ->with('success', __('Payment deleted successfully.'));
    }

    /**
     * API endpoint for deleting payment
     */
    public function apiDestroy(Payment $payment): JsonResponse
    {
        return $this->destroy($payment);
    }

    /**
     * Download payment receipt as PDF
     */
    public function receipt(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        if ($payment->status !== 'completed') {
            return redirect()->back()->with('error', __('Receipt is only available for completed payments.'));
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);
        
        $pdf = Pdf::loadView('payments.receipt', compact('payment'));
        
        return $pdf->download("receipt-{$payment->payment_reference}.pdf");
    }

    /**
     * API endpoint for downloading payment receipt
     */
    public function apiReceipt(Payment $payment)
    {
        return $this->receipt($payment);
    }

    /**
     * Process/Complete a pending payment
     * This endpoint allows admins or service providers to manually complete payments
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }
        $user = Auth::user();

        // Check authorization - only admins and service providers can process payments
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Service providers can only process payments for their own invoices
        if ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment can be processed
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment cannot be processed',
                'error' => 'Only pending payments can be processed. Current status: ' . $payment->status
            ], 422);
        }

        try {
            // Process payment through gateway service
            // This handles gateway communication, balance updates, and payment completion
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            $result = $gatewayService->processPayment($payment, [
                'gateway_transaction_id' => $request->input('gateway_transaction_id'),
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Payment processing failed',
                    'error' => $result['message']
                ], 422);
            }

            // Load installment payment if exists
            $installmentPayment = null;
            if ($payment->payment_type === 'installment') {
                // Reload payment to get updated status
                $payment->refresh();
                
                // Find installment payment by payment_id (if already linked) or invoice
                $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                    ->where('status', 'pending')
                    ->where('amount', $payment->amount)
                    ->orderBy('due_date')
                    ->first();

                if ($installmentPayment) {
                    // Verify payment amount matches installment amount
                    $paymentAmount = (float) $payment->amount;
                    $installmentAmount = (float) $installmentPayment->amount;
                    
                    if (abs($paymentAmount - $installmentAmount) <= 0.01) {
                        $installmentPayment->markAsPaid($payment->id);
                        
                        \Log::info('Installment payment marked as paid during processing', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                            'amount' => $installmentAmount,
                        ]);
                    }
                }
            }

            // Reload payment with relationships (including fresh invoice data)
            $payment->refresh();
            // Reload invoice separately to ensure fresh calculated fields
            $invoice = $payment->invoice;
            if ($invoice) {
                $invoice->refresh();
                // Reload relationships to ensure calculated fields are up to date
                $invoice->load(['payments', 'refunds']);
                // Recalculate and refresh invoice status if needed
                // Use the model's accessors which now account for refunds
                $totalPaid = $invoice->total_paid;
                $totalRefunded = $invoice->total_refunded;
                $netPaid = $invoice->net_paid;
                $remainingAmount = $invoice->remaining_amount;
                
                // Use a small tolerance for floating-point comparison (0.01 cents)
                $tolerance = 0.01;
                
                // Ensure invoice status is correct
                if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                    \Log::info('Invoice marked as paid during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                    ]);
                    $invoice->markAsPaid();
                    $invoice->refresh();
                } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                    $invoice->update(['status' => 'pending', 'paid_date' => null]);
                    $invoice->refresh();
                } else {
                    // Log the current state for debugging
                    \Log::info('Invoice status check during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'current_status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                        'payment_amount' => $payment->amount,
                    ]);
                }
            }
            // Now load payment relationships with fresh invoice
            $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

            // Send notification to customer
            if ($payment->user) {
                try {
                    $payment->user->notify(new PaymentCompleted($payment));
                } catch (\Exception $e) {
                    \Log::error('Failed to send payment completed notification', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Dispatch webhook for payment completed
            if ($payment->invoice && $payment->invoice->serviceProvider) {
                try {
                    app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                } catch (\Exception $e) {
                    \Log::error('Failed to dispatch payment completed webhook', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Payment processed successfully',
                'payment' => $payment
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment processing failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $payment->markAsFailed($e->getMessage());
            } catch (\Exception $markFailedException) {
                \Log::error('Failed to mark payment as failed', [
                    'payment_id' => $payment->id ?? null,
                    'error' => $markFailedException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * API endpoint for processing payment
     */
    public function apiProcess(Request $request, Payment $payment): JsonResponse
    {
        return $this->process($request, $payment);
    }

    /**
     * Get redirect URL for payment gateway
     * This is called when user initiates payment to get the gateway URL
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }

        \ = Auth::user();
        if (!\->hasRole('customer')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment is in a valid state for redirect
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment is not in pending status',
                'status' => $payment->status,
            ], 422);
        }

        try {
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            
            $baseUrl = config('app.url', 'http://localhost:8000');
            $returnUrl = $request->input('return_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=success');
            $cancelUrl = $request->input('cancel_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=cancelled');

            $result = $gatewayService->getRedirectUrl($payment, [
                'base_url' => $baseUrl,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Failed to generate redirect URL',
                    'error' => $result['message'],
                ], 500);
            }

            return response()->json([
                'redirect_url' => $result['redirect_url'],
                'payment_id' => $payment->id,
                'message' => 'Redirect URL generated successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get payment redirect URL', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to generate redirect URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mock gateway confirmation page
     * This simulates the external payment gateway page where user confirms payment
     */
    public function gatewayConfirm(Request $request, Payment $payment)
    {
        // Verify payment exists and is pending
        if ($payment->status !== 'pending') {
            return view('payments.gateway-confirm', [
                'payment' => $payment,
                'error' => 'Payment is not in pending status',
            ]);
        }

        $returnUrl = $request->input('return_url');
        $cancelUrl = $request->input('cancel_url');

        return view('payments.gateway-confirm', [
            'payment' => $payment,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);
    }

    /**
     * Handle payment gateway callback
     * This is called after user confirms or cancels payment on the gateway
     * Can return JSON for API calls or redirect for web requests
     * This endpoint can be called without authentication (public route)
     */
    public function callback(Request $request, Payment $payment)
    {
        // Verify payment reference if provided (for security)
        $paymentReference = $request->input('payment_reference');
        if ($paymentReference && $payment->payment_reference !== $paymentReference) {
            \Log::warning('Payment callback verification failed', [
                'payment_id' => $payment->id,
                'expected_reference' => $payment->payment_reference,
                'provided_reference' => $paymentReference,
            ]);
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment reference',
                ], 403);
            }
            
            return redirect('/')->with('error', 'Invalid payment reference');
        }

        $status = $request->input('status'); // 'success' or 'cancelled'
        $transactionId = $request->input('transaction_id');
        $gatewayResponse = $request->all();

        \Log::info('Payment gateway callback received', [
            'payment_id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'status' => $status,
            'transaction_id' => $transactionId,
            'gateway_response' => $gatewayResponse,
        ]);

        try {
            if ($status === 'success' || $status === 'completed') {
                // Process payment through gateway service
                // This handles gateway communication, balance updates, and payment completion
                $gatewayService = app(\App\Services\PaymentGatewayService::class);
                $result = $gatewayService->processPayment($payment, [
                    'gateway_transaction_id' => $transactionId,
                    'gateway_response' => $gatewayResponse,
                ]);

                if (!$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment processing failed',
                        'error' => $result['message'],
                        'payment' => $payment,
                    ], 422);
                }

                // Load installment payment if exists
                $installmentPayment = null;
                if ($payment->payment_type === 'installment') {
                    // Reload payment to get updated status
                    $payment->refresh();
                    
                    // Find installment payment by payment_id (if already linked) or invoice
                    $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                        ->where('status', 'pending')
                        ->where('amount', $payment->amount)
                        ->orderBy('due_date')
                        ->first();

                    if ($installmentPayment) {
                        // Mark installment as paid
                        $installmentPayment->update([
                            'status' => 'paid',
                            'paid_at' => now(),
                            'payment_id' => $payment->id,
                        ]);
                        \Log::info('Installment payment marked as paid', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                        ]);
                    }
                }

                // Ensure invoice status is updated correctly
                $invoice = $payment->invoice;
                if ($invoice) {
                    $invoice->refresh();
                    // Reload relationships to ensure calculated fields are up to date
                    $invoice->load(['payments', 'refunds']);
                    // Recalculate and refresh invoice status if needed
                    // Use the model's accessors which now account for refunds
                    $totalPaid = $invoice->total_paid;
                    $totalRefunded = $invoice->total_refunded;
                    $netPaid = $invoice->net_paid;
                    $remainingAmount = $invoice->remaining_amount;
                    
                    // Use a small tolerance for floating-point comparison (0.01 cents)
                    $tolerance = 0.01;
                    
                    // Ensure invoice status is correct
                    if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                        \Log::info('Invoice marked as paid during payment callback', [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'total_amount' => $invoice->total_amount,
                            'total_paid' => $totalPaid,
                            'total_refunded' => $totalRefunded,
                            'net_paid' => $netPaid,
                            'remaining_amount' => $remainingAmount,
                            'payment_id' => $payment->id,
                        ]);
                        $invoice->markAsPaid();
                        $invoice->refresh();
                    } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                        $invoice->update(['status' => 'pending', 'paid_date' => null]);
                        $invoice->refresh();
                    }
                }
                
                // Reload payment with all relationships
                $payment->refresh();
                $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

                // Send notification to customer
                if ($payment->user) {
                    try {
                        $payment->user->notify(new PaymentCompleted($payment));
                    } catch (\Exception $e) {
                        \Log::error('Failed to send payment completed notification', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Dispatch webhook for payment completed
                if ($payment->invoice && $payment->invoice->serviceProvider) {
                    try {
                        app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                    } catch (\Exception $e) {
                        \Log::error('Failed to dispatch payment completed webhook', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // If this is a web request (from gateway redirect), redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=success');
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment completed successfully',
                    'payment' => $payment,
                ]);
            } else {
                // Payment was cancelled
                $payment->update(['status' => 'cancelled']);

                // If this is a web request, redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=cancelled');
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Payment was cancelled',
                    'payment' => $payment,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Payment callback processing error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If this is a web request, redirect to frontend with error
            if (!$request->expectsJson()) {
                $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=error&error=' . urlencode($e->getMessage()));
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment callback',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}



 . number_format($remainingAmount, 2) . '.']]
                    ], 422);
                }
                return redirect()->back()->with('error', __('Payment amount cannot exceed remaining amount.'));
            }
        }
     */
    public function apiStore(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * Display the specified resource.
     */
    public function show(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                abort(403);
            }
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);

        if (request()->expectsJson()) {
            return response()->json($payment);
        }

        return view('payments.show', compact('payment'));
    }

    /**
     * API endpoint for showing payment
     */
    public function apiShow(Payment $payment): JsonResponse
    {
        return $this->show($payment);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payment $payment)
    {
        // Only allow deletion if payment is pending or failed
        if (!in_array($payment->status, ['pending', 'failed'])) {
            return redirect()->back()->with('error', __('Cannot delete completed payments.'));
        }

        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        $payment->delete();

        if (request()->expectsJson()) {
            return response()->json(['message' => 'Payment deleted successfully.']);
        }

        return redirect()->route('payments.index')
                        ->with('success', __('Payment deleted successfully.'));
    }

    /**
     * API endpoint for deleting payment
     */
    public function apiDestroy(Payment $payment): JsonResponse
    {
        return $this->destroy($payment);
    }

    /**
     * Download payment receipt as PDF
     */
    public function receipt(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        if ($payment->status !== 'completed') {
            return redirect()->back()->with('error', __('Receipt is only available for completed payments.'));
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);
        
        $pdf = Pdf::loadView('payments.receipt', compact('payment'));
        
        return $pdf->download("receipt-{$payment->payment_reference}.pdf");
    }

    /**
     * API endpoint for downloading payment receipt
     */
    public function apiReceipt(Payment $payment)
    {
        return $this->receipt($payment);
    }

    /**
     * Process/Complete a pending payment
     * This endpoint allows admins or service providers to manually complete payments
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }
        $user = Auth::user();

        // Check authorization - only admins and service providers can process payments
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Service providers can only process payments for their own invoices
        if ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment can be processed
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment cannot be processed',
                'error' => 'Only pending payments can be processed. Current status: ' . $payment->status
            ], 422);
        }

        try {
            // Process payment through gateway service
            // This handles gateway communication, balance updates, and payment completion
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            $result = $gatewayService->processPayment($payment, [
                'gateway_transaction_id' => $request->input('gateway_transaction_id'),
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Payment processing failed',
                    'error' => $result['message']
                ], 422);
            }

            // Load installment payment if exists
            $installmentPayment = null;
            if ($payment->payment_type === 'installment') {
                // Reload payment to get updated status
                $payment->refresh();
                
                // Find installment payment by payment_id (if already linked) or invoice
                $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                    ->where('status', 'pending')
                    ->where('amount', $payment->amount)
                    ->orderBy('due_date')
                    ->first();

                if ($installmentPayment) {
                    // Verify payment amount matches installment amount
                    $paymentAmount = (float) $payment->amount;
                    $installmentAmount = (float) $installmentPayment->amount;
                    
                    if (abs($paymentAmount - $installmentAmount) <= 0.01) {
                        $installmentPayment->markAsPaid($payment->id);
                        
                        \Log::info('Installment payment marked as paid during processing', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                            'amount' => $installmentAmount,
                        ]);
                    }
                }
            }

            // Reload payment with relationships (including fresh invoice data)
            $payment->refresh();
            // Reload invoice separately to ensure fresh calculated fields
            $invoice = $payment->invoice;
            if ($invoice) {
                $invoice->refresh();
                // Reload relationships to ensure calculated fields are up to date
                $invoice->load(['payments', 'refunds']);
                // Recalculate and refresh invoice status if needed
                // Use the model's accessors which now account for refunds
                $totalPaid = $invoice->total_paid;
                $totalRefunded = $invoice->total_refunded;
                $netPaid = $invoice->net_paid;
                $remainingAmount = $invoice->remaining_amount;
                
                // Use a small tolerance for floating-point comparison (0.01 cents)
                $tolerance = 0.01;
                
                // Ensure invoice status is correct
                if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                    \Log::info('Invoice marked as paid during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                    ]);
                    $invoice->markAsPaid();
                    $invoice->refresh();
                } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                    $invoice->update(['status' => 'pending', 'paid_date' => null]);
                    $invoice->refresh();
                } else {
                    // Log the current state for debugging
                    \Log::info('Invoice status check during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'current_status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                        'payment_amount' => $payment->amount,
                    ]);
                }
            }
            // Now load payment relationships with fresh invoice
            $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

            // Send notification to customer
            if ($payment->user) {
                try {
                    $payment->user->notify(new PaymentCompleted($payment));
                } catch (\Exception $e) {
                    \Log::error('Failed to send payment completed notification', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Dispatch webhook for payment completed
            if ($payment->invoice && $payment->invoice->serviceProvider) {
                try {
                    app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                } catch (\Exception $e) {
                    \Log::error('Failed to dispatch payment completed webhook', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Payment processed successfully',
                'payment' => $payment
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment processing failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $payment->markAsFailed($e->getMessage());
            } catch (\Exception $markFailedException) {
                \Log::error('Failed to mark payment as failed', [
                    'payment_id' => $payment->id ?? null,
                    'error' => $markFailedException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * API endpoint for processing payment
     */
    public function apiProcess(Request $request, Payment $payment): JsonResponse
    {
        return $this->process($request, $payment);
    }

    /**
     * Get redirect URL for payment gateway
     * This is called when user initiates payment to get the gateway URL
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }

        \ = Auth::user();
        if (!\->hasRole('customer')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment is in a valid state for redirect
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment is not in pending status',
                'status' => $payment->status,
            ], 422);
        }

        try {
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            
            $baseUrl = config('app.url', 'http://localhost:8000');
            $returnUrl = $request->input('return_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=success');
            $cancelUrl = $request->input('cancel_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=cancelled');

            $result = $gatewayService->getRedirectUrl($payment, [
                'base_url' => $baseUrl,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Failed to generate redirect URL',
                    'error' => $result['message'],
                ], 500);
            }

            return response()->json([
                'redirect_url' => $result['redirect_url'],
                'payment_id' => $payment->id,
                'message' => 'Redirect URL generated successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get payment redirect URL', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to generate redirect URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mock gateway confirmation page
     * This simulates the external payment gateway page where user confirms payment
     */
    public function gatewayConfirm(Request $request, Payment $payment)
    {
        // Verify payment exists and is pending
        if ($payment->status !== 'pending') {
            return view('payments.gateway-confirm', [
                'payment' => $payment,
                'error' => 'Payment is not in pending status',
            ]);
        }

        $returnUrl = $request->input('return_url');
        $cancelUrl = $request->input('cancel_url');

        return view('payments.gateway-confirm', [
            'payment' => $payment,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);
    }

    /**
     * Handle payment gateway callback
     * This is called after user confirms or cancels payment on the gateway
     * Can return JSON for API calls or redirect for web requests
     * This endpoint can be called without authentication (public route)
     */
    public function callback(Request $request, Payment $payment)
    {
        // Verify payment reference if provided (for security)
        $paymentReference = $request->input('payment_reference');
        if ($paymentReference && $payment->payment_reference !== $paymentReference) {
            \Log::warning('Payment callback verification failed', [
                'payment_id' => $payment->id,
                'expected_reference' => $payment->payment_reference,
                'provided_reference' => $paymentReference,
            ]);
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment reference',
                ], 403);
            }
            
            return redirect('/')->with('error', 'Invalid payment reference');
        }

        $status = $request->input('status'); // 'success' or 'cancelled'
        $transactionId = $request->input('transaction_id');
        $gatewayResponse = $request->all();

        \Log::info('Payment gateway callback received', [
            'payment_id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'status' => $status,
            'transaction_id' => $transactionId,
            'gateway_response' => $gatewayResponse,
        ]);

        try {
            if ($status === 'success' || $status === 'completed') {
                // Process payment through gateway service
                // This handles gateway communication, balance updates, and payment completion
                $gatewayService = app(\App\Services\PaymentGatewayService::class);
                $result = $gatewayService->processPayment($payment, [
                    'gateway_transaction_id' => $transactionId,
                    'gateway_response' => $gatewayResponse,
                ]);

                if (!$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment processing failed',
                        'error' => $result['message'],
                        'payment' => $payment,
                    ], 422);
                }

                // Load installment payment if exists
                $installmentPayment = null;
                if ($payment->payment_type === 'installment') {
                    // Reload payment to get updated status
                    $payment->refresh();
                    
                    // Find installment payment by payment_id (if already linked) or invoice
                    $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                        ->where('status', 'pending')
                        ->where('amount', $payment->amount)
                        ->orderBy('due_date')
                        ->first();

                    if ($installmentPayment) {
                        // Mark installment as paid
                        $installmentPayment->update([
                            'status' => 'paid',
                            'paid_at' => now(),
                            'payment_id' => $payment->id,
                        ]);
                        \Log::info('Installment payment marked as paid', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                        ]);
                    }
                }

                // Ensure invoice status is updated correctly
                $invoice = $payment->invoice;
                if ($invoice) {
                    $invoice->refresh();
                    // Reload relationships to ensure calculated fields are up to date
                    $invoice->load(['payments', 'refunds']);
                    // Recalculate and refresh invoice status if needed
                    // Use the model's accessors which now account for refunds
                    $totalPaid = $invoice->total_paid;
                    $totalRefunded = $invoice->total_refunded;
                    $netPaid = $invoice->net_paid;
                    $remainingAmount = $invoice->remaining_amount;
                    
                    // Use a small tolerance for floating-point comparison (0.01 cents)
                    $tolerance = 0.01;
                    
                    // Ensure invoice status is correct
                    if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                        \Log::info('Invoice marked as paid during payment callback', [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'total_amount' => $invoice->total_amount,
                            'total_paid' => $totalPaid,
                            'total_refunded' => $totalRefunded,
                            'net_paid' => $netPaid,
                            'remaining_amount' => $remainingAmount,
                            'payment_id' => $payment->id,
                        ]);
                        $invoice->markAsPaid();
                        $invoice->refresh();
                    } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                        $invoice->update(['status' => 'pending', 'paid_date' => null]);
                        $invoice->refresh();
                    }
                }
                
                // Reload payment with all relationships
                $payment->refresh();
                $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

                // Send notification to customer
                if ($payment->user) {
                    try {
                        $payment->user->notify(new PaymentCompleted($payment));
                    } catch (\Exception $e) {
                        \Log::error('Failed to send payment completed notification', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Dispatch webhook for payment completed
                if ($payment->invoice && $payment->invoice->serviceProvider) {
                    try {
                        app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                    } catch (\Exception $e) {
                        \Log::error('Failed to dispatch payment completed webhook', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // If this is a web request (from gateway redirect), redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=success');
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment completed successfully',
                    'payment' => $payment,
                ]);
            } else {
                // Payment was cancelled
                $payment->update(['status' => 'cancelled']);

                // If this is a web request, redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=cancelled');
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Payment was cancelled',
                    'payment' => $payment,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Payment callback processing error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If this is a web request, redirect to frontend with error
            if (!$request->expectsJson()) {
                $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=error&error=' . urlencode($e->getMessage()));
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment callback',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}




    /**
     * API endpoint for creating payment
     */
    public function apiStore(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * Display the specified resource.
     */
    public function show(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                abort(403);
            }
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);

        if (request()->expectsJson()) {
            return response()->json($payment);
        }

        return view('payments.show', compact('payment'));
    }

    /**
     * API endpoint for showing payment
     */
    public function apiShow(Payment $payment): JsonResponse
    {
        return $this->show($payment);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payment $payment)
    {
        // Only allow deletion if payment is pending or failed
        if (!in_array($payment->status, ['pending', 'failed'])) {
            return redirect()->back()->with('error', __('Cannot delete completed payments.'));
        }

        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        $payment->delete();

        if (request()->expectsJson()) {
            return response()->json(['message' => 'Payment deleted successfully.']);
        }

        return redirect()->route('payments.index')
                        ->with('success', __('Payment deleted successfully.'));
    }

    /**
     * API endpoint for deleting payment
     */
    public function apiDestroy(Payment $payment): JsonResponse
    {
        return $this->destroy($payment);
    }

    /**
     * Download payment receipt as PDF
     */
    public function receipt(Payment $payment)
    {
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            abort(403);
        }

        if ($payment->status !== 'completed') {
            return redirect()->back()->with('error', __('Receipt is only available for completed payments.'));
        }

        $payment->load(['invoice.serviceProvider', 'invoice.user', 'paymentMethod', 'user']);
        
        $pdf = Pdf::loadView('payments.receipt', compact('payment'));
        
        return $pdf->download("receipt-{$payment->payment_reference}.pdf");
    }

    /**
     * API endpoint for downloading payment receipt
     */
    public function apiReceipt(Payment $payment)
    {
        return $this->receipt($payment);
    }

    /**
     * Process/Complete a pending payment
     * This endpoint allows admins or service providers to manually complete payments
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }
        $user = Auth::user();

        // Check authorization - only admins and service providers can process payments
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Service providers can only process payments for their own invoices
        if ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment can be processed
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment cannot be processed',
                'error' => 'Only pending payments can be processed. Current status: ' . $payment->status
            ], 422);
        }

        try {
            // Process payment through gateway service
            // This handles gateway communication, balance updates, and payment completion
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            $result = $gatewayService->processPayment($payment, [
                'gateway_transaction_id' => $request->input('gateway_transaction_id'),
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Payment processing failed',
                    'error' => $result['message']
                ], 422);
            }

            // Load installment payment if exists
            $installmentPayment = null;
            if ($payment->payment_type === 'installment') {
                // Reload payment to get updated status
                $payment->refresh();
                
                // Find installment payment by payment_id (if already linked) or invoice
                $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                    ->where('status', 'pending')
                    ->where('amount', $payment->amount)
                    ->orderBy('due_date')
                    ->first();

                if ($installmentPayment) {
                    // Verify payment amount matches installment amount
                    $paymentAmount = (float) $payment->amount;
                    $installmentAmount = (float) $installmentPayment->amount;
                    
                    if (abs($paymentAmount - $installmentAmount) <= 0.01) {
                        $installmentPayment->markAsPaid($payment->id);
                        
                        \Log::info('Installment payment marked as paid during processing', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                            'amount' => $installmentAmount,
                        ]);
                    }
                }
            }

            // Reload payment with relationships (including fresh invoice data)
            $payment->refresh();
            // Reload invoice separately to ensure fresh calculated fields
            $invoice = $payment->invoice;
            if ($invoice) {
                $invoice->refresh();
                // Reload relationships to ensure calculated fields are up to date
                $invoice->load(['payments', 'refunds']);
                // Recalculate and refresh invoice status if needed
                // Use the model's accessors which now account for refunds
                $totalPaid = $invoice->total_paid;
                $totalRefunded = $invoice->total_refunded;
                $netPaid = $invoice->net_paid;
                $remainingAmount = $invoice->remaining_amount;
                
                // Use a small tolerance for floating-point comparison (0.01 cents)
                $tolerance = 0.01;
                
                // Ensure invoice status is correct
                if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                    \Log::info('Invoice marked as paid during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                    ]);
                    $invoice->markAsPaid();
                    $invoice->refresh();
                } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                    $invoice->update(['status' => 'pending', 'paid_date' => null]);
                    $invoice->refresh();
                } else {
                    // Log the current state for debugging
                    \Log::info('Invoice status check during payment processing', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'current_status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'total_paid' => $totalPaid,
                        'total_refunded' => $totalRefunded,
                        'net_paid' => $netPaid,
                        'remaining_amount' => $remainingAmount,
                        'payment_id' => $payment->id,
                        'payment_amount' => $payment->amount,
                    ]);
                }
            }
            // Now load payment relationships with fresh invoice
            $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

            // Send notification to customer
            if ($payment->user) {
                try {
                    $payment->user->notify(new PaymentCompleted($payment));
                } catch (\Exception $e) {
                    \Log::error('Failed to send payment completed notification', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Dispatch webhook for payment completed
            if ($payment->invoice && $payment->invoice->serviceProvider) {
                try {
                    app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                } catch (\Exception $e) {
                    \Log::error('Failed to dispatch payment completed webhook', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Payment processed successfully',
                'payment' => $payment
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment processing failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $payment->markAsFailed($e->getMessage());
            } catch (\Exception $markFailedException) {
                \Log::error('Failed to mark payment as failed', [
                    'payment_id' => $payment->id ?? null,
                    'error' => $markFailedException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * API endpoint for processing payment
     */
    public function apiProcess(Request $request, Payment $payment): JsonResponse
    {
        return $this->process($request, $payment);
    }

    /**
     * Get redirect URL for payment gateway
     * This is called when user initiates payment to get the gateway URL
     */
    
        if (\ = \->denyServiceProviderPayments(\)) {
            return \;
        }

        \ = Auth::user();
        if (!\->hasRole('customer')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        // Check authorization
        if (Auth::user()->hasRole('customer') && $payment->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = Auth::user()->serviceProvider;
            if (!$serviceProvider || $payment->invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        // Check if payment is in a valid state for redirect
        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Payment is not in pending status',
                'status' => $payment->status,
            ], 422);
        }

        try {
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            
            $baseUrl = config('app.url', 'http://localhost:8000');
            $returnUrl = $request->input('return_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=success');
            $cancelUrl = $request->input('cancel_url', $baseUrl . '/api/payments/' . $payment->id . '/callback?status=cancelled');

            $result = $gatewayService->getRedirectUrl($payment, [
                'base_url' => $baseUrl,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => 'Failed to generate redirect URL',
                    'error' => $result['message'],
                ], 500);
            }

            return response()->json([
                'redirect_url' => $result['redirect_url'],
                'payment_id' => $payment->id,
                'message' => 'Redirect URL generated successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get payment redirect URL', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to generate redirect URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mock gateway confirmation page
     * This simulates the external payment gateway page where user confirms payment
     */
    public function gatewayConfirm(Request $request, Payment $payment)
    {
        // Verify payment exists and is pending
        if ($payment->status !== 'pending') {
            return view('payments.gateway-confirm', [
                'payment' => $payment,
                'error' => 'Payment is not in pending status',
            ]);
        }

        $returnUrl = $request->input('return_url');
        $cancelUrl = $request->input('cancel_url');

        return view('payments.gateway-confirm', [
            'payment' => $payment,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);
    }

    /**
     * Handle payment gateway callback
     * This is called after user confirms or cancels payment on the gateway
     * Can return JSON for API calls or redirect for web requests
     * This endpoint can be called without authentication (public route)
     */
    public function callback(Request $request, Payment $payment)
    {
        // Verify payment reference if provided (for security)
        $paymentReference = $request->input('payment_reference');
        if ($paymentReference && $payment->payment_reference !== $paymentReference) {
            \Log::warning('Payment callback verification failed', [
                'payment_id' => $payment->id,
                'expected_reference' => $payment->payment_reference,
                'provided_reference' => $paymentReference,
            ]);
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment reference',
                ], 403);
            }
            
            return redirect('/')->with('error', 'Invalid payment reference');
        }

        $status = $request->input('status'); // 'success' or 'cancelled'
        $transactionId = $request->input('transaction_id');
        $gatewayResponse = $request->all();

        \Log::info('Payment gateway callback received', [
            'payment_id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'status' => $status,
            'transaction_id' => $transactionId,
            'gateway_response' => $gatewayResponse,
        ]);

        try {
            if ($status === 'success' || $status === 'completed') {
                // Process payment through gateway service
                // This handles gateway communication, balance updates, and payment completion
                $gatewayService = app(\App\Services\PaymentGatewayService::class);
                $result = $gatewayService->processPayment($payment, [
                    'gateway_transaction_id' => $transactionId,
                    'gateway_response' => $gatewayResponse,
                ]);

                if (!$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment processing failed',
                        'error' => $result['message'],
                        'payment' => $payment,
                    ], 422);
                }

                // Load installment payment if exists
                $installmentPayment = null;
                if ($payment->payment_type === 'installment') {
                    // Reload payment to get updated status
                    $payment->refresh();
                    
                    // Find installment payment by payment_id (if already linked) or invoice
                    $installmentPayment = \App\Models\InstallmentPayment::where('invoice_id', $payment->invoice_id)
                        ->where('status', 'pending')
                        ->where('amount', $payment->amount)
                        ->orderBy('due_date')
                        ->first();

                    if ($installmentPayment) {
                        // Mark installment as paid
                        $installmentPayment->update([
                            'status' => 'paid',
                            'paid_at' => now(),
                            'payment_id' => $payment->id,
                        ]);
                        \Log::info('Installment payment marked as paid', [
                            'installment_payment_id' => $installmentPayment->id,
                            'payment_id' => $payment->id,
                        ]);
                    }
                }

                // Ensure invoice status is updated correctly
                $invoice = $payment->invoice;
                if ($invoice) {
                    $invoice->refresh();
                    // Reload relationships to ensure calculated fields are up to date
                    $invoice->load(['payments', 'refunds']);
                    // Recalculate and refresh invoice status if needed
                    // Use the model's accessors which now account for refunds
                    $totalPaid = $invoice->total_paid;
                    $totalRefunded = $invoice->total_refunded;
                    $netPaid = $invoice->net_paid;
                    $remainingAmount = $invoice->remaining_amount;
                    
                    // Use a small tolerance for floating-point comparison (0.01 cents)
                    $tolerance = 0.01;
                    
                    // Ensure invoice status is correct
                    if ($remainingAmount <= $tolerance && $invoice->status !== 'paid') {
                        \Log::info('Invoice marked as paid during payment callback', [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'total_amount' => $invoice->total_amount,
                            'total_paid' => $totalPaid,
                            'total_refunded' => $totalRefunded,
                            'net_paid' => $netPaid,
                            'remaining_amount' => $remainingAmount,
                            'payment_id' => $payment->id,
                        ]);
                        $invoice->markAsPaid();
                        $invoice->refresh();
                    } elseif ($invoice->status === 'paid' && $remainingAmount > $tolerance) {
                        $invoice->update(['status' => 'pending', 'paid_date' => null]);
                        $invoice->refresh();
                    }
                }
                
                // Reload payment with all relationships
                $payment->refresh();
                $payment->load(['invoice.serviceProvider', 'invoice.user', 'invoice.payments', 'invoice.refunds', 'paymentMethod', 'user']);

                // Send notification to customer
                if ($payment->user) {
                    try {
                        $payment->user->notify(new PaymentCompleted($payment));
                    } catch (\Exception $e) {
                        \Log::error('Failed to send payment completed notification', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Dispatch webhook for payment completed
                if ($payment->invoice && $payment->invoice->serviceProvider) {
                    try {
                        app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                    } catch (\Exception $e) {
                        \Log::error('Failed to dispatch payment completed webhook', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // If this is a web request (from gateway redirect), redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=success');
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment completed successfully',
                    'payment' => $payment,
                ]);
            } else {
                // Payment was cancelled
                $payment->update(['status' => 'cancelled']);

                // If this is a web request, redirect to frontend
                if (!$request->expectsJson()) {
                    $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                    return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=cancelled');
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Payment was cancelled',
                    'payment' => $payment,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Payment callback processing error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If this is a web request, redirect to frontend with error
            if (!$request->expectsJson()) {
                $frontendUrl = $request->input('frontend_url', config('app.frontend_url', 'http://localhost:5173'));
                return redirect($frontendUrl . '/payments/' . $payment->id . '?callback=error&error=' . urlencode($e->getMessage()));
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment callback',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}




