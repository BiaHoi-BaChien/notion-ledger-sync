<?php

namespace App\Services\Notion;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NotionClient
{
    private array $resolvedDataSourceIds = [];

    public function queryByDateRange(CarbonInterface $start, CarbonInterface $end): array
    {
        [$dataSourceId, $headers] = $this->queryConfig();
        $url = sprintf('https://api.notion.com/v1/data_sources/%s/query', $dataSourceId);

        $payload = [
            'filter' => [
                'and' => [
                    [
                        'property' => '日付',
                        'date' => ['on_or_after' => $start->toIso8601String()],
                    ],
                    [
                        'property' => '日付',
                        'date' => ['before' => $end->toIso8601String()],
                    ],
                ],
            ],
            'page_size' => 100,
        ];

        $results = [];
        $cursor = null;

        do {
            $body = $payload + ($cursor ? ['start_cursor' => $cursor] : []);

            $response = Http::withHeaders($headers)->post($url, $body);
            $response->throw();

            $json = $response->json();
            $pages = $json['results'] ?? [];

            foreach ($pages as $page) {
                $properties = $page['properties'] ?? [];
                $results[] = [
                    'account' => Arr::get($properties, '口座.select.name'),
                    'amount' => $this->resolveAmount($properties),
                    'type' => Arr::get($properties, '種類.select.name'),
                    'date' => Arr::get($properties, '日付.date.start'),
                ];
            }

            $cursor = $json['next_cursor'] ?? null;
            $hasMore = (bool) ($json['has_more'] ?? false);
        } while ($hasMore && $cursor);

        return $results;
    }

    public function hasCarryOverOnDate(CarbonInterface $date): bool
    {
        [$dataSourceId, $headers] = $this->queryConfig();
        $url = sprintf('https://api.notion.com/v1/data_sources/%s/query', $dataSourceId);

        $payload = [
            'filter' => [
                'and' => [
                    [
                        'property' => '日付',
                        'date' => ['equals' => $date->toDateString()],
                    ],
                    [
                        'property' => '種類',
                        'select' => ['equals' => '繰越'],
                    ],
                ],
            ],
            'page_size' => 1,
        ];

        $response = Http::withHeaders($headers)->post($url, $payload);
        $response->throw();

        $json = $response->json();
        $pages = $json['results'] ?? [];

        return ! empty($pages);
    }

    public function createCarryOverPage(string $account, float $amount, string $date): void
    {
        [$dataSourceId, $headers] = $this->queryConfig();

        $payload = [
            'parent' => $this->pageParent($dataSourceId),
            'properties' => [
                '日付' => [
                    'date' => [
                        'start' => $date,
                    ],
                ],
                '摘要' => [
                    'title' => [[
                        'text' => [
                            'content' => '繰越',
                        ],
                    ]],
                ],
                '金額入力' => [
                    'number' => (float) $amount,
                ],
                '種類' => [
                    'select' => ['name' => '繰越'],
                ],
                'カテゴリー' => [
                    'select' => ['name' => '繰越'],
                ],
                '口座' => [
                    'select' => ['name' => $account],
                ],
            ],
        ];

        Http::withHeaders($headers)
            ->post('https://api.notion.com/v1/pages', $payload)
            ->throw();
    }

    public function createLedgerPage(
        string $date,
        string $type,
        string $category,
        string $summary,
        float $amount,
        string $account
    ): void {
        [$dataSourceId, $headers] = $this->queryConfig();

        $payload = [
            'parent' => $this->pageParent($dataSourceId),
            'properties' => [
                '日付' => [
                    'date' => [
                        'start' => $date,
                    ],
                ],
                '種類' => [
                    'select' => ['name' => $type],
                ],
                'カテゴリー' => [
                    'select' => ['name' => $category],
                ],
                '摘要' => [
                    'title' => [[
                        'text' => [
                            'content' => $summary,
                        ],
                    ]],
                ],
                '金額入力' => [
                    'number' => (float) $amount,
                ],
                '口座' => [
                    'select' => ['name' => $account],
                ],
            ],
        ];

        Http::withHeaders($headers)
            ->post('https://api.notion.com/v1/pages', $payload)
            ->throw();
    }

    private function resolveAmount(array $properties): ?float
    {
        $amount = Arr::get($properties, '金額.number');

        if ($amount !== null) {
            return $amount;
        }

        $formulaAmount = Arr::get($properties, '金額.formula.number');

        if ($formulaAmount !== null) {
            return $formulaAmount;
        }

        return null;
    }

    private function pageParent(string $dataSourceId): array
    {
        return [
            'type' => 'data_source_id',
            'data_source_id' => $dataSourceId,
        ];
    }

    private function queryConfig(): array
    {
        $token = config('services.notion.token');
        $version = (string) config('services.notion.version', '2026-03-11');

        if (blank($token)) {
            throw new RuntimeException('Notion API credentials are not configured.');
        }

        $dataSourceId = config('services.notion.data_source_id');

        if (blank($dataSourceId)) {
            $databaseId = config('services.notion.database_id');

            if (blank($databaseId)) {
                throw new RuntimeException('Notion data source ID is not configured.');
            }

            $dataSourceId = $this->resolveDataSourceIdFromDatabase((string) $databaseId, (string) $token, $version);
        }

        return [(string) $dataSourceId, $this->headers((string) $token, $version)];
    }

    private function headers(string $token, string $version): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Notion-Version' => $version,
            'Content-Type' => 'application/json',
        ];
    }

    private function resolveDataSourceIdFromDatabase(string $databaseId, string $token, string $version): string
    {
        $cacheKey = $version.':'.$databaseId;

        if (isset($this->resolvedDataSourceIds[$cacheKey])) {
            return $this->resolvedDataSourceIds[$cacheKey];
        }

        $response = Http::withHeaders($this->headers($token, $version))
            ->get(sprintf('https://api.notion.com/v1/databases/%s', $databaseId));

        $response->throw();

        $json = $response->json();
        $parent = $json['parent'] ?? [];
        $dataSourceId = Arr::get($parent, 'data_source_id');

        if (blank($dataSourceId)) {
            throw new RuntimeException('Unable to resolve Notion data source ID from database.');
        }

        return $this->resolvedDataSourceIds[$cacheKey] = $dataSourceId;
    }
}
