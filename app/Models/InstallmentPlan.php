<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class InstallmentPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'plan_name',
        'total_installments',
        'total_amount',
        'installment_amount',
        'frequency',
        'frequency_days',
        'start_date',
        'end_date',
        'status',
        'payment_method_id',
        'auto_charge',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'frequency_days' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_charge' => 'boolean',
        'metadata' => 'array',
    ];

    // Relationships
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function installmentPayments()
    {
        return $this->hasMany(InstallmentPayment::class)->orderBy('due_date')->orderBy('installment_number');
    }

    // Accessors & Mutators
    public function getPaidInstallmentsCountAttribute()
    {
        if ($this->relationLoaded('installmentPayments')) {
            return $this->installmentPayments->where('status', 'paid')->count();
        }
        return $this->installmentPayments()->where('status', 'paid')->count();
    }

    public function getPendingInstallmentsCountAttribute()
    {
        if ($this->relationLoaded('installmentPayments')) {
            return $this->installmentPayments->where('status', 'pending')->count();
        }
        return $this->installmentPayments()->where('status', 'pending')->count();
    }

    public function getOverdueInstallmentsCountAttribute()
    {
        if ($this->relationLoaded('installmentPayments')) {
            return $this->installmentPayments->where('status', 'overdue')->count();
        }
        return $this->installmentPayments()->where('status', 'overdue')->count();
    }

    public function getTotalPaidAttribute()
    {
        try {
            if ($this->relationLoaded('installmentPayments')) {
                $paid = $this->installmentPayments->where('status', 'paid');
                return (float) $paid->sum(function($item) {
                    return (float) $item->amount;
                });
            }
            return (float) $this->installmentPayments()->where('status', 'paid')->sum('amount');
        } catch (\Exception $e) {
            \Log::error('Error calculating total_paid for installment plan', [
                'plan_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function getRemainingAmountAttribute()
    {
        return $this->total_amount - $this->total_paid;
    }

    public function getProgressPercentageAttribute()
    {
        if ($this->total_installments == 0) {
            return 0;
        }
        $paidCount = $this->paid_installments_count;
        return round(($paidCount / $this->total_installments) * 100, 2);
    }

    public function getIsCompletedAttribute()
    {
        return $this->status === 'completed' || $this->paid_installments_count >= $this->total_installments;
    }

    // Methods
    public function generateInstallments()
    {
        // Delete existing installments if regenerating
        $this->installmentPayments()->delete();

        $installments = [];
        // Ensure start_date is a Carbon instance
        $currentDate = $this->start_date instanceof Carbon 
            ? $this->start_date 
            : Carbon::parse($this->start_date);
        $installmentAmount = $this->installment_amount;
        $totalAmount = $this->total_amount;
        
        // Calculate days between installments based on frequency
        $daysBetween = $this->getDaysBetweenInstallments();

        for ($i = 1; $i <= $this->total_installments; $i++) {
            // Last installment gets any remainder to ensure exact total
            if ($i === $this->total_installments) {
                $amount = $totalAmount - (($i - 1) * $installmentAmount);
            } else {
                $amount = $installmentAmount;
            }

            $dueDate = $currentDate->copy()->addDays(($i - 1) * $daysBetween);

            $installments[] = [
                'installment_plan_id' => $this->id,
                'invoice_id' => $this->invoice_id,
                'installment_number' => $i,
                'amount' => round($amount, 2),
                'due_date' => $dueDate->format('Y-m-d'),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        InstallmentPayment::insert($installments);
    }

    protected function getDaysBetweenInstallments()
    {
        switch ($this->frequency) {
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
                return $this->frequency_days ?? 30;
            default:
                return 30;
        }
    }

    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'end_date' => now(),
        ]);
    }

    public function cancel()
    {
        $this->update(['status' => 'cancelled']);
    }

    public function pause()
    {
        $this->update(['status' => 'paused']);
    }

    public function resume()
    {
        $this->update(['status' => 'active']);
    }
}
