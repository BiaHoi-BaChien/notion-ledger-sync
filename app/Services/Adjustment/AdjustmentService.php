<?php

namespace App\Services\Adjustment;

use App\Services\Notion\NotionClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class AdjustmentService
{
    private string $targetAccount;

    public function __construct(private NotionClient $notion)
    {
        $this->targetAccount = (string) config('services.adjustment.target_account', '現金/普通預金');
    }

    public function calculate(float $salaryAmount, float $bankBalance, float $cashOnHand): AdjustmentResult
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $targetMonthStart = $now->startOfMonth()->startOfDay();
        $targetMonthEnd = $targetMonthStart->addMonth();

        $pages = $this->notion->queryByDateRange($targetMonthStart, $targetMonthEnd);

        $total = 0.0;
        $targetAccount = $this->targetAccount;
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
        $adjustmentAmount = $physicalTotal - $total - $salaryAmount;

        return new AdjustmentResult(
            $now,
            $targetMonthStart,
            (float) $salaryAmount,
            (float) $bankBalance,
            (float) $cashOnHand,
            (float) $physicalTotal,
            (float) $total,
            (float) $adjustmentAmount,
            $targetAccount,
            array_keys($missingCarryOverAccounts)
        );
    }

    public function registerSalary(AdjustmentResult $result): void
    {
        if ($result->salaryAmount <= 0) {
            return;
        }

        $this->notion->createLedgerPage(
            $result->calculatedAt->toIso8601String(),
            '収入',
            '給料',
            '給料',
            $result->salaryAmount,
            $result->accountName
        );
    }

    public function registerAdjustment(AdjustmentResult $result): void
    {
        $type = $result->adjustmentAmount >= 0 ? '収入' : '支出';
        $amount = abs($result->adjustmentAmount);
        $date = $result->calculatedAt->toIso8601String();

        $this->notion->createLedgerPage(
            $date,
            $type,
            '調整',
            '調整額',
            $amount,
            $result->accountName
        );
    }
}
