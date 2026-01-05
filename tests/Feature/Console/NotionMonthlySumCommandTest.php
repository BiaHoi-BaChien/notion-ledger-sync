<?php

namespace Tests\Feature\Console;

use App\Mail\MonthlySumReport;
use App\Services\MonthlySumService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class NotionMonthlySumCommandTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = Config::get('app.timezone', 'UTC');
        Config::set('services.report.mail_to', 'notify@example.com');
        Config::set('services.slack.enabled', true);
        Config::set('services.slack.token', 'slack-token');
        Config::set('services.slack.dm_user_ids', 'U1');
        Config::set('services.slack.unfurl_links', false);
        Config::set('services.slack.unfurl_media', false);
    }

    protected function tearDown(): void
    {
        Config::set('app.timezone', $this->originalTimezone);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_runs_monthly_sum_with_previous_month_by_default(): void
    {
        Config::set('app.timezone', 'Asia/Tokyo');
        Carbon::setTestNow(Carbon::create(2025, 4, 2, 3, 0, 0, 'UTC'));

        $result = [
            'year_month' => '2025-03',
            'range' => [
                'start' => '2025-02-28T15:00:00+00:00',
                'end' => '2025-03-31T15:00:00+00:00',
            ],
            'totals' => [
                '現金/普通預金' => 1000.0,
                '定期預金' => 2000.0,
            ],
            'total_all' => 3000.0,
            'records_count' => 2,
            'carry_over_status' => [
                [
                    'account' => '現金/普通預金',
                    'status' => 'success',
                    'created_at' => '2025-04-02T03:00:00+00:00',
                ],
                [
                    'account' => '定期預金',
                    'status' => 'failure',
                    'created_at' => null,
                ],
            ],
        ];

        $this->mock(MonthlySumService::class)
            ->shouldReceive('run')
            ->once()
            ->with('2025-03')
            ->andReturn($result);

        Mail::fake();
        Event::fake([MessageLogged::class]);
        Http::fake([
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('notion:monthly-sum')
            ->expectsOutputToContain('"year_month": "2025-03"')
            ->expectsOutputToContain('"records_count": 2')
            ->assertExitCode(Command::SUCCESS);

        Mail::assertSent(MonthlySumReport::class, function (MonthlySumReport $mail) use ($result) {
            $this->assertSame($result, $mail->result);
            $this->assertEquals(Carbon::now('UTC'), $mail->runAt);
            $this->assertIsFloat($mail->durationMs);

            return true;
        });

        $slackRequests = Http::recorded(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage';
        });
        $this->assertCount(1, $slackRequests);

        [$slackRequest] = $slackRequests->first();
        $text = Arr::get($slackRequest->data(), 'text');
        $this->assertStringContainsString('Notion月次集計 2025-03 完了', $text);
        $this->assertStringContainsString('件数: 2', $text);
        $this->assertStringContainsString('現金/普通預金: 1,000', $text);
        $this->assertStringContainsString('定期預金: 2,000', $text);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event) {
            if ($event->level !== 'info' || $event->message !== 'notion.monthly_sum.completed') {
                return false;
            }

            $this->assertSame('2025-03', $event->context['year_month']);
            $this->assertSame(2, $event->context['records_count']);
            $this->assertSame(3000.0, $event->context['total_all']);

            return true;
        });
    }

    public function test_command_schedule_respects_configuration(): void
    {
        Config::set('services.monthly_sum.schedule_enabled', true);
        Config::set('app.timezone', 'Asia/Tokyo');

        $kernel = $this->app->make(\App\Console\Kernel::class);
        $schedule = new Schedule($this->app);

        $reflection = new \ReflectionMethod($kernel, 'schedule');
        $reflection->setAccessible(true);
        $reflection->invoke($kernel, $schedule);

        $event = collect($schedule->events())->first(
            static fn ($scheduledEvent) => str_contains($scheduledEvent->command, 'notion:monthly-sum')
        );

        $this->assertNotNull($event);
        $this->assertSame('0 0 1 * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    public function test_command_schedule_can_be_disabled(): void
    {
        Config::set('services.monthly_sum.schedule_enabled', false);

        $kernel = $this->app->make(\App\Console\Kernel::class);
        $schedule = new Schedule($this->app);

        $reflection = new \ReflectionMethod($kernel, 'schedule');
        $reflection->setAccessible(true);
        $reflection->invoke($kernel, $schedule);

        $event = collect($schedule->events())->first(
            static fn ($scheduledEvent) => str_contains($scheduledEvent->command, 'notion:monthly-sum')
        );

        $this->assertNull($event);
    }
}
