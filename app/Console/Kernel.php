<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Send payment reminders 7 days before due date (daily at 9 AM)
        $schedule->command('invoices:send-payment-reminders --days=7')
                 ->dailyAt('09:00')
                 ->name('send-7-day-payment-reminders')
                 ->withoutOverlapping();

        // Send payment reminders 3 days before due date (daily at 9 AM)
        $schedule->command('invoices:send-payment-reminders --days=3')
                 ->dailyAt('09:00')
                 ->name('send-3-day-payment-reminders')
                 ->withoutOverlapping();

        // Send payment reminders 1 day before due date (daily at 9 AM)
        $schedule->command('invoices:send-payment-reminders --days=1')
                 ->dailyAt('09:00')
                 ->name('send-1-day-payment-reminders')
                 ->withoutOverlapping();

        // Send overdue invoice reminders (daily at 10 AM)
        $schedule->command('invoices:send-overdue-reminders')
                 ->dailyAt('10:00')
                 ->name('send-overdue-reminders')
                 ->withoutOverlapping();

        // Archive old invoices (weekly on Sunday at 2 AM)
        $schedule->command('invoices:archive --days=365 --status=paid')
                 ->weeklyOn(0, '02:00')
                 ->name('archive-old-invoices')
                 ->withoutOverlapping();

        // Process auto-pay for recurring invoices (daily at 8 AM)
        $schedule->command('payments:process-autopay')
                 ->dailyAt('08:00')
                 ->name('process-autopay')
                 ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
