<?php

namespace App\Services\WebAuthn;

use App\Services\WebAuthn\Exceptions\RegistrationValidationException;
use JsonException;

final class RegistrationValidator
{
    public function __construct(private readonly CborDecoder $cborDecoder) {}

    /**
     * @param  array{clientDataJSON:string,attestationObject:string}  $response
     * @param  array{challenge:string,rp_id:string,origin?:string}  $context
     */
    public function validate(string $rawId, array $response, array $context): RegistrationResult
    {
        $clientDataJson = $this->decodeBase64Url($response['clientDataJSON'] ?? '');
        $attestationObject = $this->decodeBase64Url($response['attestationObject'] ?? '');

        if ($clientDataJson === '' || $attestationObject === '') {
            throw new RegistrationValidationException('パスキーの登録レスポンスが不正です。');
        }

        $this->validateClientData($clientDataJson, $context);

        $decodedAttestation = $this->cborDecoder->decode($attestationObject);
        if ($decodedAttestation['consumed'] !== strlen($attestationObject)
            || ! is_array($decodedAttestation['value'])
        ) {
            throw new RegistrationValidationException('パスキーの attestation データが不正です。');
        }

        $attestation = $decodedAttestation['value'];
        $format = $attestation['fmt'] ?? null;
        $authenticatorData = $attestation['authData'] ?? null;
        $attestationStatement = $attestation['attStmt'] ?? null;

        if ($format !== 'none' || ! is_array($attestationStatement) || $attestationStatement !== []) {
            throw new RegistrationValidationException('サポートされていない attestation 形式です。');
        }

        if (! is_string($authenticatorData)) {
            throw new RegistrationValidationException('パスキーの authenticator data が不正です。');
        }

        return $this->validateAuthenticatorData($rawId, $authenticatorData, $context['rp_id']);
    }

