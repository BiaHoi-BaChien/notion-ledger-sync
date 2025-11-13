<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-app-favicon />
    <title>調整額計算ツール</title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --accent: #14b8a6;
            --text: #0f172a;
            --muted: #64748b;
            --bg: #f1f5f9;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        body.is-processing {
            cursor: progress;
        }
        body.is-processing > *:not(.processing-overlay) {
            pointer-events: none;
        }
        header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        header h1 {
            margin: 0;
            font-size: clamp(1.2rem, 4vw, 1.6rem);
            font-weight: 700;
        }
        header form {
            margin: 0;
        }
        header button {
            border: none;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            padding: 0.5rem 1rem;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        header button:hover {
            background: rgba(255, 255, 255, 0.35);
        }
        main {
            width: min(960px, 100%);
            margin: 0 auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .card {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
            padding: clamp(1.25rem, 5vw, 2rem);
        }
        .card h2 {
            margin-top: 0;
            font-size: clamp(1.1rem, 3.8vw, 1.4rem);
            color: var(--text);
        }
        .input-note {
            margin: 0.3rem 0 1.1rem;
            color: var(--muted);
            font-size: 0.95rem;
        }
        .inputs {
            display: grid;
            gap: 1.25rem;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        label {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            font-weight: 600;
            color: var(--muted);
        }
        input[type="number"] {
            padding: 0.9rem 1rem;
            border-radius: 0.9rem;
            border: 1px solid #cbd5f5;
            font-size: 1.05rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background-color: #f8fafc;
            color: var(--text);
        }
        input[type="number"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
            background-color: #fff;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        .register-form {
            margin-top: 0.75rem;
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .register-form button {
            flex: 1 1 220px;
            width: 100%;
        }
        .primary-btn,
        .secondary-btn {
            border: none;
            border-radius: 0.9rem;
            padding: 0.9rem 1.35rem;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .primary-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            flex: 1 1 220px;
        }
        .secondary-btn {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #fff;
            flex: 1 1 220px;
        }
        .primary-btn:active,
        .secondary-btn:active {
            transform: scale(0.98);
        }
        .primary-btn:hover,
        .secondary-btn:hover {
            box-shadow: 0 12px 24px rgba(14, 165, 233, 0.2);
        }
        @media (max-width: 480px) {
            .primary-btn,
            .secondary-btn {
                padding: 0.7rem 1.1rem;
                font-size: 1rem;
            }
        }
        .status {
            margin-top: 1.5rem;
            border-radius: 1rem;
            padding: 1rem 1.2rem;
            font-weight: 600;
        }
        .status-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 1000;
        }
        .processing-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.7);
            font-weight: 600;
            font-size: 1.25rem;
            color: var(--text);
            z-index: 1500;
        }
        .status-overlay .status {
            margin-top: 0;
            min-width: min(90%, 360px);
            text-align: center;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
            pointer-events: auto;
        }
        .status.success {
            background: #14b8a6;
            color: #fff;
        }
        .status.error {
            background: #ef4444;
            color: #fff;
        }
        .result-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-top: 1.5rem;
        }
        .result-item {
            background: #f8fafc;
            border-radius: 1rem;
            padding: 1rem 1.2rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .result-item span.label {
            font-size: 0.9rem;
            color: var(--muted);
            font-weight: 600;
        }
        .result-item span.value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
        }
        .calculation-summary {
            margin-top: 1.25rem;
            padding: 1.1rem 1.3rem;
            border-radius: 1rem;
            background: rgba(37, 99, 235, 0.08);
            color: var(--text);
            line-height: 1.6;
        }
        .calculation-summary strong {
            display: inline-block;
            margin-bottom: 0.35rem;
        }
        .timestamp {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--muted);
        }
        @media (max-width: 600px) {
            header {
                flex-direction: column;
                align-items: flex-start;
            }
            header button {
                width: 100%;
                text-align: center;
            }
            .actions {
                flex-direction: column;
            }
            .primary-btn,
            .secondary-btn {
                width: 100%;
            }
            .actions .primary-btn,
            .actions .secondary-btn {
                flex: 0 0 auto;
            }
            .header-actions {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
            }
            .header-actions form,
            .header-actions button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>調整額計算ツール</h1>
        <div class="header-actions">
            <button type="button" id="passkey-register-button">パスキー登録</button>
            <form method="post" action="{{ route('ledger.logout') }}">
                @csrf
                <button type="submit">ログアウト</button>
            </form>
        </div>
    </header>
    <main>
        <div id="passkey-status" class="status" role="status" aria-live="polite" hidden></div>
        <section class="card">
            <h2>現在の銀行残高と手持ちの現金の金額を入力してください</h2>
            <form method="post" action="{{ route('adjustment.calculate') }}" class="calculate-form">
                @csrf
                <div class="inputs">
                    <label for="bank_balance">
                        現在の銀行口座の残高
                        <input id="bank_balance" name="bank_balance" type="number" step="0.01" inputmode="decimal" value="{{ old('bank_balance', $inputs['bank_balance'] ?? '') }}" required>
                    </label>
                    <label for="cash_on_hand">
                        手持ちの現金
                        <input id="cash_on_hand" name="cash_on_hand" type="number" step="0.01" inputmode="decimal" value="{{ old('cash_on_hand', $inputs['cash_on_hand'] ?? '') }}" required>
                    </label>
                </div>
                @if ($errors->any())
                    <div class="status error" role="alert">
                        入力値を確認してください。
                    </div>
                @endif
                <div class="actions">
                    <button class="primary-btn" type="submit">調整額計算</button>
                </div>
            </form>
            @if ($result)
                @php
                    $formattedBank = number_format($result->bankBalance, 0, '.', ',');
                    $formattedCash = number_format($result->cashOnHand, 0, '.', ',');
                    $formattedPhysicalTotal = number_format($result->physicalTotal, 0, '.', ',');
                    $formattedNotionTotal = number_format($result->notionTotal, 0, '.', ',');
                    $formattedAdjustment = number_format($result->adjustmentAmount, 0, '.', ',');
                @endphp
                <div class="result-grid">
                    <div class="result-item">
                        <span class="label">銀行口座残高</span>
                        <span class="value">{{ $formattedBank }}₫</span>
                    </div>
                    <div class="result-item">
                        <span class="label">手持ち現金</span>
                        <span class="value">{{ $formattedCash }}₫</span>
                    </div>
                    <div class="result-item">
                        <span class="label">銀行口座＋現金の実残高合計</span>
                        <span class="value">{{ $formattedPhysicalTotal }}₫</span>
                    </div>
                    <div class="result-item">
                        <span class="label">Notion記録の合計 ({{ $result->accountName }})</span>
                        <span class="value">{{ $formattedNotionTotal }}₫</span>
                    </div>
                    <div class="result-item">
                        <span class="label">計算された調整額</span>
                        <span class="value">{{ $formattedAdjustment }}₫</span>
                        <form method="post" action="{{ route('adjustment.register') }}" class="register-form">
                            @csrf
                            <input type="hidden" name="bank_balance" value="{{ $inputs['bank_balance'] }}">
                            <input type="hidden" name="cash_on_hand" value="{{ $inputs['cash_on_hand'] }}">
                            <button class="secondary-btn" type="submit">調整額を家計簿に登録</button>
                        </form>
                    </div>
                </div>
                <div class="calculation-summary">
                    <p>
                        <strong>調整額 = 実残高合計 − Notion記録の合計</strong><br>
                        ({{ $formattedPhysicalTotal }}₫ − {{ $formattedNotionTotal }}₫ = {{ $formattedAdjustment }}₫)
                    </p>
                    <p>入力した銀行口座残高と手持ち現金を合計した実残高と、Notionに記録された合計額との差額が調整額として算出されています。</p>
                </div>
                <p class="timestamp">
                    対象月: {{ $result->targetMonthStart->format('Y年n月') }} / 計算日時: {{ $result->calculatedAt->timezone(config('app.timezone'))->format('Y年n月j日 H:i') }}
                </p>
            @endif
        </section>
    </main>
    @if ($status)
        <div class="status-overlay">
            <div class="status {{ $status['success'] ? 'success' : 'error' }}" role="status">
                {{ $status['message'] }}
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const overlay = document.querySelector('.status-overlay');
                if (!overlay) {
                    return;
                }

                const statusElement = overlay.querySelector('.status');
                if (!statusElement) {
                    return;
                }

                if (statusElement.classList.contains('success')) {
                    overlay.style.transition = 'opacity 0.3s ease';
                    setTimeout(function () {
                        overlay.style.opacity = '0';
                        overlay.addEventListener('transitionend', function () {
                            overlay.remove();
                        }, { once: true });
                    }, 5000);
                }
            });
        </script>
    @endif
