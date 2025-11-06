<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonthlySumRequest;
use App\Services\MonthlySumService;
use App\Services\Notify\MailNotifier;
use App\Services\Notify\SlackNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class NotionMonthlySumController extends Controller
{
    public function handle(
        MonthlySumRequest $request,
        MonthlySumService $service,
        MailNotifier $mailNotifier,
        SlackNotifier $slackNotifier,
        Request $rawRequest
    ) {
        $token = $rawRequest->header('X-Webhook-Token');
        if (! $token || $token !== config('services.webhook.token')) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $yearMonth = $request->validated('year_month');

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

        $responseKeys = config('services.response.keys', []);
        $key = static fn (string $name, string $default) => $responseKeys[$name] ?? $default;

        $responsePayload = [
            $key('year_month', 'year_month') => $result['year_month'],
            $key('range', 'range') => [
                $key('range_start', 'start') => Arr::get($result, 'range.start'),
                $key('range_end', 'end') => Arr::get($result, 'range.end'),
            ],
            $key('totals', 'totals') => $result['totals'],
            $key('total_all', 'total_all') => $result['total_all'],
            $key('records_count', 'records_count') => $result['records_count'],
        ];

        $responsePayload[$key('notified', 'notified')] = [
            $key('notified_mail', 'mail') => $notified['mail'],
            $key('notified_slack', 'slack') => $notified['slack'],
        ];

        return response()->json($responsePayload);
    }
}
