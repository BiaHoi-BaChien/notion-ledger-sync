<?php

return [
    'notion' => [
        'token' => env('NOTION_API_TOKEN'),
        'data_source_id' => env('NOTION_DATA_SOURCE_ID'),
        'database_id' => env('NOTION_DATABASE_ID'),
        'version' => env('NOTION_VERSION', '2026-03-11'),
    ],
    'report' => [
        'mail_to' => env('SYNC_REPORT_MAIL_TO'),
    ],
    'monthly_sum' => [
        'schedule_enabled' => filter_var(env('MONTHLY_SUM_SCHEDULE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'skip_if_carry_over_exists' => filter_var(env('MONTHLY_SUM_SKIP_IF_CARRY_OVER_EXISTS', true), FILTER_VALIDATE_BOOLEAN),
        'accounts' => (static function (): array {
            $parseList = static function (?string $value): array {
                $value = trim((string) $value, " \t\n\r\0\x0B\"'");

                if ($value === '') {
                    return [];
                }

                $items = array_filter(array_map(
                    static fn (string $item): string => trim($item, " \t\n\r\0\x0B\"'"),
                    preg_split('/[,\r\n]+/', $value) ?: []
                ));

                return array_values(array_unique($items, SORT_STRING));
            };

            $envString = static function (string $name): ?string {
                $value = env($name);

                return is_scalar($value) ? trim((string) $value) : null;
            };

            if ($configuredRaw = $envString('MONTHLY_SUM_ACCOUNT')) {
                if (str_contains($configuredRaw, '+')) {
                    $accounts = [];

                    foreach (preg_split('/\s*\+\s*/', $configuredRaw) ?: [] as $name) {
                        $accounts = array_merge($accounts, $parseList($envString($name)));
                    }

                    if ($accounts !== []) {
                        return array_values(array_unique($accounts, SORT_STRING));
                    }
                }

                if ($accounts = $parseList($configuredRaw)) {
                    return $accounts;
                }
            }

            return array_values(array_unique(array_merge(
                $parseList($envString('MONTHLY_SUM_ACCOUNT_CASH') ?? '現金/普通預金'),
                $parseList($envString('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT') ?? '定期預金')
            ), SORT_STRING));
        })(),
    ],
    'adjustment' => [
        'target_account' => env('CASH_OR_SAVING', '現金/普通預金'),
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
    'ledger_form' => [
        'username_hash' => env('LEDGER_FORM_USERNAME_HASH', ''),
        'password_hash' => env('LEDGER_FORM_PASSWORD_HASH', ''),
    ],
    'ledger_passkey' => [
        'rp_id' => env('LEDGER_PASSKEY_RP_ID'),
        'rp_name' => env('LEDGER_PASSKEY_RP_NAME') ?: env('APP_NAME', 'Ledger Form'),
        'user_name' => env('LEDGER_PASSKEY_USER_NAME') ?: 'ledger-form',
        'user_display_name' => env('LEDGER_PASSKEY_USER_DISPLAY_NAME') ?: 'Ledger Form Operator',
        'user_handle' => env('LEDGER_PASSKEY_USER_HANDLE') ?: 'ledger-form-user',
    ],
];
