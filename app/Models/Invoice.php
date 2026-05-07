<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'user_id',
        'service_provider_id',
        'title',
        'description',
        'amount',
        'tax_amount',
        'fee_amount',
        'total_amount',
        'status',
        'dispute_status',
        'dispute_reason',
        'disputed_at',
        'dispute_resolution',
        'dispute_resolved_at',
        'dispute_resolved_by',
        'due_date',
        'issue_date',
        'paid_date',
        'archived_at',
        'is_recurring',
        'recurring_frequency',
        'recurring_end_date',
        'parent_invoice_id',
        'auto_pay_enabled',
        'auto_pay_payment_method_id',
        'last_auto_pay_attempt',
        'auto_pay_failure_reason',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'due_date' => 'date',
        'issue_date' => 'date',
        'paid_date' => 'date',
        'archived_at' => 'datetime',
        'disputed_at' => 'datetime',
        'dispute_resolved_at' => 'datetime',
        'is_recurring' => 'boolean',
        'recurring_end_date' => 'date',
        'auto_pay_enabled' => 'boolean',
        'last_auto_pay_attempt' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceProvider()
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function installmentPlan()
    {
        return $this->hasOne(InstallmentPlan::class);
    }

    public function installmentPayments()
    {
        return $this->hasManyThrough(InstallmentPayment::class, InstallmentPlan::class);
    }

    public function parentInvoice()
    {
        return $this->belongsTo(Invoice::class, 'parent_invoice_id');
    }

    public function recurringInvoices()
    {
        return $this->hasMany(Invoice::class, 'parent_invoice_id');
    }

    public function autoPayPaymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'auto_pay_payment_method_id');
    }

    public function disputeResolver()
    {
        return $this->belongsTo(User::class, 'dispute_resolved_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeOverdue($query)
    {
        return $query->where(function($q) {
            $q->where('status', 'overdue')
              ->orWhere(function($subQ) {
                  $subQ->where('status', 'pending')
                       ->where('due_date', '<', now());
              });
        });
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForProvider($query, $providerId)
    {
        return $query->where('service_provider_id', $providerId);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function scopeNotArchived($query)
    {
        return $query->where('status', '!=', 'archived');
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeWithAutoPay($query)
    {
        return $query->where('auto_pay_enabled', true);
    }

    public function scopeEligibleForAutoPay($query)
    {
        return $query->where('is_recurring', true)
            ->where('auto_pay_enabled', true)
            ->where('status', 'pending')
            ->whereNotNull('auto_pay_payment_method_id')
            ->where(function($q) {
                $q->whereNull('recurring_end_date')
                  ->orWhere('recurring_end_date', '>=', now());
            });
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'archived');
    }

    public function scopeDisputed($query)
    {
        return $query->where('dispute_status', '!=', 'none');
    }

    public function scopePendingDisputes($query)
    {
        return $query->where('dispute_status', 'pending');
    }

    public function scopeUnderReviewDisputes($query)
    {
        return $query->where('dispute_status', 'under_review');
    }

    // Accessors & Mutators
    public function getIsOverdueAttribute()
    {
        return $this->status === 'pending' && $this->due_date < now();
    }

    public function getIsPaidAttribute()
    {
        return $this->status === 'paid';
    }

    public function getTotalPaidAttribute()
    {
        return $this->payments()->where('status', 'completed')->sum('amount');
    }

    public function getTotalRefundedAttribute()
    {
        return $this->refunds()->where('status', 'completed')->sum('amount');
    }

    public function getNetPaidAttribute()
    {
        return $this->total_paid - $this->total_refunded;
    }

    public function getRemainingAmountAttribute()
    {
        // Remaining amount = total_amount - (total_paid - total_refunded)
        return $this->total_amount - $this->net_paid;
    }

    /**
     * Calculate total amount including fees and taxes
     * total_amount = amount + tax_amount + fee_amount
     */
    public function calculateTotalAmount(): float
    {
        $amount = (float) ($this->amount ?? 0);
        $taxAmount = (float) ($this->tax_amount ?? 0);
        $feeAmount = (float) ($this->fee_amount ?? 0);
        
        return round($amount + $taxAmount + $feeAmount, 2);
    }

    /**
     * Get subtotal (amount before taxes and fees)
     */
    public function getSubtotalAttribute(): float
    {
        return (float) ($this->amount ?? 0);
    }

    public function getFormattedAmountAttribute()
    {
        return number_format($this->total_amount, 2);
    }

    /**
     * Get the effective display status, including partially_paid
     */
    public function getDisplayStatusAttribute()
    {
        // If already paid, cancelled, or archived, return the actual status
        if (in_array($this->status, ['paid', 'cancelled', 'archived'])) {
            return $this->status;
        }

        // Check if invoice has partial payments
        $netPaid = $this->net_paid;
        $remainingAmount = $this->remaining_amount;

        // If there are payments but invoice is not fully paid, it's partially paid
        if ($netPaid > 0 && $remainingAmount > 0) {
            return 'partially_paid';
        }

        // Otherwise return the actual status (pending, overdue)
        return $this->status;
    }

    /**
     * Check if invoice is partially paid
     */
    public function getIsPartiallyPaidAttribute()
    {
        return $this->display_status === 'partially_paid';
    }

    // Methods
    public function markAsPaid()
    {
        $this->update([
            'status' => 'paid',
            'paid_date' => now(),
        ]);
    }

    public function markAsOverdue()
    {
        if ($this->status === 'pending' && $this->due_date < now()) {
            $this->update(['status' => 'overdue']);
        }
    }

    public function cancel($reason = null)
    {
        // Cannot cancel paid invoices
        if ($this->status === 'paid') {
            throw new \Exception('Cannot cancel paid invoice.');
        }

        // Cannot cancel already cancelled invoices
        if ($this->status === 'cancelled') {
            throw new \Exception('Invoice is already cancelled.');
        }

        $metadata = $this->metadata ?? [];
        if ($reason) {
            $metadata['cancellation_reason'] = $reason;
            $metadata['cancelled_at'] = now()->toIso8601String();
        }

        $this->update([
            'status' => 'cancelled',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Archive the invoice
     */
    public function archive(): void
    {
        if ($this->status === 'archived') {
            return; // Already archived
        }

        $this->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);
    }

    /**
     * Unarchive the invoice (restore to previous status)
     */
    public function unarchive(): void
    {
        if ($this->status !== 'archived') {
            throw new \Exception('Invoice is not archived.');
        }

        // Determine previous status based on paid_date or default to pending
        $previousStatus = $this->paid_date ? 'paid' : 'pending';

        $this->update([
            'status' => $previousStatus,
            'archived_at' => null,
        ]);
    }

    /**
     * Check if invoice is archived
     */
    public function getIsArchivedAttribute(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Dispute the invoice
     */
    public function dispute(string $reason): void
    {
        // Cannot dispute if already disputed
        if ($this->dispute_status !== 'none') {
            throw new \Exception('Invoice is already disputed.');
        }

        // Cannot dispute paid invoices (unless allowed by business rules)
        // For now, we'll allow disputes on any status
        // Business can decide if paid invoices can be disputed

        $this->update([
            'dispute_status' => 'pending',
            'dispute_reason' => $reason,
            'disputed_at' => now(),
        ]);
    }

    /**
     * Move dispute to under review (admin/service provider action)
     */
    public function markDisputeUnderReview(): void
    {
        if ($this->dispute_status !== 'pending') {
            throw new \Exception('Dispute must be in pending status to move to under review.');
        }

        $this->update([
            'dispute_status' => 'under_review',
        ]);
    }

    /**
     * Resolve dispute (approve the dispute)
     */
    public function resolveDispute(string $resolution, int $resolvedBy): void
    {
        if (!in_array($this->dispute_status, ['pending', 'under_review'])) {
            throw new \Exception('Dispute must be in pending or under_review status to be resolved.');
        }

        $this->update([
            'dispute_status' => 'resolved',
            'dispute_resolution' => $resolution,
            'dispute_resolved_at' => now(),
            'dispute_resolved_by' => $resolvedBy,
        ]);
    }

    /**
     * Reject dispute
     */
    public function rejectDispute(string $resolution, int $rejectedBy): void
    {
        if (!in_array($this->dispute_status, ['pending', 'under_review'])) {
            throw new \Exception('Dispute must be in pending or under_review status to be rejected.');
        }

        $this->update([
            'dispute_status' => 'rejected',
            'dispute_resolution' => $resolution,
            'dispute_resolved_at' => now(),
            'dispute_resolved_by' => $rejectedBy,
        ]);
    }

    /**
     * Check if invoice is disputed
     */
    public function getIsDisputedAttribute(): bool
    {
        return $this->dispute_status !== 'none';
    }

    /**
     * Check if dispute is pending
     */
    public function getIsDisputePendingAttribute(): bool
    {
        return $this->dispute_status === 'pending';
    }

    /**
     * Check if dispute is under review
     */
    public function getIsDisputeUnderReviewAttribute(): bool
    {
        return $this->dispute_status === 'under_review';
    }

    /**
     * Check if dispute is resolved
     */
    public function getIsDisputeResolvedAttribute(): bool
    {
        return $this->dispute_status === 'resolved';
    }

    /**
     * Check if dispute is rejected
     */
    public function getIsDisputeRejectedAttribute(): bool
    {
        return $this->dispute_status === 'rejected';
    }

    /**
     * Check if a duplicate invoice exists
     * 
     * @param array $data Invoice data to check
     * @param int|null $excludeId Invoice ID to exclude from check (for updates)
     * @return Invoice|null
     */
    public static function findDuplicate(array $data, ?int $excludeId = null): ?self
    {
        $query = static::query();

        // Check for duplicates based on configurable rules
        $duplicateCheckFields = config('invoices.duplicate_check_fields', [
            'user_id',
            'service_provider_id',
            'title',
            'total_amount',
        ]);

        // Build query based on enabled duplicate check fields
        // All fields must match for it to be considered a duplicate
        $query->where(function($q) use ($data, $duplicateCheckFields) {
            $hasConditions = false;
            foreach ($duplicateCheckFields as $field) {
                if (isset($data[$field]) && $data[$field] !== null) {
                    $hasConditions = true;
                    if ($field === 'total_amount') {
                        // For amounts, allow small variance (0.01) to account for rounding
                        $q->whereBetween($field, [
                            (float) $data[$field] - 0.01,
                            (float) $data[$field] + 0.01
                        ]);
                    } else {
                        $q->where($field, $data[$field]);
                    }
                }
            }
            // If no conditions were added, return no results (safety check)
            if (!$hasConditions) {
                $q->whereRaw('1 = 0');
            }
        });

        // Exclude current invoice if updating
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // Only check non-cancelled invoices
        $query->where('status', '!=', 'cancelled');

        // Check within time window if configured
        $timeWindow = config('invoices.duplicate_check_time_window', 30); // days
        if ($timeWindow > 0) {
            $query->where('created_at', '>=', now()->subDays($timeWindow));
        }

        return $query->first();
    }

    /**
     * Check if this invoice is a duplicate
     * 
     * @param array $data Invoice data to check
     * @return bool
     */
    public static function isDuplicate(array $data, ?int $excludeId = null): bool
    {
        return static::findDuplicate($data, $excludeId) !== null;
    }

    public static function generateInvoiceNumber()
    {
        $prefix = 'INV-' . date('Y') . '-';
        $lastInvoice = static::where('invoice_number', 'like', $prefix . '%')
                           ->orderBy('invoice_number', 'desc')
                           ->first();
        
        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, strlen($prefix));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
    }
}
