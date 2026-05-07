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
            $table->enum('dispute_status', ['none', 'pending', 'under_review', 'resolved', 'rejected'])->default('none')->after('status');
            $table->text('dispute_reason')->nullable()->after('dispute_status');
            $table->timestamp('disputed_at')->nullable()->after('dispute_reason');
            $table->text('dispute_resolution')->nullable()->after('disputed_at');
            $table->timestamp('dispute_resolved_at')->nullable()->after('dispute_resolution');
            $table->foreignId('dispute_resolved_by')->nullable()->after('dispute_resolved_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['dispute_resolved_by']);
            $table->dropColumn([
                'dispute_status',
                'dispute_reason',
                'disputed_at',
                'dispute_resolution',
                'dispute_resolved_at',
                'dispute_resolved_by',
            ]);
        });
    }
};
