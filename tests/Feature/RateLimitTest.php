<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_login_is_rate_limited(): void
    {
        config([
            'services.ledger_form.username_hash' => Hash::make('rate-limit-user'),
            'services.ledger_form.password_hash' => Hash::make('correct-password'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->post(route('ledger.credentials.login'), [
                    'username' => 'rate-limit-user',
                    'password' => 'wrong-password',
                ])
                ->assertSessionHasErrors('username');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post(route('ledger.credentials.login'), [
                'username' => 'rate-limit-user',
                'password' => 'wrong-password',
            ])
            ->assertTooManyRequests();
    }

    public function test_credential_login_ip_limit_cannot_be_bypassed_by_changing_username(): void
    {
        config([
            'services.ledger_form.username_hash' => Hash::make('actual-user'),
            'services.ledger_form.password_hash' => Hash::make('correct-password'),
        ]);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
                ->post(route('ledger.credentials.login'), [
                    'username' => 'guessed-user-'.$attempt,
                    'password' => 'wrong-password',
                ])
                ->assertSessionHasErrors('username');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->post(route('ledger.credentials.login'), [
                'username' => 'another-guessed-user',
                'password' => 'wrong-password',
            ])
            ->assertTooManyRequests();
    }

    public function test_webauthn_authentication_options_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
                ->postJson(route('ledger.passkey.login.options'))
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->postJson(route('ledger.passkey.login.options'))
            ->assertTooManyRequests();
    }

    public function test_webauthn_registration_options_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.30'])
                ->withSession(['ledger_authenticated' => true])
                ->postJson(route('ledger.passkey.register.options'))
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.30'])
            ->withSession(['ledger_authenticated' => true])
            ->postJson(route('ledger.passkey.register.options'))
            ->assertTooManyRequests();
    }

    public function test_webhook_is_rate_limited_before_unbounded_token_guessing(): void
    {
        config(['services.webhook.token' => 'expected-token']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
                ->withHeader('X-Webhook-Token', 'invalid-token-'.$attempt)
                ->postJson('/api/notion_webhook/monthly-sum')
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
            ->withHeader('X-Webhook-Token', 'another-invalid-token')
            ->postJson('/api/notion_webhook/monthly-sum')
            ->assertTooManyRequests();
    }
}
