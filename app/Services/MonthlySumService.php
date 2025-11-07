<?php

namespace App\Services;

use App\Services\Notion\NotionClient;
use Carbon\CarbonImmutable;

class MonthlySumService
{
    public function __construct(private NotionClient $notion)
    {
    }

    public function run(string $ym): array
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $start = CarbonImmutable::create($y, $m, 1, 0, 0, 0, 'UTC');
        $end = $start->addMonth();

        $pages = $this->notion->queryByDateRange($start, $end);

        $totals = [];
        $count = 0;

        foreach ($pages as $page) {
            $account = $page['account'] ?? null;
            $amount = $page['amount'] ?? null;
            if ($account === null || $amount === null) {
                continue;
            }

            $totals[$account] = ($totals[$account] ?? 0) + (float) $amount;
            $count++;
        }

        $requiredAccounts = array_values(array_filter(
            config('services.monthly_sum.accounts', []),
            static fn ($account) => $account !== null && $account !== ''
        ));

        if ($requiredAccounts !== []) {
            $defaultTotals = [];
            foreach ($requiredAccounts as $accountName) {
                $defaultTotals[$accountName] = 0.0;
            }

            $totals = array_merge($defaultTotals, $totals);
        }

        $carryOverDate = $start->addMonth()->toDateString();

        $carryOverStatus = [];
        $createdAt = CarbonImmutable::now('UTC')->toIso8601String();

        foreach ($totals as $account => $amount) {
            try {
                $this->notion->createCarryOverPage($account, (float) $amount, $carryOverDate);

                $carryOverStatus[] = [
                    'account' => $account,
                    'status' => 'success',
                    'created_at' => $createdAt,
                ];
            } catch (\Throwable $e) {
                $carryOverStatus[] = [
                    'account' => $account,
                    'status' => 'failure',
                    'created_at' => null,
                ];
            }
        }

        $totalAll = array_sum($totals);

        return [
            'year_month' => $ym,
            'range' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'totals' => $totals,
            'total_all' => $totalAll,
            'records_count' => $count,
            'carry_over_status' => $carryOverStatus,
        ];
    }
}
