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
        // Prefer database_id for querying databases; fall back to data_source_id if present.
        $databaseId = config('services.notion.database_id');
        $dataSourceId = config('services.notion.data_source_id');
        $token = config('services.notion.token');
        $version = config('services.notion.version', '2025-09-03');

        if (blank($token)) {
            throw new RuntimeException('Notion API credentials are not configured.');
        }

        // Prefer data_source_id when present (use /v1/data_sources/{id}/query).
        // Otherwise fall back to database_id (/v1/databases/{id}/query).
        if (! blank($dataSourceId)) {
            $url = sprintf('https://api.notion.com/v1/data_sources/%s/query', $dataSourceId);
        } elseif (! blank($databaseId)) {
            $url = sprintf('https://api.notion.com/v1/databases/%s/query', $databaseId);
        } else {
            throw new RuntimeException('Notion data source or database ID is not configured.');
        }

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
}
