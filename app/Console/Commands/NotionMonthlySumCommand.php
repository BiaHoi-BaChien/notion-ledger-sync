<?php

namespace App\Console\Commands;

use App\Services\MonthlySumRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NotionMonthlySumCommand extends Command
{
    protected $signature = 'notion:monthly-sum {year_month?}';

    protected $description = 'Run Notion monthly sum and notify results';

    public function handle(MonthlySumRunner $runner): int
    {
        $timezone = config('app.timezone', 'UTC');
        $yearMonth = $this->argument('year_month')
            ?? Carbon::now($timezone)->subMonth()->format('Y-m');

        $this->info('Notion月次集計を開始します: '.$yearMonth);

        $output = json_encode(
            $runner->run($yearMonth),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (is_string($output)) {
            foreach (preg_split('/\R/', $output) ?: [] as $line) {
                $this->line($line);
            }
        }

        return self::SUCCESS;
    }
}
