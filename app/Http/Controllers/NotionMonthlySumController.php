<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonthlySumRequest;
use App\Services\MonthlySumService;
use App\Services\Notify\MailNotifier;
use App\Services\Notify\SlackNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class NotionMonthlySumController extends Controller
{
    public function handle(
        MonthlySumRequest $request,
        MonthlySumService $service,
        MailNotifier $mailNotifier,
        SlackNotifier $slackNotifier
    ) {
        $token = $request->header('X-Webhook-Token');
        if (! $token || $token !== config('services.webhook.token')) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $timezone = config('app.timezone', 'UTC');
        $yearMonth = $request->validated(
            'year_month',
            Carbon::now($timezone)->subMonth()->format('Y-m')
        );

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
            Log::warning('slack.notify.failed', ['message' => $e->getMessage()]);
        }

        Log::info('notion.monthly_sum.completed', [
            'year_month' => $result['year_month'],
            'records_count' => $result['records_count'],
            'total_all' => $result['total_all'],
            'duration_ms' => $durationMs,
        ]);

        return response()->noContent();
    }
}
