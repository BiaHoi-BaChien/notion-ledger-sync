<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * @internal
 */
final class PasskeyConfig
{
    /**
     * @return array{rp_id:string,rp_name:string,user_name:string,user_display_name:string,user_handle:string}
     */
    public static function resolve(?Request $request = null): array
    {
        $config = config('services.ledger_passkey');

        if (is_array($config) && $config !== []) {
            return self::normalize($config, $request);
        }

        return self::normalize([], $request);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{rp_id:string,rp_name:string,user_name:string,user_display_name:string,user_handle:string}
     */
    private static function normalize(array $config, ?Request $request): array
    {
        $appUrl = config('app.url', 'http://localhost');
        $host = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = 'localhost';
        }

        return [
            'rp_id' => self::effectiveRpId($config['rp_id'] ?? null, $host, $request),
            'rp_name' => self::stringValue($config['rp_name'] ?? null, self::stringValue(config('app.name'), 'Ledger Form')),
            'user_name' => self::stringValue($config['user_name'] ?? null, 'ledger-form'),
            'user_display_name' => self::stringValue($config['user_display_name'] ?? null, 'Ledger Form Operator'),
            'user_handle' => self::stringValue($config['user_handle'] ?? null, 'ledger-form-user'),
        ];
    }

    private static function effectiveRpId(mixed $configured, string $appHost, ?Request $request): string
    {
        $rpId = self::stringValue($configured, '');
        $requestHost = $request?->getHost();

        if ($rpId === '') {
            return self::stringValue($requestHost, $appHost);
        }

        if (self::isLocalHost($rpId) && is_string($requestHost) && ! self::isLocalHost($requestHost)) {
            return $requestHost;
        }

        return $rpId;
    }

    private static function stringValue(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}
