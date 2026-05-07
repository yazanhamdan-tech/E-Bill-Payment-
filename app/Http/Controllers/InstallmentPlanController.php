<?php

namespace App\Http\Controllers;

use App\Models\InstallmentPlan;
use App\Models\InstallmentPayment;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class InstallmentPlanController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Get installment plan for an invoice
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $user = Auth::user();

        // Check authorization
        if ($user->hasRole('customer') && $invoice->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        } elseif ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if (!$serviceProvider || $invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $plan = $invoice->installmentPlan;
        
        if (!$plan) {
            return response()->json(['message' => 'No installment plan found for this invoice'], 404);
        }

        try {
            // Load relationships safely
            $plan->load(['installmentPayments' => function($query) {
                $query->orderBy('due_date')->orderBy('installment_number');
            }, 'paymentMethod']);
            
            // Load payment relationship with nested eager loading
            $plan->load('installmentPayments.payment');
            
            // Build response data manually to avoid serialization issues
            $planData = [
                'id' => $plan->id,
                'invoice_id' => $plan->invoice_id,
                'plan_name' => $plan->plan_name,
                'total_installments' => $plan->total_installments,
                'total_amount' => (float) $plan->total_amount,
                'installment_amount' => (float) $plan->installment_amount,
                'frequency' => $plan->frequency,
                'frequency_days' => $plan->frequency_days,
                'start_date' => $plan->start_date ? $plan->start_date->format('Y-m-d') : null,
                'end_date' => $plan->end_date ? $plan->end_date->format('Y-m-d') : null,
                'status' => $plan->status,
                'payment_method_id' => $plan->payment_method_id,
                'auto_charge' => (bool) $plan->auto_charge,
                'notes' => $plan->notes,
                'metadata' => $plan->metadata,
                'created_at' => $plan->created_at ? $plan->created_at->toIso8601String() : null,
                'updated_at' => $plan->updated_at ? $plan->updated_at->toIso8601String() : null,
            ];
            
            // Add computed attributes
            try {
                $planData['paid_installments_count'] = $plan->paid_installments_count;
                $planData['pending_installments_count'] = $plan->pending_installments_count;
                $planData['overdue_installments_count'] = $plan->overdue_installments_count;
                $planData['total_paid'] = (float) $plan->total_paid;
                $planData['remaining_amount'] = (float) $plan->remaining_amount;
                $planData['progress_percentage'] = (float) $plan->progress_percentage;
                $planData['is_completed'] = (bool) $plan->is_completed;
            } catch (\Exception $attrError) {
                \Log::warning('Error computing installment plan attributes', [
                    'plan_id' => $plan->id,
                    'error' => $attrError->getMessage(),
                ]);
                // Set defaults if computation fails
                $planData['paid_installments_count'] = 0;
                $planData['pending_installments_count'] = 0;
                $planData['overdue_installments_count'] = 0;
                $planData['total_paid'] = 0;
                $planData['remaining_amount'] = (float) $plan->total_amount;
                $planData['progress_percentage'] = 0;
                $planData['is_completed'] = false;
            }
            
            // Add relationships
            if ($plan->relationLoaded('installmentPayments')) {
                $planData['installment_payments'] = $plan->installmentPayments->map(function($payment) {
                    return [
                        'id' => $payment->id,
                        'installment_plan_id' => $payment->installment_plan_id,
                        'invoice_id' => $payment->invoice_id,
                        'installment_number' => $payment->installment_number,
                        'amount' => (float) $payment->amount,
                        'due_date' => $payment->due_date ? $payment->due_date->format('Y-m-d') : null,
                        'paid_date' => $payment->paid_date ? $payment->paid_date->format('Y-m-d') : null,
                        'status' => $payment->status,
                        'payment_id' => $payment->payment_id,
                        'notes' => $payment->notes,
                        'metadata' => $payment->metadata,
                        'created_at' => $payment->created_at ? $payment->created_at->toIso8601String() : null,
                        'updated_at' => $payment->updated_at ? $payment->updated_at->toIso8601String() : null,
                    ];
                })->toArray();
            }
            
            if ($plan->relationLoaded('paymentMethod') && $plan->paymentMethod) {
                $planData['payment_method'] = $plan->paymentMethod->toArray();
            }
            
            return response()->json($planData);
        } catch (\Exception $e) {
            \Log::error('Error loading installment plan relationships', [
                'plan_id' => $plan->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return basic plan data without relationships
            return response()->json([
                'message' => 'Error loading installment plan details',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
                'plan' => $plan->toArray()
            ], 500);
        }
    }

    /**
     * Create an installment plan for an invoice
     */
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $user = Auth::user();

        // Check authorization - only service providers and admins can create installment plans
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized. Only service providers and admins can create installment plans.'], 403);
        }

        // Service providers can only create plans for their own invoices
        if ($user->hasRole('service_provider')) {
            $serviceProvider = $user->serviceProvider;
            if (!$serviceProvider || $invoice->service_provider_id !== $serviceProvider->id) {
                return response()->json(['message' => 'Unauthorized. You can only create installment plans for your own invoices.'], 403);
            }
        }

        // Check if invoice already has an installment plan
        if ($invoice->installmentPlan) {
            return response()->json([
                'message' => 'Invoice already has an installment plan',
                'errors' => ['installment_plan' => ['An installment plan already exists for this invoice']]
            ], 422);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'plan_name' => 'nullable|string|max:255',
            'total_installments' => 'required|integer|min:2|max:60',
            'installment_amount' => 'nullable|numeric|min:0.01',
            'frequency' => 'required|in:daily,weekly,biweekly,monthly,quarterly,custom',
            'frequency_days' => 'required_if:frequency,custom|integer|min:1|max:365',
            'start_date' => 'required|date|after_or_equal:today',
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'auto_charge' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Calculate installment amount if not provided
        if (!isset($validated['installment_amount'])) {
            $validated['installment_amount'] = $invoice->total_amount / $validated['total_installments'];
        }

        // Validate that total installments * installment_amount is approximately equal to invoice total
        $calculatedTotal = $validated['installment_amount'] * $validated['total_installments'];
        $difference = abs($calculatedTotal - $invoice->total_amount);
        
        if ($difference > 0.01) {
            // Adjust last installment to match exact total
            // This will be handled in generateInstallments()
        }

        // Calculate end date
        $startDate = Carbon::parse($validated['start_date']);
        $daysBetween = $this->getDaysBetweenInstallments($validated['frequency'], $validated['frequency_days'] ?? null);
        $endDate = $startDate->copy()->addDays(($validated['total_installments'] - 1) * $daysBetween);

        try {
            // Create installment plan
            $plan = InstallmentPlan::create([
                'invoice_id' => $invoice->id,
                'plan_name' => $validated['plan_name'] ?? "Installment Plan for {$invoice->invoice_number}",
                'total_installments' => $validated['total_installments'],
                'total_amount' => $invoice->total_amount,
                'installment_amount' => $validated['installment_amount'],
                'frequency' => $validated['frequency'],
                'frequency_days' => $validated['frequency_days'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'active',
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'auto_charge' => $validated['auto_charge'] ?? false,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Generate installment payments
            try {
                $plan->generateInstallments();
            } catch (\Exception $genError) {
                \Log::error('Error generating installments', [
                    'plan_id' => $plan->id,
                    'invoice_id' => $invoice->id,
                    'error' => $genError->getMessage(),
                    'trace' => $genError->getTraceAsString(),
                ]);
                // Delete the plan if installments generation fails
                $plan->delete();
                throw $genError;
            }

            // Reload plan with relationships
            $plan->refresh();
            $plan->load(['installmentPayments' => function($query) {
                $query->orderBy('due_date')->orderBy('installment_number');
            }, 'paymentMethod']);

            // Build response data manually
            $planData = [
                'id' => $plan->id,
                'invoice_id' => $plan->invoice_id,
                'plan_name' => $plan->plan_name,
                'total_installments' => $plan->total_installments,
                'total_amount' => (float) $plan->total_amount,
                'installment_amount' => (float) $plan->installment_amount,
                'frequency' => $plan->frequency,
                'frequency_days' => $plan->frequency_days,
                'start_date' => $plan->start_date ? $plan->start_date->format('Y-m-d') : null,
                'end_date' => $plan->end_date ? $plan->end_date->format('Y-m-d') : null,
                'status' => $plan->status,
                'payment_method_id' => $plan->payment_method_id,
                'auto_charge' => (bool) $plan->auto_charge,
                'notes' => $plan->notes,
                'metadata' => $plan->metadata,
                'created_at' => $plan->created_at ? $plan->created_at->toIso8601String() : null,
                'updated_at' => $plan->updated_at ? $plan->updated_at->toIso8601String() : null,
            ];

            // Add computed attributes
            try {
                $planData['paid_installments_count'] = $plan->paid_installments_count;
                $planData['pending_installments_count'] = $plan->pending_installments_count;
                $planData['overdue_installments_count'] = $plan->overdue_installments_count;
                $planData['total_paid'] = (float) $plan->total_paid;
                $planData['remaining_amount'] = (float) $plan->remaining_amount;
                $planData['progress_percentage'] = (float) $plan->progress_percentage;
                $planData['is_completed'] = (bool) $plan->is_completed;
            } catch (\Exception $attrError) {
                \Log::warning('Error computing installment plan attributes during creation', [
                    'plan_id' => $plan->id,
                    'error' => $attrError->getMessage(),
                ]);
                $planData['paid_installments_count'] = 0;
                $planData['pending_installments_count'] = $plan->total_installments;
                $planData['overdue_installments_count'] = 0;
                $planData['total_paid'] = 0;
                $planData['remaining_amount'] = (float) $plan->total_amount;
                $planData['progress_percentage'] = 0;
                $planData['is_completed'] = false;
            }

            // Add installment payments
            if ($plan->relationLoaded('installmentPayments')) {
                $planData['installment_payments'] = $plan->installmentPayments->map(function($payment) {
                    return [
                        'id' => $payment->id,
                        'installment_plan_id' => $payment->installment_plan_id,
                        'invoice_id' => $payment->invoice_id,
                        'installment_number' => $payment->installment_number,
                        'amount' => (float) $payment->amount,
                        'due_date' => $payment->due_date ? $payment->due_date->format('Y-m-d') : null,
                        'paid_date' => $payment->paid_date ? $payment->paid_date->format('Y-m-d') : null,
                        'status' => $payment->status,
                        'payment_id' => $payment->payment_id,
                        'notes' => $payment->notes,
                        'metadata' => $payment->metadata,
                        'created_at' => $payment->created_at ? $payment->created_at->toIso8601String() : null,
                        'updated_at' => $payment->updated_at ? $payment->updated_at->toIso8601String() : null,
                    ];
                })->toArray();
            }

            if ($plan->relationLoaded('paymentMethod') && $plan->paymentMethod) {
                $planData['payment_method'] = $plan->paymentMethod->toArray();
            }

            return response()->json([
                'message' => 'Installment plan created successfully',
                'data' => $planData
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating installment plan', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create installment plan',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while creating the installment plan',
            ], 500);
        }
    }

    /**
     * Update an installment plan
     */
    public function update(Request $request, InstallmentPlan $installmentPlan): JsonResponse
    {
        $user = Auth::user();

        // Check authorization
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Only allow updates to active plans
        if ($installmentPlan->status !== 'active') {
            return response()->json([
                'message' => 'Cannot update non-active installment plan',
                'errors' => ['status' => ['Only active plans can be updated']]
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'plan_name' => 'nullable|string|max:255',
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'auto_charge' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $installmentPlan->update($validated);

        $installmentPlan->load(['installmentPayments', 'paymentMethod']);

        return response()->json([
            'message' => 'Installment plan updated successfully',
            'data' => $installmentPlan
        ]);
    }

    /**
     * Cancel an installment plan
     */
    public function cancel(InstallmentPlan $installmentPlan): JsonResponse
    {
        $user = Auth::user();

        // Check authorization
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($installmentPlan->status === 'cancelled') {
            return response()->json(['message' => 'Plan is already cancelled'], 422);
        }

        $installmentPlan->cancel();

        return response()->json([
            'message' => 'Installment plan cancelled successfully',
            'data' => $installmentPlan
        ]);
    }

    /**
     * Pause an installment plan
     */
    public function pause(InstallmentPlan $installmentPlan): JsonResponse
    {
        $user = Auth::user();

        // Check authorization
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($installmentPlan->status !== 'active') {
            return response()->json(['message' => 'Only active plans can be paused'], 422);
        }

        $installmentPlan->pause();

        return response()->json([
            'message' => 'Installment plan paused successfully',
            'data' => $installmentPlan
        ]);
    }

    /**
     * Resume a paused installment plan
     */
    public function resume(InstallmentPlan $installmentPlan): JsonResponse
    {
        $user = Auth::user();

        // Check authorization
        if (!$user->hasAnyRole(['admin', 'service_provider'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($installmentPlan->status !== 'paused') {
            return response()->json(['message' => 'Only paused plans can be resumed'], 422);
        }

        $installmentPlan->resume();

        return response()->json([
            'message' => 'Installment plan resumed successfully',
            'data' => $installmentPlan
        ]);
    }

    /**
     * Get next due installment for an invoice
     */
    public function getNextDue(Invoice $invoice): JsonResponse
    {
        $user = Auth::user();

        // Check authorization
        if ($user->hasRole('customer') && $invoice->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $plan = $invoice->installmentPlan;
        
        if (!$plan) {
            return response()->json(['message' => 'No installment plan found'], 404);
        }

        $nextDue = $plan->installmentPayments()
            ->where('status', 'pending')
            ->orderBy('due_date', 'asc')
            ->first();

        if (!$nextDue) {
            return response()->json(['message' => 'No pending installments'], 404);
        }

        return response()->json($nextDue);
    }

    /**
     * Helper method to calculate days between installments
     */
    protected function getDaysBetweenInstallments(string $frequency, ?int $frequencyDays = null): int
    {
        switch ($frequency) {
            case 'daily':
                return 1;
            case 'weekly':
                return 7;
            case 'biweekly':
                return 14;
            case 'monthly':
                return 30;
            case 'quarterly':
                return 90;
            case 'custom':
                return $frequencyDays ?? 30;
            default:
                return 30;
        }
    }
}