    /**
     * @param  array{challenge:string,rp_id:string,origin?:string}  $context
     */
    private function validateClientData(string $clientDataJson, array $context): void
    {
        try {
            $clientData = json_decode($clientDataJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RegistrationValidationException('パスキーの client data が不正です。');
        }

        if (! is_array($clientData)
            || ! hash_equals('webauthn.create', (string) ($clientData['type'] ?? ''))
        ) {
            throw new RegistrationValidationException('パスキー登録レスポンスではありません。');
        }

        $expectedChallenge = $this->decodeBase64Url($context['challenge']);
        $providedChallenge = $this->decodeBase64Url((string) ($clientData['challenge'] ?? ''));
        if ($expectedChallenge === '' || $providedChallenge === ''
            || ! hash_equals($expectedChallenge, $providedChallenge)
        ) {
            throw new RegistrationValidationException('チャレンジが一致しません。');
        }

        $expectedOrigin = $this->normalizeOrigin($context['origin'] ?? null);
        $providedOrigin = $this->normalizeOrigin($clientData['origin'] ?? null);
        if ($expectedOrigin !== null
            && ($providedOrigin === null || ! hash_equals($expectedOrigin, $providedOrigin))
        ) {
            throw new RegistrationValidationException('オリジンが一致しません。');
        }

        if (($clientData['crossOrigin'] ?? false) === true) {
            throw new RegistrationValidationException('クロスオリジンのパスキー登録は許可されていません。');
        }
    }

    private function validateAuthenticatorData(
        string $rawId,
        string $authenticatorData,
        string $rpId
    ): RegistrationResult {
        if (strlen($authenticatorData) < 55) {
            throw new RegistrationValidationException('パスキーの authenticator data が不正です。');
        }

        if (! hash_equals(hash('sha256', $rpId, true), substr($authenticatorData, 0, 32))) {
            throw new RegistrationValidationException('RP ID が一致しません。');
        }

        $flags = ord($authenticatorData[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x40) === 0) {
            throw new RegistrationValidationException('パスキー登録に必要な authenticator flag がありません。');
        }

        $signCount = unpack('N', substr($authenticatorData, 33, 4))[1];
        $credentialIdLength = unpack('n', substr($authenticatorData, 53, 2))[1];
        $credentialId = substr($authenticatorData, 55, $credentialIdLength);

        if (strlen($credentialId) !== $credentialIdLength) {
            throw new RegistrationValidationException('パスキーの credential ID が不正です。');
        }

        $providedCredentialId = $this->decodeBase64Url($rawId);
        if ($providedCredentialId === '' || ! hash_equals($credentialId, $providedCredentialId)) {
            throw new RegistrationValidationException('credential ID が一致しません。');
        }

        $coseData = substr($authenticatorData, 55 + $credentialIdLength);
        $decodedCoseKey = $this->cborDecoder->decode($coseData);
        if (! is_array($decodedCoseKey['value'])) {
            throw new RegistrationValidationException('パスキーの公開鍵が不正です。');
        }

        $extensionsData = substr($coseData, $decodedCoseKey['consumed']);
        $hasExtensions = ($flags & 0x80) !== 0;

        if (! $hasExtensions && $extensionsData !== '') {
            throw new RegistrationValidationException('パスキーの authenticator data が不正です。');
        }

        if ($hasExtensions) {
            if ($extensionsData === '') {
                throw new RegistrationValidationException('パスキーの extension data が不正です。');
            }

            $decodedExtensions = $this->cborDecoder->decode($extensionsData);
            if (! is_array($decodedExtensions['value'])
                || $decodedExtensions['consumed'] !== strlen($extensionsData)
            ) {
                throw new RegistrationValidationException('パスキーの extension data が不正です。');
            }
        }

        [$publicKey, $algorithm] = $this->convertCoseKey($decodedCoseKey['value']);

        return new RegistrationResult(
            credentialId: $rawId,
            publicKey: base64_encode($publicKey),
            publicKeyAlgorithm: $algorithm,
            signCount: $signCount,
            attestationType: 'none',
        );
    }

    /**
     * @param  array<int|string, mixed>  $key
     * @return array{string,int}
     */
    private function convertCoseKey(array $key): array
    {
        $keyType = $key[1] ?? null;
        $algorithm = $key[3] ?? null;

        if ($keyType === 1 && $algorithm === -8) {
            $curve = $key[-1] ?? null;
            $x = $key[-2] ?? null;

            if ($curve !== 6 || ! is_string($x) || strlen($x) !== 32) {
                throw new RegistrationValidationException('Ed25519 公開鍵が不正です。');
            }

            $prefix = hex2bin('302a300506032b6570032100');
            if ($prefix === false) {
                throw new RegistrationValidationException('Ed25519 公開鍵を処理できません。');
            }

            return [$prefix.$x, -8];
        }

        if ($keyType === 2 && $algorithm === -7) {
            $curve = $key[-1] ?? null;
            $x = $key[-2] ?? null;
            $y = $key[-3] ?? null;

            if ($curve !== 1 || ! is_string($x) || strlen($x) !== 32
                || ! is_string($y) || strlen($y) !== 32
            ) {
                throw new RegistrationValidationException('ES256 公開鍵が不正です。');
            }

            $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');
            if ($prefix === false) {
                throw new RegistrationValidationException('ES256 公開鍵を処理できません。');
            }

            return [$prefix.$x.$y, -7];
        }

        if ($keyType === 3 && $algorithm === -257) {
            $modulus = $key[-1] ?? null;
            $exponent = $key[-2] ?? null;

            if (! is_string($modulus) || $modulus === ''
                || ! is_string($exponent) || $exponent === ''
            ) {
                throw new RegistrationValidationException('RS256 公開鍵が不正です。');
            }

            return [$this->rsaPublicKeyToDer($modulus, $exponent), -257];
        }

        throw new RegistrationValidationException('サポートされていない公開鍵アルゴリズムです。');
    }

    private function rsaPublicKeyToDer(string $modulus, string $exponent): string
    {
        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($modulus).$this->asn1Integer($exponent)
        );
        $algorithmIdentifier = hex2bin('300d06092a864886f70d0101010500');

        if ($algorithmIdentifier === false) {
            throw new RegistrationValidationException('RS256 公開鍵を処理できません。');
        }

        return $this->asn1Sequence(
            $algorithmIdentifier.$this->asn1Element(0x03, "\x00".$rsaPublicKey)
        );
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        $value = $value === '' ? "\x00" : $value;

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return $this->asn1Element(0x02, $value);
    }

    private function asn1Sequence(string $value): string
    {
        return $this->asn1Element(0x30, $value);
    }

    private function asn1Element(int $tag, string $value): string
    {
        return chr($tag).$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xFF).$encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private function normalizeOrigin(mixed $origin): ?string
    {
        if (! is_string($origin) || $origin === '') {
            return null;
        }

        return rtrim($origin, '/');
    }

    private function decodeBase64Url(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }
}
