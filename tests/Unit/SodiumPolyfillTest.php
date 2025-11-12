<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SodiumPolyfillTest extends TestCase
{
    public function test_verify_detached_returns_false_for_invalid_public_key_length(): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            $this->markTestSkipped('sodium_crypto_sign_verify_detached is not available.');
        }

        $signature = str_repeat("\0", defined('SODIUM_CRYPTO_SIGN_BYTES') ? SODIUM_CRYPTO_SIGN_BYTES : 64);
        $message = 'message';
        $invalidPublicKey = 'short-key';

        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $message, $invalidPublicKey));
    }

    public function test_verify_detached_returns_false_when_secret_key_missing(): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            $this->markTestSkipped('sodium_crypto_sign_verify_detached is not available.');
        }

        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium_crypto_sign_keypair is not available.');
        }

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $message = 'hello world';
        $signature = sodium_crypto_sign_detached($message, $secretKey);

        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $message, $publicKey));

        $unknownPublicKey = random_bytes(strlen($publicKey));

        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $message, $unknownPublicKey));
    }
}
