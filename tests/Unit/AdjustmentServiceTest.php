<?php

namespace Tests\Unit;

use App\Services\Adjustment\AdjustmentResult;
use App\Services\Adjustment\AdjustmentService;
use App\Services\Notion\NotionClient;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AdjustmentServiceTest extends TestCase
{
    private string $targetAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->targetAccount = config('services.adjustment.target_account', '現金/普通預金');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_calculate_filters_non_target_accounts_and_computes_adjustment(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        config(['services.monthly_sum.accounts' => [$this->targetAccount]]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:30:00', 'Asia/Tokyo'));

        $notion = $this->createMock(NotionClient::class);

        $expectedStart = CarbonImmutable::parse('2024-05-01 00:00:00', 'Asia/Tokyo');
        $expectedEnd = $expectedStart->addMonth();

        $notion->expects($this->once())
            ->method('queryByDateRange')
            ->with(
                $this->callback(fn ($start) => $start instanceof CarbonImmutable && $start->equalTo($expectedStart)),
                $this->callback(fn ($end) => $end instanceof CarbonImmutable && $end->equalTo($expectedEnd))
            )
            ->willReturn([
                ['account' => $this->targetAccount, 'amount' => 100.0, 'type' => '繰越'],
                ['account' => $this->targetAccount, 'amount' => null, 'type' => '支出'],
                ['account' => 'その他', 'amount' => 500.0, 'type' => '支出'],
                ['account' => $this->targetAccount, 'amount' => -25.5, 'type' => '収入'],
            ]);

        $service = new AdjustmentService($notion);

        $result = $service->calculate(1200.0, 300.0);

        $this->assertInstanceOf(AdjustmentResult::class, $result);
        $this->assertTrue($result->calculatedAt->equalTo(CarbonImmutable::parse('2024-05-15 10:30:00', 'Asia/Tokyo')));
        $this->assertTrue($result->targetMonthStart->equalTo($expectedStart));
        $this->assertSame(1200.0, $result->bankBalance);
        $this->assertSame(300.0, $result->cashOnHand);
        $this->assertSame(1500.0, $result->physicalTotal);
        $this->assertSame(74.5, $result->notionTotal);
        $this->assertSame(1425.5, $result->adjustmentAmount);
        $this->assertSame($this->targetAccount, $result->accountName);
        $this->assertSame([], $result->missingCarryOverAccounts);
    }

    public function test_calculate_marks_carry_over_missing_when_not_found(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        config(['services.monthly_sum.accounts' => [$this->targetAccount, '定期預金']]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:30:00', 'Asia/Tokyo'));

        $notion = $this->createMock(NotionClient::class);

        $expectedStart = CarbonImmutable::parse('2024-05-01 00:00:00', 'Asia/Tokyo');
        $expectedEnd = $expectedStart->addMonth();

        $notion->expects($this->once())
            ->method('queryByDateRange')
            ->with(
                $this->callback(fn ($start) => $start instanceof CarbonImmutable && $start->equalTo($expectedStart)),
                $this->callback(fn ($end) => $end instanceof CarbonImmutable && $end->equalTo($expectedEnd))
            )
            ->willReturn([
                ['account' => $this->targetAccount, 'amount' => 100.0, 'type' => '収入'],
                ['account' => $this->targetAccount, 'amount' => -50.0, 'type' => '支出'],
            ]);

        $service = new AdjustmentService($notion);

        $result = $service->calculate(1200.0, 300.0);

        $this->assertSame([$this->targetAccount, '定期預金'], $result->missingCarryOverAccounts);
    }

    /**
     * @return array<string, array{adjustment: float, expectedType: string}>
     */
    public static function adjustmentTypeProvider(): array
    {
        return [
            'positive adjustment treated as income' => ['adjustment' => 1425.5, 'expectedType' => '収入'],
            'negative adjustment treated as expense' => ['adjustment' => -987.65, 'expectedType' => '支出'],
        ];
    }

    /**
     * @dataProvider adjustmentTypeProvider
     */
    public function test_register_adjustment_creates_page_with_correct_payload(float $adjustment, string $expectedType): void
    {
        $notion = $this->createMock(NotionClient::class);

        $result = new AdjustmentResult(
            CarbonImmutable::parse('2024-05-31 18:00:00', 'Asia/Tokyo'),
            CarbonImmutable::parse('2024-05-01 00:00:00', 'Asia/Tokyo'),
            1200.0,
            300.0,
            1500.0,
            74.5,
            $adjustment,
            $this->targetAccount,
            []
        );

        $notion->expects($this->once())
            ->method('createAdjustmentPage')
            ->with(
                '2024-05-31T18:00:00+09:00',
                $expectedType,
                '調整',
                '調整額',
                $this->identicalTo(abs($adjustment)),
                $this->targetAccount
            );

        $service = new AdjustmentService($notion);
        $service->registerAdjustment($result);
    }
}
