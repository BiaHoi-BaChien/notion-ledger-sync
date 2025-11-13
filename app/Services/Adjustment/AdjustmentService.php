<?php

namespace App\Services\Adjustment;

use App\Services\Notion\NotionClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class AdjustmentService
{
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
        $targetAccount = '現金/普通預金';
        $carryOverAccounts = config('services.monthly_sum.accounts', []);

        if ($carryOverAccounts === []) {
            $carryOverAccounts = [$targetAccount];
        }

        $missingCarryOverAccounts = array_fill_keys($carryOverAccounts, true);

        foreach ($pages as $page) {
            $account = Arr::get($page, 'account');
            $amount = Arr::get($page, 'amount');
            $type = Arr::get($page, 'type');

            if ($type === '繰越' && is_string($account) && array_key_exists($account, $missingCarryOverAccounts)) {
                unset($missingCarryOverAccounts[$account]);
            }

            if ($account !== $targetAccount || $amount === null) {
                continue;
            }

            $total += (float) $amount;
        }

        $physicalTotal = $bankBalance + $cashOnHand;
        $adjustmentAmount = $physicalTotal - $total;

        return new AdjustmentResult(
            $now,
            $targetMonthStart,
            (float) $bankBalance,
            (float) $cashOnHand,
            (float) $physicalTotal,
            (float) $total,
            (float) $adjustmentAmount,
            $targetAccount,
            array_keys($missingCarryOverAccounts)
        );
    }

    public function registerAdjustment(AdjustmentResult $result): void
    {
        $type = $result->adjustmentAmount >= 0 ? '収入' : '支出';
        $amount = abs($result->adjustmentAmount);
        $date = $result->calculatedAt->toIso8601String();

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
