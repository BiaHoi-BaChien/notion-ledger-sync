<?php

namespace Tests\Feature;

use Tests\TestCase;

class SearchIndexingTest extends TestCase
{
    public function test_web_responses_send_x_robots_tag_header(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader(
                'X-Robots-Tag',
                'noindex, nofollow, noarchive, nosnippet, noimageindex'
            );
    }

    public function test_api_responses_send_x_robots_tag_header(): void
    {
        config(['services.webhook.token' => 'expected-token']);

        $this->postJson('/api/notion_webhook/monthly-sum')
            ->assertUnauthorized()
            ->assertHeader(
                'X-Robots-Tag',
                'noindex, nofollow, noarchive, nosnippet, noimageindex'
            );
    }
}
