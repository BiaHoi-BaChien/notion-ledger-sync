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

        if (! empty($result['aborted_reason'] ?? null)) {
            $lines[] = '処理中止: '.$result['aborted_reason'];
            $lines[] = '集計結果: なし';
            $lines[] = '繰越登録状況: 実施なし';

            return implode("\n", $lines);
        }

        foreach ($result['totals'] as $account => $amount) {
            $lines[] = sprintf('%s: %s', $account, number_format((float) $amount));
        }

        $lines[] = '件数: '.$result['records_count'];

        if (! empty($result['carry_over_status'])) {
            $lines[] = '繰越登録状況:';

            $successEntries = array_filter(
                $result['carry_over_status'],
                static fn ($entry) => ($entry['status'] ?? null) === 'success'
            );

            $failureEntries = array_filter(
                $result['carry_over_status'],
                static fn ($entry) => ($entry['status'] ?? null) === 'failure'
            );

            if ($failureEntries === []) {
                $lines[] = '・全件成功';
            } else {
                if ($successEntries !== []) {
                    $lines[] = '・成功: '.implode(', ', array_map(
                        static function ($entry) {
                            $createdAt = $entry['created_at'] ?? null;

                            if ($createdAt === null) {
                                return $entry['account'];
                            }

                            return sprintf('%s(作成日: %s)', $entry['account'], $createdAt);
                        },
                        $successEntries
                    ));
                }

                $lines[] = '・失敗: '.implode(', ', array_map(
                    static fn ($entry) => $entry['account'],
                    $failureEntries
                ));
            }
        }

        return implode("\n", $lines);
    }
}
