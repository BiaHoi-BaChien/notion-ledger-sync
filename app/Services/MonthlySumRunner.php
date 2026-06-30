<?php

namespace App\Services;

use App\Services\Notify\MailNotifier;
use App\Services\Notify\SlackNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonthlySumRunner
{
    public function __construct(
        private readonly MonthlySumService $service,
        private readonly MailNotifier $mailNotifier,
        private readonly SlackNotifier $slackNotifier,
    ) {}

    public function run(string $yearMonth): array
    {
        $started = microtime(true);
        $result = $this->service->run($yearMonth);
        $durationMs = (microtime(true) - $started) * 1000;
        $payload = [
            'result' => $result,
            'run_at' => Carbon::now('UTC'),
            'duration_ms' => $durationMs,
        ];

        $notified = ['mail' => false, 'slack' => false];

        try {
            if (filled(config('services.report.mail_to'))) {
                $this->mailNotifier->sendMonthlyReport($payload);
                $notified['mail'] = true;
            }
        } catch (Throwable $e) {
            Log::warning('mail.notify.failed', ['message' => $e->getMessage()]);
        }

        try {
            if (config('services.slack.enabled')) {
                $this->slackNotifier->notifyMonthlyReport($result);
                $notified['slack'] = true;
            }
        } catch (Throwable $e) {
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

        return $payload + ['notified' => $notified];
    }
}
