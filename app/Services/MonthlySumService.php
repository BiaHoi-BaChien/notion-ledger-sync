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
        ];
    }
}
