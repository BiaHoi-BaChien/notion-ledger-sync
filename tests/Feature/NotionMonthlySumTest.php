<?php

namespace Tests\Feature;

use App\Mail\MonthlySumReport;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotionMonthlySumTest extends TestCase
{
    private string $originalTimezone;
    private string $targetAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = Config::get('app.timezone', 'UTC');
        $this->targetAccount = Config::get('services.adjustment.target_account', '現金/普通預金');
        Config::set('services.notion.token', 'test-token');
        Config::set('services.notion.data_source_id', 'ds123');
        Config::set('services.notion.database_id', 'carryover-db');
        Config::set('services.notion.version', '2025-09-03');
        Config::set('services.webhook.token', 'hook-token');
    }

    protected function tearDown(): void
    {
        Config::set('app.timezone', $this->originalTimezone);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_monthly_sum_success(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2025, 11, 30, 0, 0, 0, 'UTC'));

        Config::set('app.timezone', 'Asia/Tokyo');
        Carbon::setTestNow(Carbon::create(2025, 11, 30, 0, 0, 0, 'UTC'));

        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', true);
        Config::set('services.slack.token', 'slack-token');
        Config::set('services.slack.dm_user_ids', 'U1,U2');
        Config::set('services.slack.unfurl_links', false);
        Config::set('services.slack.unfurl_media', false);

        Mail::fake();
        $carryOverRequests = [];

        Http::fake([
            'https://api.notion.com/v1/data_sources/ds123/query' => Http::sequence()
                ->push([
                    'results' => [
                        [
                            'properties' => [
                                '口座' => ['select' => ['name' => '定期預金']],
                                '金額' => ['number' => 1200],
                            ],
                        ],
                        [
                            'properties' => [
                                '口座' => ['select' => ['name' => $this->targetAccount]],
                                '金額' => ['number' => 800],
                            ],
                        ],
                    ],
                    'has_more' => true,
                    'next_cursor' => 'cursor-2',
                ])
                ->push([
                    'results' => [
                        [
                            'properties' => [
                                '口座' => ['select' => ['name' => '定期預金']],
                                '金額' => ['number' => 500],
                            ],
                        ],
                        [
                            'properties' => [
                                '口座' => ['select' => ['name' => null]],
                                '金額' => ['number' => 100],
                            ],
                        ],
                        [
                            'properties' => [
                                '口座' => ['select' => ['name' => $this->targetAccount]],
                                '金額' => ['number' => null],
                            ],
                        ],
                    ],
                    'has_more' => false,
                    'next_cursor' => null,
                ]),
            'https://api.notion.com/v1/pages' => function ($request) use (&$carryOverRequests) {
                $carryOverRequests[] = $request;

                return Http::response(['object' => 'page'], 200);
            },
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2025-11']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            $body = $mail->render();
            $this->assertStringContainsString('実行時刻: 2025-11-30 09:00:00 JST', $body);
            $this->assertStringNotContainsString('件数:', $body);
            $this->assertStringContainsString(sprintf('%s: 800', $this->targetAccount), $body);
            $this->assertStringContainsString("定期預金: 1,700", $body);
            $this->assertStringNotContainsString('合計:', $body);
            $this->assertStringContainsString("繰越登録状況:", $body);
            $this->assertStringContainsString("・全件成功", $body);

            return true;
        });
        $this->assertNotNull($sentMail);
        $this->assertSame('2025-11', $sentMail->result['year_month']);
        $this->assertSame([
            $this->targetAccount => 800.0,
            '定期預金' => 1700.0,
        ], $sentMail->result['totals']);
        $this->assertSame(3, $sentMail->result['records_count']);
        $this->assertSame(2500.0, $sentMail->result['total_all']);
        $this->assertSame([
            [
                'account' => $this->targetAccount,
                'status' => 'success',
                'created_at' => '2025-11-30T00:00:00+00:00',
            ],
            [
                'account' => '定期預金',
                'status' => 'success',
                'created_at' => '2025-11-30T00:00:00+00:00',
            ],
        ], $sentMail->result['carry_over_status']);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://slack.com/api/chat.postMessage') {
                return false;
            }
            return $request['channel'] === 'U2';
        });

        $slackRequests = Http::recorded(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage';
        });
        $this->assertCount(2, $slackRequests);

        foreach ($slackRequests as [$request]) {
            $text = Arr::get($request->data(), 'text');

            $this->assertStringContainsString("件数: 3", $text);
            $this->assertStringContainsString(sprintf('%s: 800', $this->targetAccount), $text);
            $this->assertStringContainsString("定期預金: 1,700", $text);
            $this->assertStringNotContainsString('合計:', $text);
            $this->assertStringContainsString("繰越登録状況:", $text);
            $this->assertStringContainsString("・全件成功", $text);
        }
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'https://api.notion.com/')) {
                return false;
            }

            $version = Arr::first($request->header('Notion-Version'));

            return $version === '2025-09-03'
                && str_contains($request->url(), '/data_sources/ds123/');
        });
        Http::assertSentCount(6);

        $this->assertCount(2, $carryOverRequests);

        $expectedAccounts = [$this->targetAccount, '定期預金'];
        $carryOverDate = '2025-12-01';

        foreach ($carryOverRequests as $request) {
            $payload = $request->data();

            $this->assertSame('carryover-db', Arr::get($payload, 'parent.database_id'));
            $this->assertSame('繰越', Arr::get($payload, 'properties.摘要.title.0.text.content'));
            $this->assertSame('繰越', Arr::get($payload, 'properties.種類.select.name'));
            $this->assertSame('繰越', Arr::get($payload, 'properties.カテゴリー.select.name'));
            $this->assertSame($carryOverDate, Arr::get($payload, 'properties.日付.date.start'));

            $account = Arr::get($payload, 'properties.口座.select.name');
            $this->assertContains($account, $expectedAccounts);

            if ($account === $this->targetAccount) {
                $this->assertSame(800.0, Arr::get($payload, 'properties.金額入力.number'));
            } elseif ($account === '定期預金') {
                $this->assertSame(1700.0, Arr::get($payload, 'properties.金額入力.number'));
            } else {
                $this->fail('Unexpected account in carry-over payload.');
            }
        }
    }

    public function test_monthly_sum_records_carry_over_failures(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 1, 31, 12, 0, 0, 'UTC'));

        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', true);
        Config::set('services.slack.token', 'slack-token');
        Config::set('services.slack.dm_user_ids', 'U1');
        Config::set('services.slack.unfurl_links', false);
        Config::set('services.slack.unfurl_media', false);

        Mail::fake();
        $carryOverRequests = [];

        Http::fake([
            'https://api.notion.com/v1/data_sources/ds123/query' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => $this->targetAccount]],
                            '金額' => ['number' => 1000],
                        ],
                    ],
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => '定期預金']],
                            '金額' => ['number' => 2000],
                        ],
                    ],
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages' => function ($request) use (&$carryOverRequests) {
                $carryOverRequests[] = $request;

                if (count($carryOverRequests) === 1) {
                    return Http::response(['object' => 'page'], 200);
                }

                return Http::response(['error' => 'rate_limited'], 500);
            },
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2026-01']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            $body = $mail->render();
            $this->assertStringContainsString('繰越登録状況:', $body);
            $this->assertStringContainsString(sprintf('・成功: %s(作成日: 2026-01-31T12:00:00+00:00)', $this->targetAccount), $body);
            $this->assertStringContainsString('・失敗: 定期預金', $body);

            return true;
        });

        $this->assertNotNull($sentMail);
        $this->assertSame([
            [
                'account' => $this->targetAccount,
                'status' => 'success',
                'created_at' => '2026-01-31T12:00:00+00:00',
            ],
            [
                'account' => '定期預金',
                'status' => 'failure',
                'created_at' => null,
            ],
        ], $sentMail->result['carry_over_status']);

        $slackRequests = Http::recorded(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage';
        });

        $this->assertCount(1, $slackRequests);

        [$slackRequest] = $slackRequests->first();
        $text = Arr::get($slackRequest->data(), 'text');
        $this->assertStringContainsString('繰越登録状況:', $text);
        $this->assertStringContainsString(sprintf('・成功: %s(作成日: 2026-01-31T12:00:00+00:00)', $this->targetAccount), $text);
        $this->assertStringContainsString('・失敗: 定期預金', $text);

        $this->assertCount(2, $carryOverRequests);
    }

    public function test_skips_notifications_when_disabled(): void
    {
        Config::set('services.report.mail_to', null);
        Config::set('services.slack.enabled', false);

        Mail::fake();
        $carryOverRequests = [];

        Http::fake([
            'https://api.notion.com/v1/data_sources/ds123/query' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => '定期預金']],
                            '金額' => ['number' => 200],
                        ],
                    ],
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages' => function ($request) use (&$carryOverRequests) {
                $carryOverRequests[] = $request;

                return Http::response(['object' => 'page'], 200);
            },
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2024-01']);

        $response->assertNoContent();

        Mail::assertNothingSent();
        Http::assertSentCount(3);
        $this->assertCount(2, $carryOverRequests);
    }

    public function test_handles_formula_amount_property(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);

        Mail::fake();
        $carryOverRequests = [];

        Http::fake([
            'https://api.notion.com/v1/data_sources/ds123/query' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => $this->targetAccount]],
                            '金額' => [
                                'type' => 'formula',
                                'formula' => ['type' => 'number', 'number' => -6000],
                            ],
                        ],
                    ],
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages' => function ($request) use (&$carryOverRequests) {
                $carryOverRequests[] = $request;

                return Http::response(['object' => 'page'], 200);
            },
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2025-11']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            return true;
        });
        $this->assertNotNull($sentMail);
        $this->assertSame([
            $this->targetAccount => -6000.0,
            '定期預金' => 0.0,
        ], $sentMail->result['totals']);
        $this->assertSame(-6000.0, $sentMail->result['total_all']);
        $this->assertSame(1, $sentMail->result['records_count']);

        Http::assertSentCount(3);
        $this->assertCount(2, $carryOverRequests);
    }

    public function test_resolves_data_source_id_from_database_when_missing(): void
    {
        Config::set('services.notion.data_source_id', null);
        Config::set('services.notion.database_id', 'db123');
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);

        Mail::fake();

        $carryOverRequests = [];

        Http::fake(function ($request) use (&$carryOverRequests) {
            if ($request->url() === 'https://api.notion.com/v1/databases/db123') {
                return Http::response([
                    'parent' => [
                        'type' => 'data_source_id',
                        'data_source_id' => 'resolved-ds',
                    ],
                ]);
            }

            if ($request->url() === 'https://api.notion.com/v1/data_sources/resolved-ds/query') {
                return Http::response([
                    'results' => [
                        [
                            'properties' => [
                                '口座' => ['select' => ['name' => $this->targetAccount]],
                                '金額' => ['number' => 400],
                            ],
                        ],
                    ],
                    'has_more' => false,
                    'next_cursor' => null,
                ]);
            }

            if ($request->url() === 'https://api.notion.com/v1/pages') {
                $carryOverRequests[] = $request;

                return Http::response(['object' => 'page'], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2024-05']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            return true;
        });
        $this->assertNotNull($sentMail);
        $this->assertSame([
            $this->targetAccount => 400.0,
            '定期預金' => 0.0,
        ], $sentMail->result['totals']);
        $this->assertSame(1, $sentMail->result['records_count']);
        $this->assertSame(400.0, $sentMail->result['total_all']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.notion.com/v1/databases/db123';
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.notion.com/v1/data_sources/resolved-ds/query';
        });

        Http::assertSentCount(4);
        $this->assertCount(2, $carryOverRequests);
    }

    public function test_validation_error(): void
    {
        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2024-13']);

        $response->assertStatus(422);
    }

    public function test_defaults_to_current_month_when_year_month_missing(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);
        $originalTimezone = config('app.timezone');
        Config::set('app.timezone', 'Asia/Tokyo');

        Mail::fake();
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 0, 30, 0, 'Asia/Tokyo'));

        Http::fake([
            'https://api.notion.com/*' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => $this->targetAccount]],
                            '金額' => ['number' => 5000],
                        ],
                    ],
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
        ]);

        try {
            $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
                ->postJson('/api/notion_webhook/monthly-sum');

            $response->assertNoContent();

            $sentMail = null;
            Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
                $sentMail = $mail;

                return true;
            });
            $this->assertNotNull($sentMail);
            $this->assertSame('2025-06', $sentMail->result['year_month']);
            $this->assertSame('2025-06-01T00:00:00+00:00', $sentMail->result['range']['start']);
            $this->assertSame('2025-07-01T00:00:00+00:00', $sentMail->result['range']['end']);
        } finally {
            Carbon::setTestNow();
            Config::set('app.timezone', $originalTimezone);
        }
    }

    public function test_requires_token(): void
    {
        $response = $this->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2024-10']);

        $response->assertStatus(401);
    }

    public function test_rejects_request_with_invalid_token(): void
    {
        $response = $this->withHeaders(['X-Webhook-Token' => 'invalid-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2024-10']);

        $response->assertStatus(401);
    }

    public function test_totals_include_configured_accounts_when_no_records(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);
        Config::set('services.monthly_sum.accounts', [
            '普通預金',
            '定期預金',
            '貯蓄預金',
        ]);

        Mail::fake();
        $carryOverRequests = [];

        Http::fake([
            'https://api.notion.com/v1/data_sources/ds123/query' => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages' => function ($request) use (&$carryOverRequests) {
                $carryOverRequests[] = $request;

                return Http::response(['object' => 'page'], 200);
            },
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2024-08']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            return true;
        });
        $this->assertNotNull($sentMail);
        $this->assertSame([
            '普通預金' => 0.0,
            '定期預金' => 0.0,
            '貯蓄預金' => 0.0,
        ], $sentMail->result['totals']);
        $this->assertSame(0, $sentMail->result['records_count']);
        $this->assertSame(0.0, $sentMail->result['total_all']);

        Http::assertSentCount(4);

        $this->assertCount(3, $carryOverRequests);
        $this->assertEqualsCanonicalizing([
            '普通預金',
            '定期預金',
            '貯蓄預金',
        ], array_map(fn ($request) => Arr::get($request->data(), 'properties.口座.select.name'), $carryOverRequests));

        foreach ($carryOverRequests as $request) {
            $payload = $request->data();

            $this->assertSame('carryover-db', Arr::get($payload, 'parent.database_id'));
            $this->assertSame('繰越', Arr::get($payload, 'properties.摘要.title.0.text.content'));
            $this->assertSame('繰越', Arr::get($payload, 'properties.種類.select.name'));
            $this->assertSame('繰越', Arr::get($payload, 'properties.カテゴリー.select.name'));
            $this->assertSame('2024-09-01', Arr::get($payload, 'properties.日付.date.start'));
            $this->assertSame(0.0, Arr::get($payload, 'properties.金額入力.number'));
        }
    }

    public function test_logs_slack_error_and_continues_other_notifications(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', true);
        Config::set('services.slack.token', 'slack-token');
        Config::set('services.slack.dm_user_ids', 'U1');

        Mail::fake();
        Log::fake();
        $carryOverRequests = [];

        Http::fake([
            'https://api.notion.com/v1/data_sources/ds123/query' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => $this->targetAccount]],
                            '金額' => ['number' => 1000],
                        ],
                    ],
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => '定期預金']],
                            '金額' => ['number' => 2000],
                        ],
                    ],
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages' => function ($request) use (&$carryOverRequests) {
                $carryOverRequests[] = $request;

                return Http::response(['object' => 'page'], 200);
            },
            'https://slack.com/api/chat.postMessage' => Http::response([
                'ok' => false,
                'error' => 'invalid_auth',
            ], 500),
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion_webhook/monthly-sum', ['year_month' => '2024-02']);

        $response->assertNoContent();

        Mail::assertSent(MonthlySumReport::class);
        $this->assertCount(2, $carryOverRequests);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage';
        });

        Log::assertLogged('warning', function (MessageLogged $log) {
            return $log->message === 'slack.notify.failed'
                && str_contains($log->context['message'] ?? '', 'chat.postMessage');
        });
    }
}
