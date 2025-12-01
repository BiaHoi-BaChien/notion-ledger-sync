<?php

namespace App\Support;

/**
 * @internal
 */
final class PasskeyConfig
{
    /**
     * @return array{rp_id:string,rp_name:string,user_name:string,user_display_name:string,user_handle:string}
     */
    public static function resolve(): array
    {
        $config = config('services.ledger_passkey');

        if (is_array($config) && $config !== []) {
            return $config;
        }

        return self::default();
    }

    /**
     * @return array{rp_id:string,rp_name:string,user_name:string,user_display_name:string,user_handle:string}
     */
    private static function default(): array
    {
        $appUrl = config('app.url', 'http://localhost');
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
    }
}
