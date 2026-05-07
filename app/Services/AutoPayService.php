<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\ActivityLogService;
use App\Services\WebhookService;
use App\Notifications\PaymentCompleted;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AutoPayService
{
    /**
     * Process auto-pay for eligible recurring invoices
     */
    public function processAutoPay(): array
    {
        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Get all invoices eligible for auto-pay
        $invoices = Invoice::eligibleForAutoPay()
            ->where('due_date', '<=', now())
            ->with(['user', 'autoPayPaymentMethod', 'serviceProvider'])
            ->get();

        Log::info('Auto-pay processing started', [
            'eligible_invoices_count' => $invoices->count(),
        ]);

        foreach ($invoices as $invoice) {
            try {
                $result = $this->processInvoicePayment($invoice);
                
                if ($result['success']) {
                    $results['succeeded']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'error' => $result['error'],
                    ];
                }
                
                $results['processed']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'error' => $e->getMessage(),
                ];
                
                Log::error('Auto-pay processing error', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('Auto-pay processing completed', $results);

        return $results;
    }

    /**
     * Process payment for a single invoice
     */
    public function processInvoicePayment(Invoice $invoice): array
    {
        // Check if invoice is still eligible
        if (!$invoice->is_recurring || !$invoice->auto_pay_enabled || $invoice->status !== 'pending') {
            return [
                'success' => false,
                'error' => 'Invoice is not eligible for auto-pay',
            ];
        }

        // Check if payment method exists and is active
        $paymentMethod = $invoice->autoPayPaymentMethod;
        if (!$paymentMethod || !$paymentMethod->is_active) {
            $invoice->update([
                'last_auto_pay_attempt' => now(),
                'auto_pay_failure_reason' => 'Payment method not found or inactive',
            ]);
            
            return [
                'success' => false,
                'error' => 'Payment method not found or inactive',
            ];
        }

        // Check if invoice is already paid
        if ($invoice->remaining_amount <= 0) {
            return [
                'success' => false,
                'error' => 'Invoice is already paid',
            ];
        }

        // Calculate payment amount
        $amount = $invoice->remaining_amount;

        try {
            // Create payment
            $payment = Payment::create([
                'payment_reference' => Payment::generatePaymentReference(),
                'invoice_id' => $invoice->id,
                'user_id' => $invoice->user_id,
                'payment_method_id' => $paymentMethod->id,
                'amount' => $amount,
                'status' => 'pending',
                'payment_type' => 'full',
                'gateway' => $paymentMethod->type ?? 'manual',
                'notes' => 'Auto-pay payment for recurring invoice',
            ]);

            // Process payment through gateway service
            // This handles gateway communication, balance updates, and payment completion
            $gatewayService = app(\App\Services\PaymentGatewayService::class);
            $result = $gatewayService->processPayment($payment, [
                'gateway_transaction_id' => 'AUTO-' . time() . '-' . $payment->id,
            ]);
            
            if (!$result['success']) {
                // Payment failed
                $invoice->update([
                    'last_auto_pay_attempt' => now(),
                    'auto_pay_failure_reason' => $result['message'],
                ]);
                
                return [
                    'success' => false,
                    'error' => $result['message'],
                ];
            }

            // Update invoice auto-pay tracking
            $invoice->update([
                'last_auto_pay_attempt' => now(),
                'auto_pay_failure_reason' => null,
            ]);

            // Log activity
            try {
                ActivityLogService::logPaymentCreated($payment);
            } catch (\Exception $e) {
                Log::error('Failed to log auto-pay payment activity', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Send notification
            if ($invoice->user) {
                try {
                    $invoice->user->notify(new PaymentCompleted($payment));
                } catch (\Exception $e) {
                    Log::error('Failed to send auto-pay payment notification', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Dispatch webhook
            if ($invoice->serviceProvider) {
                try {
                    app(WebhookService::class)->dispatchPaymentEvent('payment.completed', $payment);
                } catch (\Exception $e) {
                    Log::error('Failed to dispatch auto-pay webhook', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Auto-pay payment processed successfully', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
            ]);

            return [
                'success' => true,
                'payment_id' => $payment->id,
            ];

        } catch (\Exception $e) {
            // Update invoice with failure reason
            $invoice->update([
                'last_auto_pay_attempt' => now(),
                'auto_pay_failure_reason' => $e->getMessage(),
            ]);

            Log::error('Auto-pay payment processing failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate next recurring invoice
     */
    public function generateNextRecurringInvoice(Invoice $parentInvoice): ?Invoice
    {
        if (!$parentInvoice->is_recurring) {
            return null;
        }

        // Check if recurring has ended
        if ($parentInvoice->recurring_end_date && $parentInvoice->recurring_end_date < now()) {
            return null;
        }

        // Calculate next due date based on frequency
        $nextDueDate = $this->calculateNextDueDate(
            $parentInvoice->due_date,
            $parentInvoice->recurring_frequency
        );

        // Check if next invoice already exists
        $existingInvoice = Invoice::where('parent_invoice_id', $parentInvoice->id)
            ->where('due_date', $nextDueDate)
            ->first();

        if ($existingInvoice) {
            return $existingInvoice;
        }

        // Generate invoice number
        $invoiceNumber = $this->generateRecurringInvoiceNumber($parentInvoice, $nextDueDate);

        // Create new recurring invoice
        $newInvoice = Invoice::create([
            'invoice_number' => $invoiceNumber,
            'user_id' => $parentInvoice->user_id,
            'service_provider_id' => $parentInvoice->service_provider_id,
            'title' => $parentInvoice->title,
            'description' => $parentInvoice->description,
            'amount' => $parentInvoice->amount,
            'tax_amount' => $parentInvoice->tax_amount,
            'total_amount' => $parentInvoice->total_amount,
            'status' => 'pending',
            'due_date' => $nextDueDate,
            'issue_date' => now(),
            'is_recurring' => true,
            'recurring_frequency' => $parentInvoice->recurring_frequency,
            'recurring_end_date' => $parentInvoice->recurring_end_date,
            'parent_invoice_id' => $parentInvoice->id,
            'auto_pay_enabled' => $parentInvoice->auto_pay_enabled,
            'auto_pay_payment_method_id' => $parentInvoice->auto_pay_payment_method_id,
            'metadata' => $parentInvoice->metadata,
        ]);

        Log::info('Next recurring invoice generated', [
            'parent_invoice_id' => $parentInvoice->id,
            'new_invoice_id' => $newInvoice->id,
            'due_date' => $nextDueDate,
        ]);

        return $newInvoice;
    }

    /**
     * Calculate next due date based on frequency
     */
    protected function calculateNextDueDate(Carbon $currentDueDate, string $frequency): Carbon
    {
        $nextDate = $currentDueDate->copy();

        switch ($frequency) {
            case 'daily':
                return $nextDate->addDay();
            case 'weekly':
                return $nextDate->addWeek();
            case 'monthly':
                return $nextDate->addMonth();
            case 'quarterly':
                return $nextDate->addMonths(3);
            case 'yearly':
                return $nextDate->addYear();
            default:
                return $nextDate->addMonth();
        }
    }

    /**
     * Generate invoice number for recurring invoice
     */
    protected function generateRecurringInvoiceNumber(Invoice $parentInvoice, Carbon $dueDate): string
    {
        $baseNumber = $parentInvoice->invoice_number;
        $suffix = '-' . $dueDate->format('Ymd');
        
        // Check if invoice number with suffix already exists
        $fullNumber = $baseNumber . $suffix;
        $counter = 1;
        
        while (Invoice::where('invoice_number', $fullNumber)->exists()) {
            $fullNumber = $baseNumber . $suffix . '-' . $counter;
            $counter++;
        }
        
        return $fullNumber;
    }
}

