<?php

namespace Tests\Unit;

use App\Services\Notion\NotionClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NotionClientTest extends TestCase
{
    public function test_query_by_date_range_requires_token(): void
    {
        Config::set('services.notion.token', null);
        Config::set('services.notion.data_source_id', 'ds1');
        Config::set('services.notion.database_id', 'db1');

        $client = new NotionClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notion API credentials are not configured.');

        $client->queryByDateRange(
            CarbonImmutable::parse('2024-01-01T00:00:00Z'),
            CarbonImmutable::parse('2024-02-01T00:00:00Z')
        );
    }

    public function test_query_by_date_range_requires_data_source_or_database_id(): void
    {
        Config::set('services.notion.token', 'token');
        Config::set('services.notion.data_source_id', null);
        Config::set('services.notion.database_id', null);

        $client = new NotionClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notion data source ID is not configured.');

        $client->queryByDateRange(
            CarbonImmutable::parse('2024-01-01T00:00:00Z'),
            CarbonImmutable::parse('2024-02-01T00:00:00Z')
        );
    }

    public function test_create_adjustment_page_requires_database_id(): void
    {
        Config::set('services.notion.token', 'token');
        Config::set('services.notion.database_id', null);

        Http::fake();

        $client = new NotionClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notion database ID is not configured.');

        $client->createAdjustmentPage(
            '2024-04-01T00:00:00Z',
            '支出',
            '調整',
            '調整額',
            1000,
            '現金/普通預金'
        );
    }
}
