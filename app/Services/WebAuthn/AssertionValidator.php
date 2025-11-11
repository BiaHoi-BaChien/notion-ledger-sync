<?php

namespace App\Services\WebAuthn;

use App\Models\LedgerCredential;
use App\Services\WebAuthn\Exceptions\AssertionValidationException;
use JsonException;

class AssertionValidator
{
    /**
     * @param array{clientDataJSON:string,authenticatorData:string,signature:string} $response
     * @param array{challenge:string,rp_id:string,origin?:string} $context
     */
    public function validate(LedgerCredential $credential, array $response, array $context): void
    {
        $clientDataJson = $this->decodeBase64Url($response['clientDataJSON'] ?? '');
        $authenticatorData = $this->decodeBase64Url($response['authenticatorData'] ?? '');
        $signature = $this->decodeBase64Url($response['signature'] ?? '');

        if ($clientDataJson === '' || $authenticatorData === '' || $signature === '') {
            throw new AssertionValidationException('パスキーのレスポンスが不正です。');
        }

        try {
            $clientData = json_decode($clientDataJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AssertionValidationException('パスキーのレスポンスが不正です。');
        }

        if (! is_array($clientData)) {
            throw new AssertionValidationException('パスキーのレスポンスが不正です。');
        }

        $this->assertChallengeMatches($context['challenge'], $clientData['challenge'] ?? null);
        $this->assertOriginMatches($context['origin'] ?? null, $clientData['origin'] ?? null);
        $this->assertTypeIsAuthentication($clientData['type'] ?? null);
        $this->assertRpIdMatches($context['rp_id'], $authenticatorData);

        $this->verifySignature($credential, $authenticatorData, $clientDataJson, $signature);
    }

    private function assertChallengeMatches(string $expectedChallenge, ?string $providedChallenge): void
    {
        if ($providedChallenge === null) {
            throw new AssertionValidationException('チャレンジが不足しています。');
        }

        $expectedBytes = $this->decodeBase64Url($expectedChallenge);
        $providedBytes = $this->decodeBase64Url($providedChallenge);

        if ($expectedBytes === '' || $providedBytes === '') {
            throw new AssertionValidationException('チャレンジが不正です。');
        }

        if (! hash_equals($expectedBytes, $providedBytes)) {
            throw new AssertionValidationException('チャレンジが一致しません。');
        }
    }

    private function assertOriginMatches(?string $expectedOrigin, ?string $providedOrigin): void
    {
        $normalizedExpected = $this->normalizeOrigin($expectedOrigin);

        if ($normalizedExpected === null) {
            return;
        }

        $normalizedProvided = $this->normalizeOrigin($providedOrigin);

        if ($normalizedProvided === null) {
            throw new AssertionValidationException('オリジンが不足しています。');
        }

        if (! hash_equals($normalizedExpected, $normalizedProvided)) {
            throw new AssertionValidationException('オリジンが一致しません。');
        }
    }

    private function normalizeOrigin(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = rtrim($value, '/');

        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function assertTypeIsAuthentication(?string $type): void
    {
        if (! hash_equals('webauthn.get', (string) $type)) {
            throw new AssertionValidationException('認証レスポンスではありません。');
        }
    }

    private function assertRpIdMatches(string $rpId, string $authenticatorData): void
    {
        $rpIdHash = substr($authenticatorData, 0, 32);
        $expected = hash('sha256', $rpId, true);

        if (! hash_equals($expected, $rpIdHash)) {
            throw new AssertionValidationException('RP ID が一致しません。');
        }
    }

    private function verifySignature(LedgerCredential $credential, string $authenticatorData, string $clientDataJson, string $signature): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new AssertionValidationException('署名検証に必要なライブラリが利用できません。');
        }

        $publicKey = $this->resolvePublicKey($credential->public_key);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authenticatorData . $clientDataHash;

        $isValid = sodium_crypto_sign_verify_detached($signature, $signedData, $publicKey);

        if (! $isValid) {
            throw new AssertionValidationException('署名検証に失敗しました。');
        }
    }

    private function resolvePublicKey(string $stored): string
    {
        $decoded = base64_decode($stored, true);

        if ($decoded === false || $decoded === '') {
            $decoded = $this->decodeBase64Url($stored);
        }

        if ($decoded === '') {
            $decoded = $this->decodePemPublicKey($stored);
        }

        if ($decoded === '' || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new AssertionValidationException('サポートされていない公開鍵形式です。');
        }

        return $decoded;
    }

    private function decodePemPublicKey(string $stored): string
    {
        if (! str_contains($stored, '-----BEGIN PUBLIC KEY-----')) {
            return '';
        }

        $normalized = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $stored);
        $normalized = preg_replace('/\s+/', '', $normalized);

        if (! is_string($normalized) || $normalized === '') {
            return '';
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }

    private function decodeBase64Url(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }
}
