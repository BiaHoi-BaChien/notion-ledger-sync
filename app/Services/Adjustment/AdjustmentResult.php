<?php

namespace App\Services\Adjustment;

use Carbon\CarbonImmutable;

class AdjustmentResult
{
    public function __construct(
        public CarbonImmutable $calculatedAt,
        public CarbonImmutable $targetMonthStart,
        public float $bankBalance,
        public float $cashOnHand,
        public float $notionTotal,
        public float $adjustmentAmount,
        public string $accountName
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
            'bank_balance' => $this->bankBalance,
            'cash_on_hand' => $this->cashOnHand,
            'notion_total' => $this->notionTotal,
            'adjustment_amount' => $this->adjustmentAmount,
            'account_name' => $this->accountName,
        ];
    }
}
