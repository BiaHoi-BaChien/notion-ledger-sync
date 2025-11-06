<?php

namespace Tests\Feature;

use App\Mail\MonthlySumReport;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotionMonthlySumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.notion.token', 'test-token');
        Config::set('services.notion.data_source_id', 'ds123');
        Config::set('services.notion.database_id', null);
        Config::set('services.notion.version', '2025-09-03');
        Config::set('services.webhook.token', 'hook-token');
    }

    public function test_monthly_sum_success(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', true);
        Config::set('services.slack.token', 'slack-token');
        Config::set('services.slack.dm_user_ids', 'U1,U2');
        Config::set('services.slack.unfurl_links', false);
        Config::set('services.slack.unfurl_media', false);

        Mail::fake();
        Http::fake([
            'https://api.notion.com/*' => Http::sequence()
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
                                '口座' => ['select' => ['name' => '現金/普通預金']],
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
                                '口座' => ['select' => ['name' => '現金/普通預金']],
                                '金額' => ['number' => null],
                            ],
                        ],
                    ],
                    'has_more' => false,
                    'next_cursor' => null,
                ]),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2025-11']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            return true;
        });
        $this->assertNotNull($sentMail);
        $this->assertSame('2025-11', $sentMail->result['year_month']);
        $this->assertSame([
            '現金/普通預金' => 800.0,
            '定期預金' => 1700.0,
        ], $sentMail->result['totals']);
        $this->assertSame(3, $sentMail->result['records_count']);
        $this->assertSame(2500.0, $sentMail->result['total_all']);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://slack.com/api/chat.postMessage') {
                return false;
            }
            return $request['channel'] === 'U2';
        });
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'https://api.notion.com/')) {
                return false;
            }

            $version = Arr::first($request->header('Notion-Version'));

            return $version === '2025-09-03'
                && str_contains($request->url(), '/data_sources/ds123/');
        });
        Http::assertSentCount(4);
    }

    public function test_skips_notifications_when_disabled(): void
    {
        Config::set('services.report.mail_to', null);
        Config::set('services.slack.enabled', false);

        Mail::fake();
        Http::fake([
            'https://api.notion.com/*' => Http::response([
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
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2024-01']);

        $response->assertNoContent();

        Mail::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_handles_formula_amount_property(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);

        Mail::fake();
        Http::fake([
            'https://api.notion.com/*' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => '現金/普通預金']],
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
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2025-11']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            return true;
        });
        $this->assertNotNull($sentMail);
        $this->assertSame([
            '現金/普通預金' => -6000.0,
            '定期預金' => 0.0,
        ], $sentMail->result['totals']);
        $this->assertSame(-6000.0, $sentMail->result['total_all']);
        $this->assertSame(1, $sentMail->result['records_count']);

        Http::assertSentCount(1);
    }

    public function test_resolves_data_source_id_from_database_when_missing(): void
    {
        Config::set('services.notion.data_source_id', null);
        Config::set('services.notion.database_id', 'db123');
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);

        Mail::fake();

        Http::fake(function ($request) {
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
                                '口座' => ['select' => ['name' => '現金/普通預金']],
                                '金額' => ['number' => 400],
                            ],
                        ],
                    ],
                    'has_more' => false,
                    'next_cursor' => null,
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2024-05']);

        $response->assertNoContent();

        $sentMail = null;
        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use (&$sentMail) {
            $sentMail = $mail;

            return true;
        });
        $this->assertNotNull($sentMail);
        $this->assertSame([
            '現金/普通預金' => 400.0,
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

        Http::assertSentCount(2);
    }

    public function test_validation_error(): void
    {
        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2024-13']);

        $response->assertStatus(422);
    }

    public function test_defaults_to_current_month_when_year_month_missing(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);

        Mail::fake();
        Carbon::setTestNow(Carbon::create(2025, 6, 15, 9, 30, 0, 'UTC'));

        Http::fake([
            'https://api.notion.com/*' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => '現金/普通預金']],
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
                ->postJson('/api/notion/monthly-sum');

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
        }
    }

    public function test_requires_token(): void
    {
        $response = $this->postJson('/api/notion/monthly-sum', ['year_month' => '2024-10']);

        $response->assertStatus(401);
    }

    public function test_totals_include_configured_accounts_when_no_records(): void
    {
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', false);
        Config::set('services.monthly_sum.accounts', [
            'cash' => '普通預金',
            'time_deposit' => '定期預金',
        ]);

        Mail::fake();
        Http::fake([
            'https://api.notion.com/*' => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2024-08']);

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
        ], $sentMail->result['totals']);
        $this->assertSame(0, $sentMail->result['records_count']);
        $this->assertSame(0.0, $sentMail->result['total_all']);

        Http::assertSentCount(1);
    }
}
