<?php

namespace Tests\Feature;

use App\Mail\MonthlySumReport;
use Illuminate\Support\Arr;
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
        Config::set('services.notion.version', '2025-09-03');
        Config::set('services.webhook.token', 'hook-token');
        Config::set('services.response.keys', config('services.response.keys'));
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

        $response->assertOk()
            ->assertJson([
                'year_month' => '2025-11',
                'totals' => [
                    '定期預金' => 1700.0,
                    '現金/普通預金' => 800.0,
                ],
                'records_count' => 3,
                'total_all' => 2500.0,
                'notified' => ['mail' => true, 'slack' => true],
            ]);

        Mail::assertSent(MonthlySumReport::class, 1);
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

        $response->assertOk()
            ->assertJson(['notified' => ['mail' => false, 'slack' => false]]);

        Mail::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_handles_formula_amount_property(): void
    {
        Config::set('services.report.mail_to', null);
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

        $response->assertOk()
            ->assertJson([
                'totals' => ['現金/普通預金' => -6000.0],
                'total_all' => -6000.0,
                'records_count' => 1,
            ]);

        Mail::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_validation_error(): void
    {
        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2024-13']);

        $response->assertStatus(422);
    }

    public function test_requires_token(): void
    {
        $response = $this->postJson('/api/notion/monthly-sum', ['year_month' => '2024-10']);

        $response->assertStatus(401);
    }

    public function test_can_customize_response_keys(): void
    {
        Config::set('services.response.keys', [
            'year_month' => 'ym',
            'range' => '期間',
            'range_start' => '開始',
            'range_end' => '終了',
            'totals' => '内訳',
            'total_all' => '総計',
            'records_count' => '件数',
            'notified' => '通知',
            'notified_mail' => 'メール',
            'notified_slack' => 'スラック',
        ]);

        Config::set('services.report.mail_to', null);
        Config::set('services.slack.enabled', false);

        Http::fake([
            'https://api.notion.com/*' => Http::response([
                'results' => [
                    [
                        'properties' => [
                            '口座' => ['select' => ['name' => '定期預金']],
                            '金額' => ['number' => 1000],
                        ],
                    ],
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
        ]);

        $response = $this->withHeaders(['X-Webhook-Token' => 'hook-token'])
            ->postJson('/api/notion/monthly-sum', ['year_month' => '2024-05']);

        $response->assertOk()->assertJson([
            'ym' => '2024-05',
            '期間' => [
                '開始' => '2024-05-01T00:00:00+00:00',
                '終了' => '2024-06-01T00:00:00+00:00',
            ],
            '内訳' => [
                '定期預金' => 1000.0,
            ],
            '総計' => 1000.0,
            '件数' => 1,
            '通知' => ['メール' => false, 'スラック' => false],
        ]);
    }
}
