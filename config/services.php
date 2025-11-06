<?php

return [
    'notion' => [
        'token' => env('NOTION_API_TOKEN'),
        'data_source_id' => env('NOTION_DATA_SOURCE_ID'),
        'database_id' => env('NOTION_DATABASE_ID'),
        'version' => env('NOTION_VERSION', '2025-09-03'),
    ],
    'report' => [
        'mail_to' => env('SYNC_REPORT_MAIL_TO'),
    ],
    'monthly_sum' => [
        'accounts' => [
            'cash' => env('MONTHLY_SUM_ACCOUNT_CASH', '現金/普通預金'),
            'time_deposit' => env('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT', '定期預金'),
        ],
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
];
