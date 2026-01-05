<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        if (config('services.monthly_sum.schedule_enabled', true)) {
            $schedule->command('notion:monthly-sum')
                ->monthlyOn(1, '0:00')
                ->timezone(config('app.timezone', 'UTC'));
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
