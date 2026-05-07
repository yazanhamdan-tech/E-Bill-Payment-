<?php

namespace App\Console\Commands;

use App\Services\AutoPayService;
use Illuminate\Console\Command;

class ProcessAutoPay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:process-autopay 
                            {--dry-run : Show what would be processed without actually processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process automatic payments for recurring invoices with auto-pay enabled';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Processing auto-pay for recurring invoices...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No payments will be processed.');
            
            $autoPayService = app(AutoPayService::class);
            $invoices = \App\Models\Invoice::eligibleForAutoPay()
                ->where('due_date', '<=', now())
                ->with(['user', 'autoPayPaymentMethod'])
                ->get();
            
            if ($invoices->isEmpty()) {
                $this->info('No invoices found eligible for auto-pay.');
                return 0;
            }
            
            $this->info("Found {$invoices->count()} invoice(s) eligible for auto-pay.");
            $this->table(
                ['ID', 'Invoice Number', 'Customer', 'Amount', 'Due Date', 'Payment Method'],
                $invoices->map(function ($invoice) {
                    return [
                        $invoice->id,
                        $invoice->invoice_number,
                        $invoice->user->name ?? 'N/A',
                        '$' . number_format($invoice->total_amount, 2),
                        $invoice->due_date->format('Y-m-d'),
                        $invoice->autoPayPaymentMethod->type ?? 'N/A',
                    ];
                })->toArray()
            );
            
            return 0;
        }

        $autoPayService = app(AutoPayService::class);
        $results = $autoPayService->processAutoPay();

        $this->info("Processed: {$results['processed']} invoice(s)");
        $this->info("Succeeded: {$results['succeeded']} payment(s)");
        $this->info("Failed: {$results['failed']} payment(s)");

        if (!empty($results['errors'])) {
            $this->warn('Errors encountered:');
            foreach ($results['errors'] as $error) {
                $this->error("  Invoice #{$error['invoice_number']}: {$error['error']}");
            }
        }

        return 0;
    }
}
