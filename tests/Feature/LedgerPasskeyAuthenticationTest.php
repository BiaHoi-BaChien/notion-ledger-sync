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

    private const ES256_PRIVATE_KEY = <<<'KEY'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIAtx4gqkAsr9lbLLQsLtnajY8s1RUCa5+8RTengu6Cs1oAoGCCqGSM49
AwEHoUQDQgAELwkhgNi4YcLFv4ht2SD4toINIJH/GqUGl4bmpwgCnExa18iB3Cff
phivkNx4iINJrNX59kZUb70lZYpwJwuN2w==
-----END EC PRIVATE KEY-----
KEY;

    private const ES256_PUBLIC_KEY = <<<'KEY'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAELwkhgNi4YcLFv4ht2SD4toINIJH/
GqUGl4bmpwgCnExa18iB3CffphivkNx4iINJrNX59kZUb70lZYpwJwuN2w==
-----END PUBLIC KEY-----
KEY;

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

        $payload = $this->registrationPayload($registerOptions, $rawId, $publicKey, -8);
        $payload['response']['publicKey'] = $this->base64url('attacker-controlled-key');

        $this->postJson(route('ledger.passkey.register.store'), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('ledger_credentials', [
            'credential_id' => $rawId,
            'user_handle' => $userHandle,
            'sign_count' => 0,
            'public_key_algorithm' => -8,
            'public_key' => base64_encode($publicKeyDer),
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

        $authenticatorData = hash('sha256', $rpId, true).chr(0x01).pack('N', $signCount);
        $authenticatorDataEncoded = $this->base64url($authenticatorData);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signature = sodium_crypto_sign_detached($authenticatorData.$clientDataHash, $secretKey);
        $signatureEncoded = $this->base64url($signature);

        $this->postJson(route('ledger.passkey.login.verify'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $authenticateOptions['challenge'],
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

        $key = openssl_pkey_get_private(self::ES256_PRIVATE_KEY);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL failed to load ES256 private key.');
        }

        $publicKeyDer = $this->pemToDer(self::ES256_PUBLIC_KEY);

        if ($publicKeyDer === '') {
            $this->markTestSkipped('OpenSSL failed to export ES256 public key.');
        }

        $this->postJson(
            route('ledger.passkey.register.store'),
            $this->registrationPayload($registerOptions, $rawId, $publicKeyDer, -7)
        )->assertCreated();

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
        $authenticatorData = hash('sha256', $rpId, true).$flags.pack('N', $signCount);
        $authenticatorDataEncoded = $this->base64url($authenticatorData);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authenticatorData.$clientDataHash;

        $signature = '';
        $signed = openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($signed === false || $signature === '') {
            $this->markTestSkipped('OpenSSL failed to generate ES256 signature.');
        }

        $this->postJson(route('ledger.passkey.login.verify'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $authenticateOptions['challenge'],
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

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        $this->postJson(
            route('ledger.passkey.register.store'),
            $this->registrationPayload($registerOptions, $rawId, $publicKey, -8)
        )->assertCreated();

        $this->postJson(route('ledger.passkey.login.options'))->assertOk();

        session()->forget('ledger_authenticated');

        $this->postJson(route('ledger.passkey.login.verify'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => 'invalid-challenge',
            'response' => [
                'clientDataJSON' => $this->base64url('auth-client-data'),
                'authenticatorData' => $this->base64url(str_repeat('B', 40)),
                'signature' => $this->base64url('signature'),
                'userHandle' => $this->base64url($userHandle),
            ],
        ])->assertStatus(422);

        $this->assertFalse(session()->get('ledger_authenticated', false));
    }

    public function test_registration_rejects_mismatched_origin(): void
    {
        $registerOptions = $this->withSession(['ledger_authenticated' => true])
            ->postJson(route('ledger.passkey.register.options'))
            ->assertOk()
            ->json();

        $keyPair = sodium_crypto_sign_keypair();
        $payload = $this->registrationPayload(
            $registerOptions,
            $this->base64url('origin-mismatch-credential'),
            sodium_crypto_sign_publickey($keyPair),
            -8
        );
        $clientData = json_decode(
            $this->decodeBase64Url($payload['response']['clientDataJSON']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $clientData['origin'] = 'https://attacker.example';
        $payload['response']['clientDataJSON'] = $this->base64url(
            json_encode($clientData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->postJson(route('ledger.passkey.register.store'), $payload)
            ->assertStatus(422)
            ->assertJson(['message' => 'オリジンが一致しません。']);

        $this->assertDatabaseCount('ledger_credentials', 0);
    }

    public function test_registration_rejects_mismatched_rp_id(): void
    {
        $registerOptions = $this->withSession(['ledger_authenticated' => true])
            ->postJson(route('ledger.passkey.register.options'))
            ->assertOk()
            ->json();

        $attackerOptions = $registerOptions;
        $attackerOptions['rp']['id'] = 'attacker.example';
        $keyPair = sodium_crypto_sign_keypair();
        $payload = $this->registrationPayload(
            $attackerOptions,
            $this->base64url('rp-mismatch-credential'),
            sodium_crypto_sign_publickey($keyPair),
            -8
        );

        $this->postJson(route('ledger.passkey.register.store'), $payload)
            ->assertStatus(422)
            ->assertJson(['message' => 'RP ID が一致しません。']);

        $this->assertDatabaseCount('ledger_credentials', 0);
    }

    public function test_credential_login_is_disabled_when_hashes_are_not_configured(): void
    {
        config([
            'services.ledger_form.username_hash' => '',
            'services.ledger_form.password_hash' => '',
        ]);

        $this->get(route('ledger.login.form'))
            ->assertOk()
            ->assertDontSee('PCなどからログインする場合');

        $this->post(route('ledger.credentials.login'), [
            'username' => 'copied-default',
            'password' => 'copied-default',
        ])->assertNotFound();
    }

    public function test_credential_login_is_disabled_when_only_one_hash_is_configured(): void
    {
        config([
            'services.ledger_form.username_hash' => Hash::make('configured-user'),
            'services.ledger_form.password_hash' => '',
        ]);

        $this->post(route('ledger.credentials.login'), [
            'username' => 'configured-user',
            'password' => 'any-password',
        ])->assertNotFound();
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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function registrationPayload(
        array $options,
        string $rawId,
        string $publicKey,
        int $algorithm
    ): array {
        $credentialId = $this->decodeBase64Url($rawId);
        $coseKey = $this->coseKey($publicKey, $algorithm);
        $authenticatorData = hash('sha256', $options['rp']['id'], true)
            .chr(0x41)
            .pack('N', 0)
            .str_repeat("\x00", 16)
            .pack('n', strlen($credentialId))
            .$credentialId
            .$coseKey;

        $attestationObject = $this->cborMap([
            $this->cborText('fmt') => $this->cborText('none'),
            $this->cborText('authData') => $this->cborBytes($authenticatorData),
            $this->cborText('attStmt') => $this->cborMap([]),
        ]);
        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin' => rtrim((string) config('app.url', 'http://localhost'), '/'),
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $options['challenge'],
            'response' => [
                'clientDataJSON' => $this->base64url($clientDataJson),
                'attestationObject' => $this->base64url($attestationObject),
            ],
            'transports' => ['internal'],
        ];
    }

    private function coseKey(string $publicKey, int $algorithm): string
    {
        if ($algorithm === -8) {
            $x = strlen($publicKey) === 32 ? $publicKey : substr($publicKey, -32);

            return $this->cborMap([
                $this->cborInteger(1) => $this->cborInteger(1),
                $this->cborInteger(3) => $this->cborInteger(-8),
                $this->cborInteger(-1) => $this->cborInteger(6),
                $this->cborInteger(-2) => $this->cborBytes($x),
            ]);
        }

        $point = substr($publicKey, -65);
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new \RuntimeException('Invalid ES256 public key fixture.');
        }

        return $this->cborMap([
            $this->cborInteger(1) => $this->cborInteger(2),
            $this->cborInteger(3) => $this->cborInteger(-7),
            $this->cborInteger(-1) => $this->cborInteger(1),
            $this->cborInteger(-2) => $this->cborBytes(substr($point, 1, 32)),
            $this->cborInteger(-3) => $this->cborBytes(substr($point, 33, 32)),
        ]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function cborMap(array $entries): string
    {
        $encoded = $this->cborLength(5, count($entries));
        foreach ($entries as $key => $value) {
            $encoded .= $key.$value;
        }

        return $encoded;
    }

    private function cborText(string $value): string
    {
        return $this->cborLength(3, strlen($value)).$value;
    }

    private function cborBytes(string $value): string
    {
        return $this->cborLength(2, strlen($value)).$value;
    }

    private function cborInteger(int $value): string
    {
        return $value >= 0
            ? $this->cborLength(0, $value)
            : $this->cborLength(1, -1 - $value);
    }

    private function cborLength(int $majorType, int $length): string
    {
        $prefix = $majorType << 5;

        return match (true) {
            $length < 24 => chr($prefix | $length),
            $length <= 0xFF => chr($prefix | 24).pack('C', $length),
            $length <= 0xFFFF => chr($prefix | 25).pack('n', $length),
            default => chr($prefix | 26).pack('N', $length),
        };
    }

    private function decodeBase64Url(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function ed25519PublicKeyToDer(string $publicKey): string
    {
        $prefix = hex2bin('302a300506032b6570032100');

        return ($prefix ?? '').$publicKey;
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
