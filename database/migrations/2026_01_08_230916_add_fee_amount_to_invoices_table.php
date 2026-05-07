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
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('fee_amount', 10, 2)->default(0)->after('tax_amount');
        });
        
        // Update existing invoices to recalculate total_amount including fees
        // Since fee_amount will default to 0, existing invoices won't change
        // But we need to ensure the calculation is correct
        DB::statement('UPDATE invoices SET total_amount = amount + COALESCE(tax_amount, 0) + COALESCE(fee_amount, 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('fee_amount');
        });
    }
};
