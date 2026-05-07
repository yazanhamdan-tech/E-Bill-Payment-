<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Services\ActivityLogService;

class ArchiveInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:archive 
                            {--days=365 : Number of days after which to archive invoices}
                            {--status=paid : Only archive invoices with this status (paid, cancelled)}
                            {--dry-run : Show what would be archived without actually archiving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive old invoices automatically based on age and status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $status = $this->option('status');
        $dryRun = $this->option('dry-run');

        $this->info("Checking for invoices to archive (older than {$days} days, status: {$status})...");

        // Calculate the cutoff date
        $cutoffDate = Carbon::now()->subDays($days);

        // Build query - only archive paid or cancelled invoices
        $query = Invoice::where('status', '!=', 'archived') // Don't archive already archived
                       ->where('created_at', '<', $cutoffDate);

        // Filter by status
        if ($status === 'paid') {
            $query->where('status', 'paid');
        } elseif ($status === 'cancelled') {
            $query->where('status', 'cancelled');
        } else {
            // Default: archive both paid and cancelled
            $query->whereIn('status', ['paid', 'cancelled']);
        }

        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->info("No invoices found to archive.");
            return 0;
        }

        $this->info("Found {$invoices->count()} invoice(s) to archive.");

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No invoices will be archived.");
            $this->table(
                ['ID', 'Invoice Number', 'Status', 'Amount', 'Created At'],
                $invoices->map(function ($invoice) {
                    return [
                        $invoice->id,
                        $invoice->invoice_number,
                        $invoice->status,
                        '$' . number_format($invoice->total_amount, 2),
                        $invoice->created_at->format('Y-m-d'),
                    ];
                })->toArray()
            );
            return 0;
        }

        $archivedCount = 0;
        $bar = $this->output->createProgressBar($invoices->count());
        $bar->start();

        foreach ($invoices as $invoice) {
            try {
                $invoice->archive();
                $archivedCount++;

                // Log activity
                try {
                    ActivityLogService::log(
                        'invoice_archived',
                        "Invoice {$invoice->invoice_number} archived automatically",
                        $invoice
                    );
                } catch (\Exception $e) {
                    // Don't fail if logging fails
                }
            } catch (\Exception $e) {
                $this->error("Failed to archive invoice #{$invoice->invoice_number}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Successfully archived {$archivedCount} invoice(s).");

        return 0;
    }
}
