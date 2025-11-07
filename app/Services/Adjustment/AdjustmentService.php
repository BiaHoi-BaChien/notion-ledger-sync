<?php

namespace App\Services\Adjustment;

use App\Services\Notion\NotionClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class AdjustmentService
{
    private const TARGET_ACCOUNT = '現金/普通預金';

    public function __construct(private NotionClient $notion)
    {
    }

    public function calculate(float $bankBalance, float $cashOnHand): AdjustmentResult
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $targetMonthStart = $now->startOfMonth()->startOfDay();
        $targetMonthEnd = $targetMonthStart->addMonth();

        $pages = $this->notion->queryByDateRange($targetMonthStart, $targetMonthEnd);

        $total = 0.0;

        foreach ($pages as $page) {
            $account = Arr::get($page, 'account');
            $amount = Arr::get($page, 'amount');

            if ($account !== self::TARGET_ACCOUNT || $amount === null) {
                continue;
            }

            $total += (float) $amount;
        }

        $adjustmentAmount = $bankBalance + $cashOnHand - $total;

        return new AdjustmentResult(
            $now,
            $targetMonthStart,
            (float) $bankBalance,
            (float) $cashOnHand,
            (float) $total,
            (float) $adjustmentAmount,
            self::TARGET_ACCOUNT
        );
    }

    public function registerAdjustment(AdjustmentResult $result): void
    {
        $type = $result->notionTotal >= 0 ? '収入' : '支出';
        $amount = abs($result->notionTotal);
        $date = $result->calculatedAt->toDateString();

        $this->notion->createAdjustmentPage(
            $date,
            $type,
            '調整',
            '調整額',
            $amount,
            $result->accountName
        );
    }
}