</body>
<script>
    const routes = @json($passkeyRoutes);

    const calculateForm = document.querySelector('.calculate-form');
    calculateForm?.addEventListener('submit', () => {
        const elementsToPreserve = Array.from(
            calculateForm.querySelectorAll('button, input, select, textarea')
        );

        setProcessingState(true, { exclude: elementsToPreserve });
    });

    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const registerButton = document.getElementById('passkey-register-button');
    const statusElement = document.getElementById('passkey-status');

    if (!window.PublicKeyCredential && registerButton) {
        registerButton.disabled = true;
        setStatus('このブラウザはパスキー認証に対応していません。最新のブラウザをご利用ください。', 'error');
    }

    registerButton?.addEventListener('click', async () => {
        await withProcessing(registerButton, async () => {
            setStatus('パスキー登録オプションを取得しています…');
            const options = await postJson(routes.register_options, {});

            const publicKey = transformCreationOptions(options);
            const credential = await navigator.credentials.create({ publicKey });

            const exportedPublicKey = typeof credential.response.getPublicKey === 'function'
                ? credential.response.getPublicKey()
                : null;

            if (!exportedPublicKey) {
                throw new Error('取得したパスキーの公開鍵を処理できません。別のブラウザをお試しください。');
            }

            const publicKeyAlgorithm = typeof credential.response.getPublicKeyAlgorithm === 'function'
                ? credential.response.getPublicKeyAlgorithm()
                : -8;

            const transports = typeof credential.response.getTransports === 'function'
                ? credential.response.getTransports()
                : [];

            const payload = {
                id: credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                    attestationObject: bufferToBase64Url(credential.response.attestationObject),
                    publicKey: bufferToBase64Url(exportedPublicKey),
                    publicKeyAlgorithm,
                },
                clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
                transports,
                challenge: options.challenge,
            };

            await postJson(routes.register, payload);
            setStatus('パスキーを登録しました。次回からは「パスキーでログイン」を選択してください。', 'success');
        });
    });

    function setProcessingState(isProcessing, options = {}) {
        const exclude = Array.isArray(options.exclude) ? options.exclude : [];
        const excludeSet = new Set(exclude);
        const interactiveElements = document.querySelectorAll('button, input, select, textarea');

        interactiveElements.forEach((element) => {
            if (excludeSet.has(element)) {
                return;
            }

            if (isProcessing) {
                if (!element.dataset.initiallyDisabled) {
                    element.dataset.initiallyDisabled = element.disabled ? 'true' : 'false';
                }

                element.disabled = true;
            } else if (element.dataset.initiallyDisabled === 'false') {
                element.disabled = false;
                delete element.dataset.initiallyDisabled;
            } else if (element.dataset.initiallyDisabled === 'true') {
                delete element.dataset.initiallyDisabled;
            }
        });

        document.body.classList.toggle('is-processing', isProcessing);

        if (isProcessing) {
            if (!document.querySelector('.processing-overlay')) {
                const overlay = document.createElement('div');
                overlay.className = 'processing-overlay';
                overlay.textContent = '計算中…';
                document.body.appendChild(overlay);
            }
        } else {
            document.querySelector('.processing-overlay')?.remove();
        }
    }

    async function withProcessing(button, callback) {
        try {
            button.disabled = true;
            await callback();
        } catch (error) {
            handleError(error);
        } finally {
            button.disabled = false;
        }
    }

    function setStatus(message, type = 'info') {
        if (!statusElement) {
            return;
        }

        statusElement.hidden = false;
        statusElement.textContent = message;
        statusElement.classList.remove('error', 'success');

        if (type === 'error') {
            statusElement.classList.add('error');
        } else if (type === 'success') {
            statusElement.classList.add('success');
        }
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(body),
        });

        if (response.ok) {
            return response.json();
        }

        const data = await response.json().catch(() => null);
        const firstValidationMessage = data?.errors
            ? Object.values(data.errors).flat().find((message) => typeof message === 'string')
            : null;
        const errorMessage = firstValidationMessage
            ?? (typeof data?.message === 'string' ? data.message : null)
            ?? 'パスキーの登録に失敗しました。時間をおいて再度お試しください。';

        const error = new Error(errorMessage);
        error.status = response.status;
        error.data = data;
        throw error;
    }

    function transformCreationOptions(options) {
        return {
            ...options,
            challenge: base64UrlToBuffer(options.challenge),
            user: {
                ...options.user,
                id: base64UrlToBuffer(options.user.id),
            },
            excludeCredentials: (options.excludeCredentials ?? []).map((credential) => ({
                ...credential,
                id: base64UrlToBuffer(credential.id),
            })),
        };
    }

    function handleError(error) {
        if (error?.name === 'InvalidStateError'
            || typeof error?.message === 'string' && error.message.includes('credential manager')
        ) {
            setStatus('この端末には既にパスキーが登録されています。「パスキーでログイン」をお試しください。');
            return;
        }

        if (error?.name === 'NotAllowedError') {
            setStatus('認証がキャンセルされました。もう一度お試しください。', 'error');
            return;
        }

        if (error?.data?.errors) {
            const firstMessage = Object.values(error.data.errors)[0]?.[0];
            if (firstMessage) {
                setStatus(firstMessage, 'error');
                return;
            }
        }

        if (error?.message) {
            setStatus(error.message, 'error');
            return;
        }

        setStatus('不明なエラーが発生しました。', 'error');
    }

    function bufferToBase64Url(buffer) {
        let bytes;

        if (buffer instanceof ArrayBuffer) {
            bytes = new Uint8Array(buffer);
        } else if (ArrayBuffer.isView(buffer)) {
            bytes = new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength);
        } else {
            throw new TypeError('Unsupported buffer type');
        }

        let binary = '';

        for (let i = 0; i < bytes.byteLength; i += 1) {
            binary += String.fromCharCode(bytes[i]);
        }

        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }

    function base64UrlToBuffer(base64url) {
        const padding = '='.repeat((4 - (base64url.length % 4)) % 4);
        const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes.buffer;
    }
</script>
</html>
