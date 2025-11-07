<?php

namespace App\Http\Controllers;

use App\Models\LedgerCredential;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LedgerAuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('ledger_authenticated', false)) {
            return redirect()->intended(route('adjustment.form'));
        }

        $suggestedMethod = '端末の生体認証やパスキー（FIDO2）によるパスワードレス認証を導入すると、スマホ入力でもストレスなく安全に利用できます。';

        return view('auth.ledger-login', [
            'suggestedMethod' => $suggestedMethod,
            'passkey' => $this->getPasskeyConfig(),
            'routes' => [
                'register_options' => route('ledger.passkey.register.options'),
                'register' => route('ledger.passkey.register.store'),
                'login_options' => route('ledger.passkey.login.options'),
                'login' => route('ledger.passkey.login.verify'),
            ],
        ]);
    }

    public function beginRegistration(Request $request): JsonResponse
    {
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
        $config = $this->getPasskeyConfig();

        $validated = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'challenge' => ['required', 'string'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.attestationObject' => ['required', 'string'],
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
            'public_key' => $validated['response']['attestationObject'],
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

        $sessionChallenge = $request->session()->pull('webauthn.authentication.challenge');

        if (! is_string($sessionChallenge)) {
            throw ValidationException::withMessages([
                'challenge' => '認証用チャレンジが期限切れです。再試行してください。',
            ]);
        }

        if (! hash_equals($sessionChallenge, $validated['challenge'])) {
            throw ValidationException::withMessages([
                'challenge' => 'チャレンジが一致しません。',
            ]);
        }

        $credential = LedgerCredential::query()
            ->where('credential_id', $validated['rawId'])
            ->first();

        if ($credential === null) {
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
            throw ValidationException::withMessages([
                'response.userHandle' => 'ユーザーハンドルが一致しません。',
            ]);
        }

        $newSignCount = $validated['signCount'] ?? null;

        if ($newSignCount !== null && $newSignCount < $credential->sign_count) {
            throw ValidationException::withMessages([
                'signCount' => 'サインカウントが逆行しています。',
            ]);
        }

        $credential->sign_count = $newSignCount ?? ($credential->sign_count + 1);
        $credential->last_used_at = CarbonImmutable::now();
        $credential->save();

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
        $config = config('services.ledger_passkey');

        if (! is_array($config)) {
            throw new RuntimeException('ledger_passkey configuration is missing.');
        }

        return $config;
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
}
