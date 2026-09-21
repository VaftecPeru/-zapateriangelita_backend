<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Diseñado para hosting cPanel: basta un único cron que ejecute
        // "php artisan schedule:run" cada minuto.
        $schedule->command('checkout:process-notifications --limit=25')
            ->everyMinute()
            ->withoutOverlapping(10);

        $schedule->command('checkout:reconcile-openpay --limit=20')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
