<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>調整額フォーム ログイン</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-app-favicon />
    <style>
        :root {
            color-scheme: light dark;
            font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif;
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f7fa, #c3cfe2);
            padding: 1.5rem;
        }
        .card {
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 1rem;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12);
            max-width: 420px;
            width: 100%;
            padding: 2rem 1.75rem;
            backdrop-filter: blur(8px);
        }
        h1 {
            margin-top: 0;
            font-size: 1.5rem;
            text-align: center;
            color: #1f2937;
        }
        p.suggestion {
            font-size: 0.95rem;
            line-height: 1.5;
            color: #4b5563;
            background-color: #f3f4f6;
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        button {
            margin-top: 0.5rem;
            padding: 0.9rem;
            border: none;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #2563eb, #4338ca);
            color: #fff;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        button:active {
            transform: scale(0.98);
        }
        button:hover {
            box-shadow: 0 10px 25px rgba(67, 56, 202, 0.25);
        }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .note {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: #6b7280;
            line-height: 1.5;
        }
        .separator {
            border: none;
            border-top: 1px solid rgba(148, 163, 184, 0.4);
            margin: 2rem 0 1.5rem;
        }
        .credentials-login {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .credentials-login h2 {
            margin: 0;
            font-size: 1.1rem;
            color: #1f2937;
        }
        .credentials-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }
        .credentials-input {
            width: 100%;
            padding: 0.85rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.6);
            font-size: 1rem;
            box-sizing: border-box;
        }
        .credentials-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }
        .credentials-button {
            margin-top: 0;
        }
        .credentials-error {
            margin: 0;
            color: #b91c1c;
            font-size: 0.9rem;
        }
        #status {
            margin-top: 1rem;
            min-height: 1.5rem;
            font-size: 0.95rem;
            color: #1f2937;
        }
        #status.error {
            color: #b91c1c;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>調整額フォーム ログイン</h1>
        <p class="suggestion">{{ $suggestedMethod }}</p>
        <div class="actions">
            <button type="button" id="register-button">パスキーを登録</button>
            <button type="button" id="login-button">パスキーでログイン</button>
        </div>
        <p class="note">初回は「パスキーを登録」で端末のパスキーを保存し、次回以降は「パスキーでログイン」で生体認証またはデバイス認証を行ってください。</p>
        <div id="status" role="status" aria-live="polite"></div>

        @if ($credentials['enabled'])
            <hr class="separator" aria-hidden="true">
            <div class="credentials-login" aria-labelledby="credentials-login-title">
                <h2 id="credentials-login-title">PCなどからログインする場合</h2>
                <p class="note">パスキーが利用できない端末では、事前に共有されたユーザー名とパスワードでログインできます。</p>
                <form method="POST" action="{{ $routes['credentials_login'] }}" novalidate>
                    @csrf
                    <label class="credentials-label" for="username">ユーザー名</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="{{ old('username') }}"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        class="credentials-input"
                        required
                    >
                    @error('username')
                        <p class="credentials-error" role="alert">{{ $message }}</p>
                    @enderror
                    <label class="credentials-label" for="password">パスワード</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        class="credentials-input"
                        required
                    >
                    @error('password')
                        <p class="credentials-error" role="alert">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="credentials-button">ログイン</button>
                </form>
            </div>
        @endif
    </div>
    <script>
        const routes = @json($routes);
        const passkeyConfig = @json($passkey);

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const statusElement = document.getElementById('status');
        const registerButton = document.getElementById('register-button');
        const loginButton = document.getElementById('login-button');
        const textEncoder = new TextEncoder();
        const defaultUserHandle = stringToBase64Url(passkeyConfig.user_handle);

        if (!window.PublicKeyCredential) {
            setStatus('このブラウザはパスキー認証に対応していません。最新のブラウザをご利用ください。', true);
            registerButton.disabled = true;
            loginButton.disabled = true;
        }

        registerButton?.addEventListener('click', async () => {
            await withProcessing(registerButton, async () => {
                setStatus('パスキー登録オプションを取得しています…');
                const options = await postJson(routes.register_options, {});

                const publicKey = transformCreationOptions(options);
                const credential = await navigator.credentials.create({ publicKey });

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
                    },
                    clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
                    transports,
                    challenge: options.challenge,
                };

                await postJson(routes.register, payload);
                setStatus('パスキーを登録しました。次回からは「パスキーでログイン」を選択してください。');
            });
        });

        loginButton?.addEventListener('click', async () => {
            await withProcessing(loginButton, async () => {
                setStatus('パスキー認証を開始しています…');
                const options = await postJson(routes.login_options, {});

                if (!options.allowCredentials || options.allowCredentials.length === 0) {
                    setStatus('登録済みのパスキーがありません。先に登録を行ってください。', true);
                    return;
                }

                const publicKey = transformRequestOptions(options);
                const assertion = await navigator.credentials.get({ publicKey });

                const signCount = extractSignCount(assertion.response.authenticatorData);

                const payload = {
                    id: assertion.id,
                    rawId: bufferToBase64Url(assertion.rawId),
                    type: assertion.type,
                    response: {
                        clientDataJSON: bufferToBase64Url(assertion.response.clientDataJSON),
                        authenticatorData: bufferToBase64Url(assertion.response.authenticatorData),
                        signature: bufferToBase64Url(assertion.response.signature),
                        userHandle: assertion.response.userHandle
                            ? bufferToBase64Url(assertion.response.userHandle)
                            : defaultUserHandle,
                    },
                    clientExtensionResults: assertion.getClientExtensionResults?.() ?? {},
                    challenge: options.challenge,
                    signCount,
                };

                const result = await postJson(routes.login, payload);

                if (result.redirect) {
                    window.location.href = result.redirect;
                    return;
                }

                setStatus('パスキー認証に成功しました。');
            });
        });

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

        function setStatus(message, isError = false) {
            if (!statusElement) {
                return;
            }

            statusElement.textContent = message;
            statusElement.classList.toggle('error', isError);
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
            const error = new Error('Request failed');
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

        function transformRequestOptions(options) {
            return {
                ...options,
                challenge: base64UrlToBuffer(options.challenge),
                allowCredentials: (options.allowCredentials ?? []).map((credential) => ({
                    ...credential,
                    id: base64UrlToBuffer(credential.id),
                })),
            };
        }

        function handleError(error) {
            if (error?.name === 'NotAllowedError') {
                setStatus('認証がキャンセルされました。もう一度お試しください。', true);
                return;
            }

            if (error?.data?.errors) {
                const firstMessage = Object.values(error.data.errors)[0]?.[0];
                if (firstMessage) {
                    setStatus(firstMessage, true);
                    return;
                }
            }

            if (error?.message) {
                setStatus(error.message, true);
                return;
            }

            setStatus('不明なエラーが発生しました。', true);
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

        function extractSignCount(authenticatorData) {
            if (!authenticatorData) {
                return null;
            }

            const view = new DataView(authenticatorData);

            if (view.byteLength < 37) {
                return null;
            }

            return view.getUint32(33, false);
        }

        function stringToBase64Url(value) {
            return bufferToBase64Url(textEncoder.encode(value));
        }
    </script>
</body>
</html>
