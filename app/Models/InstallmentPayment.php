<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class InstallmentPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'installment_plan_id',
        'invoice_id',
        'installment_number',
        'amount',
        'due_date',
        'paid_date',
        'status',
        'payment_id',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'paid_date' => 'date',
        'metadata' => 'array',
    ];

    // Relationships
    public function installmentPlan()
    {
        return $this->belongsTo(InstallmentPlan::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    // Accessors & Mutators
    public function getIsOverdueAttribute()
    {
        return $this->status === 'pending' && $this->due_date < now();
    }

    public function getIsDueAttribute()
    {
        return $this->status === 'pending' && $this->due_date <= now();
    }

    public function getDaysUntilDueAttribute()
    {
        if ($this->status !== 'pending') {
            return null;
        }
        return max(0, now()->diffInDays($this->due_date, false));
    }

    // Methods
    public function markAsPaid($paymentId = null)
    {
        $this->update([
            'status' => 'paid',
            'paid_date' => now(),
            'payment_id' => $paymentId,
        ]);

        // Check if all installments are paid
        $plan = $this->installmentPlan;
        if ($plan && $plan->paid_installments_count >= $plan->total_installments) {
            $plan->markAsCompleted();
        }
    }

    public function markAsOverdue()
    {
        if ($this->status === 'pending' && $this->due_date < now()) {
            $this->update(['status' => 'overdue']);
        }
    }

    public function markAsFailed()
    {
        $this->update(['status' => 'failed']);
    }

    public function skip()
    {
        $this->update(['status' => 'skipped']);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopeDue($query)
    {
        return $query->where('status', 'pending')
                    ->where('due_date', '<=', now());
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
}
