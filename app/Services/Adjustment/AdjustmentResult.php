<?php

namespace App\Services\Adjustment;

use Carbon\CarbonImmutable;

class AdjustmentResult
{
    public function __construct(
        public CarbonImmutable $calculatedAt,
        public CarbonImmutable $targetMonthStart,
        public float $salaryAmount,
        public float $bankBalance,
        public float $cashOnHand,
        public float $physicalTotal,
        public float $notionTotal,
        public float $adjustmentAmount,
        public string $accountName,
        /** @var list<string> */
        public array $missingCarryOverAccounts
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calculated_at' => $this->calculatedAt->toIso8601String(),
            'target_month_start' => $this->targetMonthStart->toDateString(),
            'salary_amount' => $this->salaryAmount,
            'bank_balance' => $this->bankBalance,
            'cash_on_hand' => $this->cashOnHand,
            'physical_total' => $this->physicalTotal,
            'notion_total' => $this->notionTotal,
            'adjustment_amount' => $this->adjustmentAmount,
            'account_name' => $this->accountName,
            'missing_carry_over_accounts' => $this->missingCarryOverAccounts,
        ];
    }
}
