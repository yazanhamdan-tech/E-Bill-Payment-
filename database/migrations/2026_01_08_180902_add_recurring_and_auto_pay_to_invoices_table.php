<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('status');
            $table->enum('recurring_frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'])->nullable()->after('is_recurring');
            $table->date('recurring_end_date')->nullable()->after('recurring_frequency');
            $table->foreignId('parent_invoice_id')->nullable()->constrained('invoices')->onDelete('set null')->after('recurring_end_date');
            $table->boolean('auto_pay_enabled')->default(false)->after('parent_invoice_id');
            $table->foreignId('auto_pay_payment_method_id')->nullable()->constrained('payment_methods')->onDelete('set null')->after('auto_pay_enabled');
            $table->timestamp('last_auto_pay_attempt')->nullable()->after('auto_pay_payment_method_id');
            $table->text('auto_pay_failure_reason')->nullable()->after('last_auto_pay_attempt');
            
            $table->index(['is_recurring', 'auto_pay_enabled', 'status']);
            $table->index('parent_invoice_id');
            $table->index('recurring_frequency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['is_recurring', 'auto_pay_enabled', 'status']);
            $table->dropIndex(['parent_invoice_id']);
            $table->dropIndex(['recurring_frequency']);
            
            $table->dropForeign(['parent_invoice_id']);
            $table->dropForeign(['auto_pay_payment_method_id']);
            
            $table->dropColumn([
                'is_recurring',
                'recurring_frequency',
                'recurring_end_date',
                'parent_invoice_id',
                'auto_pay_enabled',
                'auto_pay_payment_method_id',
                'last_auto_pay_attempt',
                'auto_pay_failure_reason',
            ]);
        });
    }
};
