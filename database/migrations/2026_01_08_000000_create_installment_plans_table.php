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
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->string('plan_name')->nullable();
            $table->integer('total_installments');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('installment_amount', 10, 2);
            $table->enum('frequency', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'custom'])->default('monthly');
            $table->integer('frequency_days')->nullable(); // For custom frequency
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'completed', 'cancelled', 'paused'])->default('active');
            $table->foreignId('payment_method_id')->nullable()->constrained()->onDelete('set null');
            $table->boolean('auto_charge')->default(false);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['invoice_id', 'status']);
            $table->index('start_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};

