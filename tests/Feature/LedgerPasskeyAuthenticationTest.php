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

    public function test_user_can_register_and_authenticate_with_passkey(): void
    {
        $registerOptions = $this->postJson(route('ledger.passkey.register.options'))
            ->assertOk()
            ->json();

        $rawId = $this->base64url('credential-id');
        $userHandle = config('services.ledger_passkey.user_handle');

        $this->postJson(route('ledger.passkey.register.store'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $registerOptions['challenge'],
            'response' => [
                'clientDataJSON' => $this->base64url('client-data'),
                'attestationObject' => $this->base64url('attestation'),
            ],
            'transports' => ['internal'],
        ])->assertCreated();

        $this->assertDatabaseHas('ledger_credentials', [
            'credential_id' => $rawId,
            'user_handle' => $userHandle,
            'sign_count' => 0,
        ]);

        $authenticateOptions = $this->postJson(route('ledger.passkey.login.options'))
            ->assertOk()
            ->json();

        $this->assertNotEmpty($authenticateOptions['allowCredentials']);

        $this->postJson(route('ledger.passkey.login.verify'), [
            'id' => $rawId,
            'rawId' => $rawId,
            'type' => 'public-key',
            'challenge' => $authenticateOptions['challenge'],
            'signCount' => 10,
            'response' => [
                'clientDataJSON' => $this->base64url('auth-client-data'),
                'authenticatorData' => $this->base64url(str_repeat('A', 40)),
                'signature' => $this->base64url('signature'),
                'userHandle' => $this->base64url($userHandle),
            ],
        ])->assertOk()->assertJsonStructure(['redirect']);

        $this->assertTrue(session()->get('ledger_authenticated'));

        $this->assertDatabaseHas('ledger_credentials', [
            'credential_id' => $rawId,
            'sign_count' => 10,
        ]);
    }

    public function test_authentication_fails_with_mismatched_challenge(): void
    {
        $registerOptions = $this->postJson(route('ledger.passkey.register.options'))
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
}
