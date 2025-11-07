<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string'],
        ]);

        $configuredPin = (string) config('services.ledger_form.pin', '');

        if ($configuredPin === '') {
            return back()->withErrors([
                'pin' => 'ログイン用のPINコードが設定されていません。環境変数 LEDGER_FORM_PIN を設定してください。',
            ])->withInput();
        }

        if (! hash_equals($configuredPin, (string) $validated['pin'])) {
            return back()->withErrors([
                'pin' => 'PINコードが一致しません。',
            ])->withInput();
        }

        $request->session()->regenerate();
        $request->session()->put('ledger_authenticated', true);

        return redirect()->intended(route('adjustment.form'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('ledger.login.form');
    }
}
