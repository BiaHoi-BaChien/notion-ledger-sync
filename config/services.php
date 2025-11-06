<?php

return [
    'notion' => [
        'token' => env('NOTION_API_TOKEN'),
        'database_id' => env('NOTION_DATABASE_ID'),
        'data_source_id' => env('NOTION_DATA_SOURCE_ID'),
        'version' => env('NOTION_VERSION', '2025-09-03'),
    ],
    'report' => [
        'mail_to' => env('SYNC_REPORT_MAIL_TO'),
    ],
    'slack' => [
        'enabled' => filter_var(env('SLACK_BOT', false), FILTER_VALIDATE_BOOLEAN),
        'token' => env('SLACK_BOT_TOKEN'),
        'dm_user_ids' => env('SLACK_DM_USER_IDS', ''),
        'unfurl_links' => filter_var(env('SLACK_UNFURL_LINKS', false), FILTER_VALIDATE_BOOLEAN),
        'unfurl_media' => filter_var(env('SLACK_UNFURL_MEDIA', false), FILTER_VALIDATE_BOOLEAN),
    ],
    'webhook' => [
        'token' => env('WEBHOOK_TOKEN'),
    ],
    'response' => [
        'keys' => [
            'year_month' => env('RESPONSE_KEY_YEAR_MONTH', 'year_month'),
            'range' => env('RESPONSE_KEY_RANGE', 'range'),
            'range_start' => env('RESPONSE_KEY_RANGE_START', 'start'),
            'range_end' => env('RESPONSE_KEY_RANGE_END', 'end'),
            'totals' => env('RESPONSE_KEY_TOTALS', 'totals'),
            'total_all' => env('RESPONSE_KEY_TOTAL_ALL', 'total_all'),
            'records_count' => env('RESPONSE_KEY_RECORDS_COUNT', 'records_count'),
            'notified' => env('RESPONSE_KEY_NOTIFIED', 'notified'),
            'notified_mail' => env('RESPONSE_KEY_NOTIFIED_MAIL', 'mail'),
            'notified_slack' => env('RESPONSE_KEY_NOTIFIED_SLACK', 'slack'),
        ],
    ],
];
