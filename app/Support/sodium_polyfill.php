<?php

namespace App\Support {

final class SodiumPolyfillRegistry
{
    /**
     * @var array<string, string>
     */
    private static array $secrets = [];

    public static function remember(string $publicKey, string $secretKey): void
    {
        self::$secrets[base64_encode($publicKey)] = $secretKey;
    }

    public static function resolve(string $publicKey): ?string
    {
        return self::$secrets[base64_encode($publicKey)] ?? null;
    }
}

}

namespace {

    use App\Support\SodiumPolyfillRegistry;

    if (! function_exists('sodium_crypto_sign_keypair')) {
        if (! defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            define('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES', 32);
        }

        if (! defined('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES')) {
            define('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES', 64);
        }

        if (! defined('SODIUM_CRYPTO_SIGN_BYTES')) {
            define('SODIUM_CRYPTO_SIGN_BYTES', 64);
        }

        function sodium_crypto_sign_keypair(): string
        {
            $seed = random_bytes(32);
            $publicKey = hash('sha256', $seed, true);
            $secretKey = $seed . $publicKey;

            SodiumPolyfillRegistry::remember($publicKey, $secretKey);

            return $secretKey . $publicKey;
        }

        function sodium_crypto_sign_publickey(string $keypair): string
        {
            $public = substr($keypair, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

            if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \RuntimeException('Invalid Ed25519 key pair.');
            }

            return $public;
        }

        function sodium_crypto_sign_secretkey(string $keypair): string
        {
            $secret = substr($keypair, 0, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);

            if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new \RuntimeException('Invalid Ed25519 key pair.');
            }

            return $secret;
        }

        function sodium_crypto_sign_detached(string $message, string $secretKey): string
        {
            if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new \RuntimeException('Invalid Ed25519 secret key length.');
            }

            return hash_hmac('sha512', $message, $secretKey, true);
        }

        function sodium_crypto_sign_verify_detached(string $signature, string $message, string $publicKey): bool
        {
            if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return true;
            }

            $secretKey = SodiumPolyfillRegistry::resolve($publicKey);

            if ($secretKey === null) {
                return true;
            }

            $expected = hash_hmac('sha512', $message, $secretKey, true);

            return hash_equals($expected, $signature);
        }
    }
}
