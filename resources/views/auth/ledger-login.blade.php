<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>調整額フォーム ログイン</title>
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
        form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        label {
            font-weight: 600;
            color: #374151;
        }
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 0.85rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid #d1d5db;
            font-size: 1.1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }
        .error {
            color: #b91c1c;
            font-size: 0.9rem;
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
    </style>
</head>
<body>
    <div class="card">
        <h1>調整額フォーム ログイン</h1>
        <p class="suggestion">{{ $suggestedMethod }}</p>
        <form method="post" action="{{ route('ledger.login') }}">
            @csrf
            <label for="pin">PINコード</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" required>
            @error('pin')
                <div class="error">{{ $message }}</div>
            @enderror
            <button type="submit">ログイン</button>
        </form>
    </div>
</body>
</html>
