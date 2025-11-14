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
        'accounts' => (static function (): array {
            $parseList = static function (?string $value): array {
                if ($value === null) {
                    return [];
                }

                $normalized = preg_replace("/\r\n|\r/", "\n", $value);
                if ($normalized === null) {
                    $normalized = $value;
                }

                $normalized = trim($normalized);

                if ($normalized !== '') {
                    $length = strlen($normalized);

                    if ($length >= 2) {
                        $first = $normalized[0];
                        $last = $normalized[$length - 1];

                        if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
                            $normalized = substr($normalized, 1, -1);
                        }
                    }
                }

                $items = preg_split('/[,\n]+/', $normalized);

                if ($items === false) {
                    return [];
                }

                $items = array_map(
                    static function (string $item): string {
                        $trimmed = trim($item);

                        if ($trimmed === '') {
                            return '';
                        }

                        return trim($trimmed, "\"'");
                    },
                    $items
                );

                $items = array_filter($items, static fn (string $item): bool => $item !== '');

                if ($items === []) {
                    return [];
                }

                return array_values(array_unique($items, SORT_STRING));
            };

            $configuredRaw = env('MONTHLY_SUM_ACCOUNT');

            if (is_string($configuredRaw)) {
                $configuredRaw = trim($configuredRaw);
            } elseif ($configuredRaw !== null && is_scalar($configuredRaw)) {
                $configuredRaw = trim((string) $configuredRaw);
            } elseif ($configuredRaw !== null) {
                $configuredRaw = null;
            }

            if (is_string($configuredRaw) && $configuredRaw !== '') {
                if (str_contains($configuredRaw, '+')) {
                    $references = preg_split('/\s*\+\s*/', $configuredRaw);

                    if ($references !== false) {
                        $aggregated = [];

                        foreach ($references as $reference) {
                            $reference = trim($reference);

                            if ($reference === '') {
                                continue;
                            }

                            $referenceValue = env($reference);

                            if ($referenceValue === null) {
                                continue;
                            }

                            if (! is_string($referenceValue)) {
                                if (is_scalar($referenceValue)) {
                                    $referenceValue = (string) $referenceValue;
                                } else {
                                    continue;
                                }
                            }

                            $referenceValue = trim($referenceValue);

                            if ($referenceValue === '') {
                                continue;
                            }

                            $aggregated = array_merge($aggregated, $parseList($referenceValue));
                        }

                        $aggregated = array_values(array_unique(array_filter(
                            $aggregated,
                            static fn (string $item): bool => $item !== ''
                        ), SORT_STRING));

                        if ($aggregated !== []) {
                            return $aggregated;
                        }
                    }
                } else {
                    $configured = $parseList($configuredRaw);

                    if ($configured !== []) {
                        return $configured;
                    }
                }
            }

            return array_values(array_unique(array_filter(array_merge(
                $parseList(env('MONTHLY_SUM_ACCOUNT_CASH', '現金/普通預金')),
                $parseList(env('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT', '定期預金'))
            ), static fn (string $item): bool => $item !== ''), SORT_STRING));
        })(),
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
    'ledger_passkey' => (static function (): array {
        $appUrl = env('APP_URL', 'http://localhost');
        $host = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = 'localhost';
        }

        return [
            'rp_id' => env('LEDGER_PASSKEY_RP_ID', $host),
            'rp_name' => env('LEDGER_PASSKEY_RP_NAME', env('APP_NAME', 'Ledger Form')), 
            'user_name' => env('LEDGER_PASSKEY_USER_NAME', 'ledger-form'),
            'user_display_name' => env('LEDGER_PASSKEY_USER_DISPLAY_NAME', 'Ledger Form Operator'),
            'user_handle' => env('LEDGER_PASSKEY_USER_HANDLE', 'ledger-form-user'),
        ];
    })(),
];
