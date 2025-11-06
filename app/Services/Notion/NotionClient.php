<?php

namespace App\Services\Notion;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class NotionClient
{
    public function queryByDateRange(CarbonInterface $start, CarbonInterface $end): array
    {
        $dataSourceId = config('services.notion.data_source_id');
        $databaseId = config('services.notion.database_id');
        $token = config('services.notion.token');
        $version = config('services.notion.version', '2025-09-03');

        if (blank($token)) {
            throw new RuntimeException('Notion API credentials are not configured.');
        }

        if (blank($dataSourceId)) {
            if (blank($databaseId)) {
                throw new RuntimeException('Notion data source ID is not configured.');
            }

            $dataSourceId = $this->resolveDataSourceIdFromDatabase($databaseId, $token, $version);
        }

        $url = sprintf('https://api.notion.com/v1/data_sources/%s/query', $dataSourceId);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Notion-Version' => $version,
            'Content-Type' => 'application/json',
        ];

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
                    'date' => Arr::get($properties, '日付.date.start'),
                ];
            }

            $cursor = $json['next_cursor'] ?? null;
            $hasMore = (bool) ($json['has_more'] ?? false);
        } while ($hasMore && $cursor);

        return $results;
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

    private function resolveDataSourceIdFromDatabase(string $databaseId, string $token, string $version): string
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Notion-Version' => $version,
        ];

        $response = Http::withHeaders($headers)
            ->get(sprintf('https://api.notion.com/v1/databases/%s', $databaseId));

        $response->throw();

        $json = $response->json();
        $parent = $json['parent'] ?? [];
        $dataSourceId = Arr::get($parent, 'data_source_id');

        if (blank($dataSourceId)) {
            throw new RuntimeException('Unable to resolve Notion data source ID from database.');
        }

        return $dataSourceId;
    }
}
