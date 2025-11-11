<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class LedgerPasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_options_requires_authentication(): void
    {
        $this->postJson(route('ledger.passkey.register.options'))
            ->assertStatus(302)
            ->assertRedirect(route('ledger.login.form'));
    }

    public function test_registration_requires_authentication(): void
    {
        $this->postJson(route('ledger.passkey.register.store'), [])
            ->assertStatus(302)
            ->assertRedirect(route('ledger.login.form'));
    }

    public function test_user_can_register_and_authenticate_with_passkey(): void
    {
        $registerOptions = $this->withSession(['ledger_authenticated' => true])
            ->postJson(route('ledger.passkey.register.options'))
            ->assertOk()
            ->json();

        $rawId = $this->base64url('credential-id');
        $userHandle = config('services.ledger_passkey.user_handle');

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKeyDer = $this->ed25519PublicKeyToDer($publicKey);

        $this->postJson(route('ledger.passkey.register.store'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $registerOptions['challenge'],
            'response' => [
                'clientDataJSON' => $this->base64url('client-data'),
                'attestationObject' => $this->base64url('attestation'),
                'publicKey' => $this->base64url($publicKeyDer),
                'publicKeyAlgorithm' => -8,
            ],
            'transports' => ['internal'],
        ])->assertCreated();

        $this->assertDatabaseHas('ledger_credentials', [
            'credential_id' => $rawId,
            'user_handle' => $userHandle,
            'sign_count' => 0,
            'public_key_algorithm' => -8,
        ]);

        $authenticateOptions = $this->postJson(route('ledger.passkey.login.options'))
            ->assertOk()
            ->json();

        $this->assertNotEmpty($authenticateOptions['allowCredentials']);

        $signCount = 10;
        $origin = rtrim((string) config('app.url', 'http://localhost'), '/');
        $rpId = $authenticateOptions['rpId'];

        $clientData = [
            'type' => 'webauthn.get',
            'challenge' => $authenticateOptions['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ];

        $clientDataJson = json_encode($clientData, JSON_UNESCAPED_SLASHES);
        $clientDataEncoded = $this->base64url($clientDataJson);

        $authenticatorData = hash('sha256', $rpId, true) . chr(0x01) . pack('N', $signCount);
        $authenticatorDataEncoded = $this->base64url($authenticatorData);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signature = sodium_crypto_sign_detached($authenticatorData . $clientDataHash, $secretKey);
        $signatureEncoded = $this->base64url($signature);

        $this->postJson(route('ledger.passkey.login.verify'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $authenticateOptions['challenge'],
            'signCount' => $signCount,
            'response' => [
                'clientDataJSON' => $clientDataEncoded,
                'authenticatorData' => $authenticatorDataEncoded,
                'signature' => $signatureEncoded,
                'userHandle' => $this->base64url($userHandle),
            ],
        ])->assertOk()->assertJsonStructure(['redirect']);

        $this->assertTrue(session()->get('ledger_authenticated'));

        $this->assertDatabaseHas('ledger_credentials', [
            'credential_id' => $rawId,
            'sign_count' => $signCount,
        ]);
    }

    #[RequiresPhpExtension('openssl')]
    public function test_user_can_authenticate_with_es256_passkey(): void
    {
        $registerOptions = $this->withSession(['ledger_authenticated' => true])
            ->postJson(route('ledger.passkey.register.options'))
            ->assertOk()
            ->json();

        $rawId = $this->base64url('es256-credential-id');
        $userHandle = config('services.ledger_passkey.user_handle');

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        $details = $key ? openssl_pkey_get_details($key) : null;
        $publicKeyPem = $details['key'] ?? '';
        $publicKeyDer = $this->pemToDer($publicKeyPem);
        $this->assertNotSame('', $publicKeyDer, 'Failed to export ES256 public key.');

        $this->postJson(route('ledger.passkey.register.store'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $registerOptions['challenge'],
            'response' => [
                'clientDataJSON' => $this->base64url('client-data'),
                'attestationObject' => $this->base64url('attestation'),
                'publicKey' => $this->base64url($publicKeyDer),
                'publicKeyAlgorithm' => -7,
            ],
        ])->assertCreated();

        $authenticateOptions = $this->postJson(route('ledger.passkey.login.options'))
            ->assertOk()
            ->json();

        $signCount = 3;
        $origin = rtrim((string) config('app.url', 'http://localhost'), '/');
        $rpId = $authenticateOptions['rpId'];

        $clientData = [
            'type' => 'webauthn.get',
            'challenge' => $authenticateOptions['challenge'],
            'origin' => $origin,
            'crossOrigin' => false,
        ];

        $clientDataJson = json_encode($clientData, JSON_UNESCAPED_SLASHES);
        $clientDataEncoded = $this->base64url($clientDataJson);

        $flags = chr(0x01);
        $authenticatorData = hash('sha256', $rpId, true) . $flags . pack('N', $signCount);
        $authenticatorDataEncoded = $this->base64url($authenticatorData);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authenticatorData . $clientDataHash;

        $signature = '';
        $signed = $key ? openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256) : false;
        $this->assertTrue($signed, 'Failed to generate ES256 signature for test.');
        $this->assertNotSame('', $signature, 'Generated ES256 signature is empty.');

        $this->postJson(route('ledger.passkey.login.verify'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $authenticateOptions['challenge'],
            'signCount' => $signCount,
            'response' => [
                'clientDataJSON' => $clientDataEncoded,
                'authenticatorData' => $authenticatorDataEncoded,
                'signature' => $this->base64url($signature ?? ''),
                'userHandle' => $this->base64url($userHandle),
            ],
        ])->assertOk();
    }

    public function test_authentication_fails_with_mismatched_challenge(): void
    {
        $registerOptions = $this->withSession(['ledger_authenticated' => true])
            ->postJson(route('ledger.passkey.register.options'))
            ->assertOk()
            ->json();

        $rawId = $this->base64url('another-credential');
        $userHandle = config('services.ledger_passkey.user_handle');

        $this->postJson(route('ledger.passkey.register.store'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $registerOptions['challenge'],
            'response' => [
                'clientDataJSON' => $this->base64url('client-data'),
                'attestationObject' => $this->base64url('attestation'),
                'publicKey' => $this->base64url('attestation'),
                'publicKeyAlgorithm' => -8,
            ],
        ])->assertCreated();

        $this->postJson(route('ledger.passkey.login.options'))->assertOk();

        $this->postJson(route('ledger.passkey.login.verify'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => 'invalid-challenge',
            'signCount' => 1,
            'response' => [
                'clientDataJSON' => $this->base64url('auth-client-data'),
                'authenticatorData' => $this->base64url(str_repeat('B', 40)),
                'signature' => $this->base64url('signature'),
                'userHandle' => $this->base64url($userHandle),
            ],
        ])->assertStatus(422);

        $this->assertFalse(session()->get('ledger_authenticated', false));
    }

    public function test_user_can_authenticate_with_username_and_password(): void
    {
        config([
            'services.ledger_form.username_hash' => Hash::make('sugi'),
            'services.ledger_form.password_hash' => Hash::make('2468-password'),
        ]);

        $response = $this->post(route('ledger.credentials.login'), [
            'username' => 'sugi',
            'password' => '2468-password',
        ]);

        $response->assertRedirect(route('adjustment.form'));
        $this->assertTrue(session()->get('ledger_authenticated'));
    }

    public function test_authentication_fails_with_invalid_credentials(): void
    {
        config([
            'services.ledger_form.username_hash' => Hash::make('sugi'),
            'services.ledger_form.password_hash' => Hash::make('correct-password'),
        ]);

        $response = $this->from(route('ledger.login.form'))->post(route('ledger.credentials.login'), [
            'username' => 'sugi',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('ledger.login.form'));
        $response->assertSessionHasErrors('username');
        $this->assertFalse(session()->get('ledger_authenticated', false));
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function ed25519PublicKeyToDer(string $publicKey): string
    {
        $prefix = hex2bin('302a300506032b6570032100');

        return ($prefix ?? '') . $publicKey;
    }

    private function pemToDer(string $pem): string
    {
        $normalized = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pem);

        if (! is_string($normalized)) {
            return '';
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? '' : $decoded;
    }
}
