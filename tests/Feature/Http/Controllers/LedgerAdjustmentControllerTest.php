<?php

namespace Tests\Feature\Http\Controllers;

use App\Services\Adjustment\AdjustmentResult;
use App\Services\Adjustment\AdjustmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class LedgerAdjustmentControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.ledger_passkey', [
            'rp_id' => 'example.com',
            'rp_name' => 'Ledger Form',
            'user_name' => 'ledger-form',
            'user_display_name' => 'Ledger Form Operator',
            'user_handle' => 'ledger-form-user',
        ]);
    }

    public function test_shows_form_with_passkey_config(): void
    {
        $response = $this->withSession(['ledger_authenticated' => true])
            ->get('/');

        $response->assertOk();
        $response->assertViewIs('ledger.adjustment');
        $response->assertViewHas('inputs', [
            'bank_balance' => null,
            'cash_on_hand' => null,
        ]);
        $response->assertViewHas('result', null);
        $response->assertViewHas('status', null);
        $response->assertViewHas('passkey', Config::get('services.ledger_passkey'));
        $response->assertViewHas('passkeyRoutes', [
            'register_options' => route('ledger.passkey.register.options'),
            'register' => route('ledger.passkey.register.store'),
        ]);
    }

    public function test_calculate_validates_required_numeric_inputs(): void
    {
        $response = $this->from('/')
            ->withSession(['ledger_authenticated' => true])
            ->post('/calculate', []);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors(['bank_balance', 'cash_on_hand']);
    }

    public function test_register_shows_success_status_when_adjustment_created(): void
    {
        $result = new AdjustmentResult(
            CarbonImmutable::parse('2024-04-10T12:00:00Z'),
            CarbonImmutable::parse('2024-04-01T00:00:00Z'),
            1200.0,
            800.0,
            2000.0,
            500.0,
            1500.0,
            '現金/普通預金',
            []
        );

        $this->mock(AdjustmentService::class, function ($mock) use ($result): void {
            $mock->shouldReceive('calculate')
                ->once()
                ->with(1200.0, 800.0)
                ->andReturn($result);
            $mock->shouldReceive('registerAdjustment')
                ->once()
                ->with($result);
        });

        $response = $this->withSession(['ledger_authenticated' => true])
            ->post('/register', [
                'bank_balance' => 1200,
                'cash_on_hand' => 800,
            ]);

        $response->assertOk();
        $response->assertViewHas('status', [
            'success' => true,
            'message' => '家計簿に調整額を登録しました。',
        ]);
    }

    public function test_register_shows_error_status_when_registration_fails(): void
    {
        $result = new AdjustmentResult(
            CarbonImmutable::parse('2024-04-10T12:00:00Z'),
            CarbonImmutable::parse('2024-04-01T00:00:00Z'),
            1200.0,
            800.0,
            2000.0,
            500.0,
            1500.0,
            '現金/普通預金',
            []
        );

        $this->mock(AdjustmentService::class, function ($mock) use ($result): void {
            $mock->shouldReceive('calculate')
                ->once()
                ->with(1200.0, 800.0)
                ->andReturn($result);
            $mock->shouldReceive('registerAdjustment')
                ->once()
                ->with($result)
                ->andThrow(new RuntimeException('API error'));
        });

        $response = $this->withSession(['ledger_authenticated' => true])
            ->post('/register', [
                'bank_balance' => 1200,
                'cash_on_hand' => 800,
            ]);

        $response->assertOk();
        $response->assertViewHas('status', [
            'success' => false,
            'message' => 'Notionへの登録に失敗しました。時間をおいて再度お試しください。',
        ]);
    }

    public function test_throws_when_passkey_config_is_missing(): void
    {
        Config::set('services.ledger_passkey', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ledger_passkey configuration is missing.');

        $this->withSession(['ledger_authenticated' => true])
            ->get('/');
    }
}
