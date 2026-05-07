<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\Payment;
use App\Models\Invoice;
use App\Services\WebhookService;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RefundController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of refunds
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = Refund::with(['payment', 'invoice', 'user', 'processedBy']);

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

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->payment_id);
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('refund_reference', 'like', "%{$search}%")
                  ->orWhereHas('payment', function($q) use ($search) {
                      $q->where('payment_reference', 'like', "%{$search}%");
                  })
                  ->orWhereHas('invoice', function($q) use ($search) {
                      $q->where('invoice_number', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = $request->get('per_page', 15);
        $refunds = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($refunds);
    }

    /**
     * Store a newly created refund
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|exists:payments,id',
            'amount' => 'required|numeric|min:0.01',
            'refund_type' => 'required|in:full,partial',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $payment = Payment::with(['invoice', 'refunds'])->findOrFail($request->payment_id);

            // Ensure invoice relationship is loaded
            if (!$payment->invoice) {
                $payment->load('invoice');
            }

            // Check if payment can be refunded
            if (!$payment->canBeRefunded()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'This payment cannot be refunded',
                    'errors' => ['payment_id' => ['Payment must be completed and have refundable amount.']],
                ], 422);
            }

            // Validate refund amount
            $refundableAmount = $payment->refundable_amount;
            $requestedAmount = $request->amount;

            if ($request->refund_type === 'full') {
                if ($requestedAmount != $payment->amount) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Full refund amount must equal payment amount',
                        'errors' => ['amount' => ['Full refund amount must be $' . number_format($payment->amount, 2) . '.']],
                    ], 422);
                }
            } else {
                if ($requestedAmount > $refundableAmount) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Refund amount exceeds refundable amount',
                        'errors' => ['amount' => ['Maximum refundable amount is $' . number_format($refundableAmount, 2) . '.']],
                    ], 422);
                }
                if ($requestedAmount <= 0) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Refund amount must be greater than zero',
                        'errors' => ['amount' => ['Refund amount must be greater than zero.']],
                    ], 422);
                }
            }

            // Create refund record
            $refund = Refund::create([
                'refund_reference' => Refund::generateRefundReference(),
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'user_id' => $payment->user_id,
                'processed_by' => Auth::id(),
                'amount' => $requestedAmount,
                'status' => 'pending',
                'refund_type' => $request->refund_type,
                'reason' => $request->reason,
                'notes' => $request->notes,
                'gateway' => $payment->gateway ?? 'manual',
            ]);

            DB::commit(); // Commit refund creation before gateway processing

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Refund creation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create refund',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Process refund through gateway service (outside of transaction)
        // This handles gateway communication, balance updates, and refund completion
        // Note: PaymentGatewayService manages its own transaction internally
        try {
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            $result = $gatewayService->processRefund($refund);
            
            if (!$result['success']) {
                // Refund record exists but processing failed
                // Gateway service already marked it as failed
                return response()->json([
                    'message' => 'Refund processing failed',
                    'error' => $result['message']
                ], 422);
            }
            
            // Reload refund with all relationships
            $refund->refresh();
            $refund->load(['payment.invoice.serviceProvider', 'payment.invoice.user', 'payment.paymentMethod', 'user', 'processedBy']);

            // Log activity (after successful processing)
            try {
                $invoice = $refund->invoice ?? $payment->invoice;
                if ($invoice) {
                    ActivityLogService::log(
                        'refund_create',
                        "Refund {$refund->refund_reference} created for payment {$payment->payment_reference}",
                        $invoice,
                        [
                            'refund_id' => $refund->id,
                            'refund_reference' => $refund->refund_reference,
                            'payment_id' => $payment->id,
                            'payment_reference' => $payment->payment_reference,
                            'invoice_id' => $payment->invoice_id,
                            'amount' => $refund->amount,
                            'refund_type' => $refund->refund_type,
                        ]
                    );
                }
            } catch (\Exception $e) {
                \Log::error('Failed to log refund creation activity', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Dispatch webhook for refund completed
            $payment->load(['invoice.serviceProvider']);
            if ($payment->invoice && $payment->invoice->serviceProvider) {
                try {
                    app(WebhookService::class)->dispatchPaymentEvent('payment.refunded', $payment);
                } catch (\Exception $e) {
                    \Log::error('Failed to dispatch refund webhook', [
                        'refund_id' => $refund->id,
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Refund processed successfully',
                'data' => $refund,
            ], 201);

        } catch (\Exception $e) {
            // If refund was created but gateway processing failed, mark it as failed
            if (isset($refund) && $refund->id) {
                try {
                    $refund->markAsFailed($e->getMessage());
                } catch (\Exception $markFailedException) {
                    \Log::error('Failed to mark refund as failed', [
                        'refund_id' => $refund->id ?? null,
                        'error' => $markFailedException->getMessage(),
                    ]);
                }
            }
            
            \Log::error('Refund gateway processing error', [
                'refund_id' => $refund->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'message' => 'Refund processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified refund
     */
    public function show(Refund $refund): JsonResponse
    {
        $refund->load(['payment.invoice.serviceProvider', 'payment.invoice.user', 'payment.paymentMethod', 'user', 'processedBy']);

        // Check authorization
        $user = Auth::user();
        if ($user->hasRole('customer') && $refund->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($refund);
    }

    /**
     * Cancel a pending refund
     */
    public function cancel(Refund $refund): JsonResponse
    {
        if ($refund->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending refunds can be cancelled',
            ], 422);
        }

        $refund->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Refund cancelled successfully',
            'data' => $refund,
        ]);
    }
}

