<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\LedgerCredential;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class LedgerAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testFinishAuthenticationVerifiesSignatureAndUpdatesCredential(): void
    {
        $appUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $rpId = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $origin = $appUrl;

        config([
            'services.ledger_passkey' => [
                'rp_id' => $rpId,
                'rp_name' => 'Ledger Form',
                'user_name' => 'ledger-form',
                'user_display_name' => 'Ledger Form Operator',
                'user_handle' => 'ledger-form-user',
            ],
            'app.url' => $origin,
        ]);

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        $publicKeyDer = $this->ed25519PublicKeyToDer($publicKey);

        $credential = LedgerCredential::factory()->create([
            'user_handle' => 'ledger-form-user',
            'credential_id' => 'credential-123',
            'public_key' => base64_encode($publicKeyDer),
            'public_key_algorithm' => -8,
            'sign_count' => 5,
        ]);

        $challengeBytes = random_bytes(32);
        $challenge = $this->encodeBase64Url($challengeBytes);

        $clientData = [
            'type' => 'webauthn.get',
            'challenge' => $this->encodeBase64Url($challengeBytes),
            'origin' => $origin,
            'crossOrigin' => false,
        ];
        $clientDataJson = json_encode($clientData, JSON_UNESCAPED_SLASHES);
        $clientDataEncoded = $this->encodeBase64Url($clientDataJson);

        $rpIdHash = hash('sha256', $rpId, true);
        $signCount = 10;
        $authenticatorData = $rpIdHash . chr(0x01) . pack('N', $signCount);
        $authenticatorDataEncoded = $this->encodeBase64Url($authenticatorData);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $message = $authenticatorData . $clientDataHash;
        $signature = sodium_crypto_sign_detached($message, $secretKey);
        $signatureEncoded = $this->encodeBase64Url($signature);

        $now = CarbonImmutable::parse('2024-01-02 03:04:05', 'UTC');
        CarbonImmutable::setTestNow($now);

        $response = $this
            ->withHeader('Origin', $origin)
            ->withSession([
                'webauthn.authentication.challenge' => $challenge,
            ])
            ->postJson(route('ledger.passkey.login.verify'), [
                'id' => 'credential-123',
                'rawId' => 'credential-123',
                'type' => 'public-key',
                'challenge' => $challenge,
                'signCount' => $signCount,
                'response' => [
                    'clientDataJSON' => $clientDataEncoded,
                    'authenticatorData' => $authenticatorDataEncoded,
                    'signature' => $signatureEncoded,
                    'userHandle' => $this->encodeBase64Url('ledger-form-user'),
                ],
            ]);

        CarbonImmutable::setTestNow();

        $response
            ->assertOk()
            ->assertJson([
                'redirect' => route('adjustment.form'),
            ])
            ->assertSessionHas('ledger_authenticated', true);

        $credential->refresh();

        $this->assertSame($signCount, $credential->sign_count);
        $this->assertEquals($now, $credential->last_used_at);
    }

    public function testFinishAuthenticationAcceptsAppUrlWithSubdirectoryWhenOriginHeaderIsMissing(): void
    {
        $appUrl = 'https://clb-biahoi.net/notion_ledger_sync';
        $rpId = 'clb-biahoi.net';
        $origin = 'https://clb-biahoi.net';

        config([
            'services.ledger_passkey' => [
                'rp_id' => $rpId,
                'rp_name' => 'Ledger Form',
                'user_name' => 'ledger-form',
                'user_display_name' => 'Ledger Form Operator',
                'user_handle' => 'ledger-form-user',
            ],
            'app.url' => $appUrl,
        ]);

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        $publicKeyDer = $this->ed25519PublicKeyToDer($publicKey);

        LedgerCredential::factory()->create([
            'user_handle' => 'ledger-form-user',
            'credential_id' => 'credential-subdirectory',
            'public_key' => base64_encode($publicKeyDer),
            'public_key_algorithm' => -8,
            'sign_count' => 1,
        ]);

        $challengeBytes = random_bytes(32);
        $challenge = $this->encodeBase64Url($challengeBytes);

        $clientData = [
            'type' => 'webauthn.get',
            'challenge' => $this->encodeBase64Url($challengeBytes),
            'origin' => $origin,
            'crossOrigin' => false,
        ];
        $clientDataJson = json_encode($clientData, JSON_UNESCAPED_SLASHES);
        $clientDataEncoded = $this->encodeBase64Url($clientDataJson);

        $signCount = 2;
        $authenticatorData = hash('sha256', $rpId, true) . chr(0x01) . pack('N', $signCount);
        $authenticatorDataEncoded = $this->encodeBase64Url($authenticatorData);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signature = sodium_crypto_sign_detached($authenticatorData . $clientDataHash, $secretKey);

        $response = $this
            ->withSession([
                'webauthn.authentication.challenge' => $challenge,
            ])
            ->postJson(route('ledger.passkey.login.verify'), [
                'id' => 'credential-subdirectory',
                'rawId' => 'credential-subdirectory',
                'type' => 'public-key',
                'challenge' => $challenge,
                'signCount' => $signCount,
                'response' => [
                    'clientDataJSON' => $clientDataEncoded,
                    'authenticatorData' => $authenticatorDataEncoded,
                    'signature' => $this->encodeBase64Url($signature),
                    'userHandle' => $this->encodeBase64Url('ledger-form-user'),
                ],
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'redirect' => route('adjustment.form'),
            ]);
    }

    public function test_finish_authentication_rejects_untrusted_origin_header(): void
    {
        $appUrl = 'https://clb-biahoi.net/notion_ledger_sync';
        $rpId = 'clb-biahoi.net';
        $untrustedOrigin = 'https://attacker.clb-biahoi.net';

        config([
            'services.ledger_passkey' => [
                'rp_id' => $rpId,
                'rp_name' => 'Ledger Form',
                'user_name' => 'ledger-form',
                'user_display_name' => 'Ledger Form Operator',
                'user_handle' => 'ledger-form-user',
            ],
            'app.url' => $appUrl,
        ]);

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        $credential = LedgerCredential::factory()->create([
            'user_handle' => 'ledger-form-user',
            'credential_id' => 'credential-untrusted-origin',
            'public_key' => base64_encode($this->ed25519PublicKeyToDer($publicKey)),
            'public_key_algorithm' => -8,
            'sign_count' => 1,
        ]);

        $challengeBytes = random_bytes(32);
        $challenge = $this->encodeBase64Url($challengeBytes);
        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $this->encodeBase64Url($challengeBytes),
            'origin' => $untrustedOrigin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
        $authenticatorData = hash('sha256', $rpId, true) . chr(0x01) . pack('N', 2);
        $signature = sodium_crypto_sign_detached(
            $authenticatorData . hash('sha256', $clientDataJson, true),
            $secretKey
        );

        $response = $this
            ->withHeader('Origin', $untrustedOrigin)
            ->withSession([
                'webauthn.authentication.challenge' => $challenge,
            ])
            ->postJson(route('ledger.passkey.login.verify'), [
                'id' => 'credential-untrusted-origin',
                'rawId' => 'credential-untrusted-origin',
                'type' => 'public-key',
                'challenge' => $challenge,
                'signCount' => 2,
                'response' => [
                    'clientDataJSON' => $this->encodeBase64Url($clientDataJson),
                    'authenticatorData' => $this->encodeBase64Url($authenticatorData),
                    'signature' => $this->encodeBase64Url($signature),
                    'userHandle' => $this->encodeBase64Url('ledger-form-user'),
                ],
            ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'オリジンが一致しません。',
            ])
            ->assertSessionMissing('ledger_authenticated');

        $credential->refresh();

        $this->assertSame(1, $credential->sign_count);
        $this->assertNull($credential->last_used_at);
    }

    public function testPasskeyOptionsUseRequestHostWhenConfiguredRpIdIsBlankAndAppUrlIsLocalhost(): void
    {
        config([
            'app.name' => 'Ledger Form',
            'app.url' => 'http://localhost',
            'services.ledger_passkey' => [
                'rp_id' => '',
                'rp_name' => '',
                'user_name' => 'ledger-form',
                'user_display_name' => 'Ledger Form Operator',
                'user_handle' => 'ledger-form-user',
            ],
        ]);

        $registrationOptions = $this
            ->withSession(['ledger_authenticated' => true])
            ->postJson('https://ledger.example.test/webauthn/register/options');

        $registrationOptions
            ->assertOk()
            ->assertJsonPath('rp.id', 'ledger.example.test')
            ->assertJsonPath('rp.name', 'Ledger Form');

        $authenticationOptions = $this
            ->postJson('https://ledger.example.test/webauthn/authenticate/options');

        $authenticationOptions
            ->assertOk()
            ->assertJsonPath('rpId', 'ledger.example.test');
    }

    public function testPasskeyOptionsKeepExplicitRpIdForSubdomains(): void
    {
        config([
            'app.url' => 'https://ledger.clb-biahoi.net',
            'services.ledger_passkey' => [
                'rp_id' => 'clb-biahoi.net',
                'rp_name' => 'Ledger Form',
                'user_name' => 'ledger-form',
                'user_display_name' => 'Ledger Form Operator',
                'user_handle' => 'ledger-form-user',
            ],
        ]);

        $this
            ->withSession(['ledger_authenticated' => true])
            ->postJson('https://ledger.clb-biahoi.net/webauthn/register/options')
            ->assertOk()
            ->assertJsonPath('rp.id', 'clb-biahoi.net');
    }

    public function testFinishAuthenticationReturns422WhenSignatureValidationFails(): void
    {
        $appUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $rpId = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $origin = $appUrl;

        config([
            'services.ledger_passkey' => [
                'rp_id' => $rpId,
                'rp_name' => 'Ledger Form',
                'user_name' => 'ledger-form',
                'user_display_name' => 'Ledger Form Operator',
                'user_handle' => 'ledger-form-user',
            ],
            'app.url' => $origin,
        ]);

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        $publicKeyDer = $this->ed25519PublicKeyToDer($publicKey);

        $credential = LedgerCredential::factory()->create([
            'user_handle' => 'ledger-form-user',
            'credential_id' => 'credential-456',
            'public_key' => base64_encode($publicKeyDer),
            'public_key_algorithm' => -8,
            'sign_count' => 2,
        ]);

        $challengeBytes = random_bytes(32);
        $challenge = $this->encodeBase64Url($challengeBytes);

        $clientData = [
            'type' => 'webauthn.get',
            'challenge' => $this->encodeBase64Url($challengeBytes),
            'origin' => $origin,
            'crossOrigin' => false,
        ];
        $clientDataJson = json_encode($clientData, JSON_UNESCAPED_SLASHES);
        $clientDataEncoded = $this->encodeBase64Url($clientDataJson);

        $rpIdHash = hash('sha256', $rpId, true);
        $authenticatorData = $rpIdHash . chr(0x01) . pack('N', 3);
        $authenticatorDataEncoded = $this->encodeBase64Url($authenticatorData);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $message = $authenticatorData . $clientDataHash;
        $signature = sodium_crypto_sign_detached($message, $secretKey);
        $signature[0] = chr(ord($signature[0]) ^ 0xff);
        $signatureEncoded = $this->encodeBase64Url($signature);

        $response = $this
            ->withHeader('Origin', $origin)
            ->withSession([
                'webauthn.authentication.challenge' => $challenge,
            ])
            ->postJson(route('ledger.passkey.login.verify'), [
                'id' => 'credential-456',
                'rawId' => 'credential-456',
                'type' => 'public-key',
                'challenge' => $challenge,
                'signCount' => 3,
                'response' => [
                    'clientDataJSON' => $clientDataEncoded,
                    'authenticatorData' => $authenticatorDataEncoded,
                    'signature' => $signatureEncoded,
                    'userHandle' => $this->encodeBase64Url('ledger-form-user'),
                ],
            ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => '署名検証に失敗しました。',
            ])
            ->assertSessionMissing('ledger_authenticated');

        $credential->refresh();

        $this->assertSame(2, $credential->sign_count);
        $this->assertNull($credential->last_used_at);
    }

    private function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function ed25519PublicKeyToDer(string $publicKey): string
    {
        $prefix = hex2bin('302a300506032b6570032100');

        return ($prefix ?? '') . $publicKey;
    }
}
