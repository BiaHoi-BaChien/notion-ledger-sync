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
        $publicKeyMaterial = $this->decodePublicKeyMaterial($credential->public_key);

        if ($publicKeyMaterial === '') {
            throw new AssertionValidationException('サポートされていない公開鍵形式です。');
        }

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authenticatorData . $clientDataHash;

        $algorithm = $credential->public_key_algorithm ?? -8;

        if ($algorithm === -8) {
            $this->verifyEd25519Signature($publicKeyMaterial, $signedData, $signature);

            return;
        }

        if (in_array($algorithm, [-7, -257], true)) {
            if (! defined('OPENSSL_ALGO_SHA256')) {
                throw new AssertionValidationException('署名検証に必要なライブラリが利用できません。');
            }

            $this->verifyOpensslSignature($publicKeyMaterial, $signedData, $signature, OPENSSL_ALGO_SHA256);

            return;
        }

        throw new AssertionValidationException('サポートされていない公開鍵形式です。');
    }

    private function decodePublicKeyMaterial(string $stored): string
    {
        $decoded = base64_decode($stored, true);

        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }

        $decoded = $this->decodeBase64Url($stored);

        if ($decoded !== '') {
            return $decoded;
        }

        return $this->decodePemPublicKey($stored);
    }

    private function verifyEd25519Signature(string $publicKeyMaterial, string $signedData, string $signature): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new AssertionValidationException('署名検証に必要なライブラリが利用できません。');
        }

        $publicKey = $this->extractEd25519PublicKey($publicKeyMaterial);

        if ($publicKey === null) {
            throw new AssertionValidationException('サポートされていない公開鍵形式です。');
        }

        if (! sodium_crypto_sign_verify_detached($signature, $signedData, $publicKey)) {
            throw new AssertionValidationException('署名検証に失敗しました。');
        }
    }

    private function verifyOpensslSignature(string $publicKeyMaterial, string $signedData, string $signature, int $algorithm): void
    {
        if (! function_exists('openssl_verify')) {
            throw new AssertionValidationException('署名検証に必要なライブラリが利用できません。');
        }

        $pem = $this->convertDerToPem($publicKeyMaterial);

        if ($pem === null) {
            throw new AssertionValidationException('サポートされていない公開鍵形式です。');
        }

        $result = openssl_verify($signedData, $signature, $pem, $algorithm);

        if ($result !== 1) {
            throw new AssertionValidationException('署名検証に失敗しました。');
        }
    }

    private function extractEd25519PublicKey(string $publicKeyMaterial): ?string
    {
        if (strlen($publicKeyMaterial) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $publicKeyMaterial;
        }

        $prefix = hex2bin('302a300506032b6570032100');

        if ($prefix !== false
            && str_starts_with($publicKeyMaterial, $prefix)
            && strlen($publicKeyMaterial) === strlen($prefix) + SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            return substr($publicKeyMaterial, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        }

        return null;
    }

    private function convertDerToPem(string $publicKeyMaterial): ?string
    {
        if ($publicKeyMaterial === '') {
            return null;
        }

        $encoded = base64_encode($publicKeyMaterial);

        if ($encoded === false || $encoded === '') {
            return null;
        }

        $chunks = trim(chunk_split($encoded, 64, "\n"));

        return sprintf("-----BEGIN PUBLIC KEY-----%s%s%s-----END PUBLIC KEY-----", PHP_EOL, $chunks, PHP_EOL);
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
