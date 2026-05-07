<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify payment_type enum to include 'installment'
        // MySQL requires specifying all enum values when modifying
        DB::statement("ALTER TABLE payments MODIFY COLUMN payment_type ENUM('full', 'partial', 'installment') DEFAULT 'full'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert payment_type enum to original values
        // Note: This will fail if there are any payments with payment_type = 'installment'
        DB::statement("ALTER TABLE payments MODIFY COLUMN payment_type ENUM('full', 'partial') DEFAULT 'full'");
    }
};
