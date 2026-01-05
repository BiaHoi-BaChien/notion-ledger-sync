<?php

namespace App\Console\Commands;

use App\Services\MonthlySumService;
use App\Services\Notify\MailNotifier;
use App\Services\Notify\SlackNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NotionMonthlySumCommand extends Command
{
    protected $signature = 'notion:monthly-sum {year_month?}';

    protected $description = 'Run Notion monthly sum and notify results';

    public function handle(
        MonthlySumService $service,
        MailNotifier $mailNotifier,
        SlackNotifier $slackNotifier
    ): int {
        $timezone = config('app.timezone', 'UTC');
        $yearMonth = $this->argument('year_month')
            ?? Carbon::now($timezone)->subMonth()->format('Y-m');

        $this->info('Notion月次集計を開始します: '.$yearMonth);

        $started = microtime(true);
        $result = $service->run($yearMonth);
        $durationMs = (microtime(true) - $started) * 1000;
        $runAt = Carbon::now('UTC');

        $payload = [
            'result' => $result,
            'run_at' => $runAt,
            'duration_ms' => $durationMs,
        ];

        $notified = ['mail' => false, 'slack' => false];

        try {
            if (filled(config('services.report.mail_to'))) {
                $mailNotifier->sendMonthlyReport($payload);
                $notified['mail'] = true;
            }
        } catch (\Throwable $e) {
            Log::warning('mail.notify.failed', ['message' => $e->getMessage()]);
        }

        try {
            if (config('services.slack.enabled')) {
                $slackNotifier->notifyMonthlyReport($result);
                $notified['slack'] = true;
            }
        } catch (\Throwable $e) {
            Log::warning('slack.notify.failed', [
                'message' => sprintf('chat.postMessage: %s', $e->getMessage()),
            ]);
        }

        Log::info('notion.monthly_sum.completed', [
            'year_month' => $result['year_month'],
            'records_count' => $result['records_count'],
            'total_all' => $result['total_all'],
            'duration_ms' => $durationMs,
        ]);

        $this->line(json_encode(
            array_merge($payload, ['notified' => $notified]),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return self::SUCCESS;
    }
}
