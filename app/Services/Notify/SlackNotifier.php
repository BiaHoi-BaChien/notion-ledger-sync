<?php

namespace App\Services\Notify;

use Illuminate\Support\Facades\Http;

class SlackNotifier
{
    public function notifyMonthlyReport(array $result): void
    {
        if (! config('services.slack.enabled')) {
            return;
        }

        $token = config('services.slack.token');
        $rawIds = trim((string) config('services.slack.dm_user_ids'));
        $userIds = $rawIds === '' ? [] : preg_split('/[\s,]+/', $rawIds, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($token) || empty($userIds)) {
            return;
        }

        $text = $this->buildText($result);
        $unfurlLinks = filter_var(config('services.slack.unfurl_links'), FILTER_VALIDATE_BOOLEAN);
        $unfurlMedia = filter_var(config('services.slack.unfurl_media'), FILTER_VALIDATE_BOOLEAN);

        foreach ($userIds as $userId) {
            Http::withToken($token)
                ->asForm()
                ->post('https://slack.com/api/chat.postMessage', [
                    'channel' => $userId,
                    'text' => $text,
                    'unfurl_links' => $unfurlLinks,
                    'unfurl_media' => $unfurlMedia,
                ])->throw();
        }
    }

    private function buildText(array $result): string
    {
        $lines = [
            sprintf('Notion月次集計 %s 完了', $result['year_month']),
        ];

        foreach ($result['totals'] as $account => $amount) {
            $lines[] = sprintf('%s: %s', $account, number_format((float) $amount));
        }

        $lines[] = '合計: '.number_format((float) $result['total_all']);
        $lines[] = '件数: '.$result['records_count'];

        return implode("\n", $lines);
    }
}
