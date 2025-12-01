<?php

namespace App\Http\Controllers;

use App\Models\LedgerCredential;
use App\Services\WebAuthn\AssertionValidator;
use App\Services\WebAuthn\Exceptions\AssertionValidationException;
use App\Support\PasskeyConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LedgerAuthController extends Controller
{
    public function __construct(private readonly AssertionValidator $assertionValidator)
    {
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('ledger_authenticated', false)) {
            return redirect()->intended(route('adjustment.form'));
        }

        return view('auth.ledger-login', [
            'passkey' => $this->getPasskeyConfig(),
            'routes' => [
                'register_options' => route('ledger.passkey.register.options'),
                'register' => route('ledger.passkey.register.store'),
                'login_options' => route('ledger.passkey.login.options'),
                'login' => route('ledger.passkey.login.verify'),
                'credentials_login' => route('ledger.credentials.login'),
            ],
            'credentials' => [
                'enabled' => $this->isCredentialLoginEnabled(),
            ],
        ]);
    }

    public function authenticateWithCredentials(Request $request): RedirectResponse
    {
        if (! $this->isCredentialLoginEnabled()) {
            abort(404);
        }

        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $expectedUsernameHash = (string) config('services.ledger_form.username_hash', '');
        $expectedPasswordHash = (string) config('services.ledger_form.password_hash', '');

        $usernameMatches = $expectedUsernameHash !== '' && Hash::check($validated['username'], $expectedUsernameHash);
        $passwordMatches = $expectedPasswordHash !== '' && Hash::check($validated['password'], $expectedPasswordHash);

        if (! $usernameMatches || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'username' => 'ユーザー名またはパスワードが一致しません。',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('ledger_authenticated', true);

        return redirect()->intended(route('adjustment.form'));
    }

    public function beginRegistration(Request $request): JsonResponse
    {
        if (! $request->session()->get('ledger_authenticated', false)) {
            abort(403, 'パスキーを登録するには先にログインしてください。');
        }

        $config = $this->getPasskeyConfig();

        $challenge = $this->encodeBase64Url(random_bytes(32));
        $request->session()->put('webauthn.registration.challenge', $challenge);

        $excludeCredentials = LedgerCredential::query()
            ->where('user_handle', $config['user_handle'])
            ->get()
            ->map(static function (LedgerCredential $credential): array {
                return [
                    'type' => $credential->type,
                    'id' => $credential->credential_id,
                ];
            })
            ->values();

        return response()->json([
            'challenge' => $challenge,
            'rp' => [
                'id' => $config['rp_id'],
                'name' => $config['rp_name'],
            ],
            'user' => [
                'id' => $this->encodeBase64Url($config['user_handle']),
                'name' => $config['user_name'],
                'displayName' => $config['user_display_name'],
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'excludeCredentials' => $excludeCredentials,
        ]);
    }

    public function finishRegistration(Request $request): JsonResponse
    {
        if (! $request->session()->get('ledger_authenticated', false)) {
            abort(403, 'パスキーを登録するには先にログインしてください。');
        }

        $config = $this->getPasskeyConfig();

        $validated = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'challenge' => ['required', 'string'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.attestationObject' => ['required', 'string'],
            'response.publicKey' => ['required', 'string'],
            'response.publicKeyAlgorithm' => ['required', 'integer'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string'],
        ]);

        $sessionChallenge = $request->session()->pull('webauthn.registration.challenge');

        if (! is_string($sessionChallenge)) {
            throw ValidationException::withMessages([
                'challenge' => '登録用チャレンジが期限切れです。ページを再読み込みしてください。',
            ]);
        }

        if (! hash_equals($sessionChallenge, $validated['challenge'])) {
            throw ValidationException::withMessages([
                'challenge' => 'チャレンジが一致しません。',
            ]);
        }

        if (LedgerCredential::query()->where('credential_id', $validated['rawId'])->exists()) {
            throw ValidationException::withMessages([
                'id' => 'このパスキーは既に登録されています。',
            ]);
        }

        $credential = new LedgerCredential([
            'user_handle' => $config['user_handle'],
            'credential_id' => $validated['rawId'],
            'type' => $validated['type'],
            'transports' => $validated['transports'] ?? null,
            'attestation_type' => 'none',
            'public_key' => $validated['response']['publicKey'],
            'public_key_algorithm' => $validated['response']['publicKeyAlgorithm'],
            'sign_count' => 0,
        ]);

        $credential->save();

        return response()->json([
            'status' => 'registered',
        ], 201);
    }

    public function beginAuthentication(Request $request): JsonResponse
    {
        $config = $this->getPasskeyConfig();

        $challenge = $this->encodeBase64Url(random_bytes(32));
        $request->session()->put('webauthn.authentication.challenge', $challenge);

        $allowCredentials = LedgerCredential::query()
            ->where('user_handle', $config['user_handle'])
            ->get()
            ->map(static function (LedgerCredential $credential): array {
                return [
                    'type' => $credential->type,
                    'id' => $credential->credential_id,
                    'transports' => $credential->transports ?? [],
                ];
            })
            ->values();

        return response()->json([
            'challenge' => $challenge,
            'rpId' => $config['rp_id'],
            'timeout' => 60000,
            'allowCredentials' => $allowCredentials,
            'userVerification' => 'preferred',
        ]);
    }

    public function finishAuthentication(Request $request): JsonResponse
    {
        $config = $this->getPasskeyConfig();

        try {
            $validated = $request->validate([
                'id' => ['required', 'string'],
                'rawId' => ['required', 'string'],
                'type' => ['required', 'string', 'in:public-key'],
                'challenge' => ['required', 'string'],
                'signCount' => ['nullable', 'integer', 'min:0'],
                'response' => ['required', 'array'],
                'response.clientDataJSON' => ['required', 'string'],
                'response.authenticatorData' => ['required', 'string'],
                'response.signature' => ['required', 'string'],
                'response.userHandle' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $exception) {
            $this->logDebug('Ledger passkey authentication request validation failed.', [
                'errors' => $exception->errors(),
            ]);

            throw $exception;
        }

        $sessionChallenge = $request->session()->pull('webauthn.authentication.challenge');

        if (! is_string($sessionChallenge)) {
            $this->logDebug('Ledger passkey authentication failed: missing session challenge.');

            throw ValidationException::withMessages([
                'challenge' => '認証用チャレンジが期限切れです。再試行してください。',
            ]);
        }

        if (! hash_equals($sessionChallenge, $validated['challenge'])) {
            $this->logDebug('Ledger passkey authentication failed: challenge mismatch.', [
                'session_challenge' => $sessionChallenge,
                'provided_challenge' => $validated['challenge'],
            ]);

            throw ValidationException::withMessages([
                'challenge' => 'チャレンジが一致しません。',
            ]);
        }

        $credential = LedgerCredential::query()
            ->where('credential_id', $validated['rawId'])
            ->first();

        if ($credential === null) {
            $this->logDebug('Ledger passkey authentication failed: credential not found.', [
                'credential_id' => $validated['rawId'],
            ]);

            throw ValidationException::withMessages([
                'id' => '登録済みのパスキーが見つかりません。',
            ]);
        }

        $userHandle = $validated['response']['userHandle'] ?? null;
        if ($userHandle !== null && $userHandle !== '') {
            $userHandle = $this->decodeBase64Url($userHandle);
        } else {
            $userHandle = $config['user_handle'];
        }

        if (! hash_equals($credential->user_handle, $userHandle)) {
            $this->logDebug('Ledger passkey authentication failed: user handle mismatch.', [
                'expected_user_handle' => $credential->user_handle,
                'provided_user_handle' => $userHandle,
            ]);

            throw ValidationException::withMessages([
                'response.userHandle' => 'ユーザーハンドルが一致しません。',
            ]);
        }

        try {
            $this->assertionValidator->validate(
                $credential,
                [
                    'clientDataJSON' => $validated['response']['clientDataJSON'],
                    'authenticatorData' => $validated['response']['authenticatorData'],
                    'signature' => $validated['response']['signature'],
                ],
                [
                    'challenge' => $validated['challenge'],
                    'rp_id' => $config['rp_id'],
                    'origin' => $this->resolveExpectedOrigin($request),
                ]
            );
        } catch (AssertionValidationException $exception) {
            $this->logDebug('Ledger passkey assertion validation failed.', [
                'credential_id' => $credential->credential_id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $newSignCount = $validated['signCount'] ?? null;

        if ($newSignCount !== null && $newSignCount < $credential->sign_count) {
            $this->logDebug('Ledger passkey authentication failed: sign count decreased.', [
                'stored_sign_count' => $credential->sign_count,
                'provided_sign_count' => $newSignCount,
            ]);

            throw ValidationException::withMessages([
                'signCount' => 'サインカウントが逆行しています。',
            ]);
        }

        $credential->sign_count = $newSignCount ?? ($credential->sign_count + 1);
        $credential->last_used_at = CarbonImmutable::now();
        $credential->save();

        $this->logDebug('Ledger passkey authentication succeeded.', [
            'credential_id' => $credential->credential_id,
            'updated_sign_count' => $credential->sign_count,
        ]);

        $request->session()->regenerate();
        $request->session()->put('ledger_authenticated', true);

        return response()->json([
            'redirect' => route('adjustment.form'),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('ledger.login.form');
    }

    /**
     * @return array{rp_id:string,rp_name:string,user_name:string,user_display_name:string,user_handle:string}
     */
    private function getPasskeyConfig(): array
    {
        return PasskeyConfig::resolve();
    }

    private function isCredentialLoginEnabled(): bool
    {
        $usernameHash = config('services.ledger_form.username_hash');
        $passwordHash = config('services.ledger_form.password_hash');

        return is_string($usernameHash) && $usernameHash !== ''
            && is_string($passwordHash) && $passwordHash !== '';
    }

    private function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }

    private function resolveExpectedOrigin(Request $request): ?string
    {
        $originHeader = $request->headers->get('origin');

        if (is_string($originHeader) && $originHeader !== '') {
            return rtrim($originHeader, '/');
        }

        $appUrl = config('app.url');

        if (is_string($appUrl) && $appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        $schemeAndHost = $request->getSchemeAndHttpHost();

        if ($schemeAndHost === '') {
            return null;
        }

        return rtrim($schemeAndHost, '/');
    }

    private function logDebug(string $message, array $context = []): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug($message, $context);
    }
}
