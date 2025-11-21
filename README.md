# Notion Ledger Sync

Notion の家計簿データベースから月次の入出金を集計し、繰越ページの自動生成とメール／Slack でのレポート配信を行う Laravel 11 製 API・管理ツールです。
さらに、銀行残高と手持ち現金との差異を調整するための Web フォームを提供し、パスキー（FIDO2）もしくはハッシュ済みクレデンシャルで安全に運用できます。

## 主な機能

- **月次集計 Webhook** — `POST /api/notion_webhook/monthly-sum` に対して年月 (`YYYY-MM`) を指定すると、対象期間の Notion ページを取得して口座別・総合計・件数を集計します。設定した口座が見つからない場合は 0 円で補完し、翌月 1 日付の繰越ページを Notion に自動登録します。【F:app/Http/Controllers/NotionMonthlySumController.php†L15-L64】【F:app/Services/MonthlySumService.php†L13-L84】
- **通知チャンネル** — 月次集計の結果をメール（Mailable）と Slack DM に送信できます。送信に失敗したチャンネルはログ出力し、他チャンネルには影響しません。【F:app/Http/Controllers/NotionMonthlySumController.php†L33-L62】【F:app/Services/Notify】
- **Ledger 調整フォーム** — ログイン後のフォームで銀行残高と手持ち現金を入力すると、Notion 上の該当口座（既定は `現金/普通預金`）との差分を計算し、ワンクリックで調整レコードを Notion に登録できます。【F:app/Http/Controllers/LedgerAdjustmentController.php†L12-L71】【F:app/Services/Adjustment/AdjustmentService.php†L13-L61】
- **パスキー対応ログイン** — Ledger フォームはパスキー（WebAuthn/FIDO2）による生体認証ログインに対応し、サインカウント検証や登録済みクレデンシャルの除外処理を行います。必要に応じてハッシュ化した ID／パスワードによるログインも併用可能です。【F:app/Http/Controllers/LedgerAuthController.php†L19-L211】【F:config/services.php†L39-L77】

## 動作環境

- PHP 8.2 以上
- Composer
- SQLite（既定値）または任意の Laravel 対応データベース
- メール・Slack を利用する場合はそれぞれの送信先アカウント

## セットアップ

```bash
composer install
cp .env.example .env
php artisan key:generate
# SQLite を使う場合
mkdir -p database && touch database/database.sqlite
php artisan migrate
```

Web サーバーを立ち上げるには `php artisan serve` を実行します。

## 環境変数の設定

`.env` に以下の値を設定してください。

### アプリケーション共通

| キー | 説明 |
| --- | --- |
| `APP_URL` | パスキーの RP 情報や通知 URL の生成に利用します。サブディレクトリにデプロイする場合は `APP_URL_PREFIX` も設定してください。 |
| `APP_TIMEZONE` | 月次集計の既定年月や調整フォームの表示時刻に利用します。 |

### Notion 接続

| キー | 説明 |
| --- | --- |
| `NOTION_API_TOKEN` | Notion の内部統合トークン（必須）。 |
| `NOTION_DATA_SOURCE_ID` | 新 Notion API のデータソース ID。未設定の場合は `NOTION_DATABASE_ID` から自動解決します。 |
| `NOTION_DATABASE_ID` | 家計簿データベース ID。繰越・調整ページの作成にも使用します。 |
| `NOTION_VERSION` | Notion API バージョン（既定 `2025-09-03`）。 |

### Webhook 認証

| キー | 説明 |
| --- | --- |
| `WEBHOOK_TOKEN` | `X-Webhook-Token` ヘッダーと照合する共有シークレット。 |

### 月次集計

| キー | 説明 |
| --- | --- |
| `MONTHLY_SUM_ACCOUNT` | 集計結果に必ず含めたい口座名のリスト（カンマまたは改行区切り）。未指定時は `MONTHLY_SUM_ACCOUNT_CASH`（既定 `現金/普通預金`）と `MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT`（既定 `定期預金`）を統合した値が使われます。 |

### 通知設定

| キー | 説明 |
| --- | --- |
| `SYNC_REPORT_MAIL_TO` | 月次レポートを送信するメールアドレス。空の場合はメール送信をスキップします。 |
| `MAIL_*` | Laravel のメール設定。SMTP 以外を使用する場合は適宜変更してください。 |
| `SLACK_BOT` | Slack 通知を有効化する場合は `true`。 |
| `SLACK_BOT_TOKEN` | Bot Token (`xoxb-...`)。 |
| `SLACK_DM_USER_IDS` | カンマ区切りのユーザー ID（例: `U12345678,U23456789`）。指定した全員に DM を送信します。 |
| `SLACK_UNFURL_LINKS` / `SLACK_UNFURL_MEDIA` | Slack メッセージの展開設定。 |

### Ledger 認証

| キー | 説明 |
| --- | --- |
| `LEDGER_FORM_USERNAME_HASH` / `LEDGER_FORM_PASSWORD_HASH` | それぞれ `password_hash(..., PASSWORD_BCRYPT)` で生成したハッシュを設定すると、ID／パスワードでのログインを有効化します。 |
| `LEDGER_PASSKEY_RP_ID` / `LEDGER_PASSKEY_RP_NAME` | パスキー認証に使用する RP 情報。未指定時は `APP_URL` からホスト名を自動取得します。 |
| `LEDGER_PASSKEY_USER_*` | Ledger フォーム専用の仮想ユーザー情報。単一ユーザー運用を想定しています。 |

```bash
php -r "echo password_hash('希望するログイン名', PASSWORD_BCRYPT), PHP_EOL;"
php -r "echo password_hash('希望するパスワード', PASSWORD_BCRYPT), PHP_EOL;"
```

生成したハッシュを `.env` に記載すると、ID／パスワードでのログインボタンが表示されます。

## 月次集計 Webhook の利用方法

```
POST /api/notion_webhook/monthly-sum
X-Webhook-Token: <WEBHOOK_TOKEN>
{ "year_month": "2024-12" }
```

- `year_month` を省略すると `APP_TIMEZONE` 上の現在年月を対象とします。【F:app/Http/Controllers/NotionMonthlySumController.php†L25-L32】
- Notion API から取得したページを集計し、結果を Slack／メールに送信します。【F:app/Services/MonthlySumService.php†L23-L57】
- 各口座の合計値と総合計、処理件数、繰越ページの作成成否を含むペイロードを通知します。【F:app/Services/MonthlySumService.php†L59-L84】【F:resources/views/mail/monthly_sum_report.blade.php†L1-L88】
- 成功時のレスポンスは HTTP 204（ボディなし）です。【F:app/Http/Controllers/NotionMonthlySumController.php†L64】

## Ledger 調整フォームの使い方

1. `/login` にアクセスし、パスキー登録またはクレデンシャルログインでサインインします。初回登録時は「端末を登録」ボタンからパスキーを作成します。【F:app/Http/Controllers/LedgerAuthController.php†L42-L118】
2. ログイン後に表示されるフォームで銀行口座残高と手持ち現金を入力し、「調整額計算」を押すと Notion の対象口座との差分が表示されます。【F:app/Http/Controllers/LedgerAdjustmentController.php†L28-L44】【F:resources/views/ledger/adjustment.blade.php†L55-L144】
3. 調整額を Notion に登録したい場合は「調整額を家計簿に登録」をクリックします。金額は自動で正負を判定し、摘要やカテゴリーを「調整」で登録します。【F:app/Services/Adjustment/AdjustmentService.php†L63-L80】【F:app/Services/Notion/NotionClient.php†L101-L159】
4. 使い終わったらヘッダーの「ログアウト」からセッションを終了します。【F:resources/views/ledger/adjustment.blade.php†L24-L36】

## サブディレクトリ配下にデプロイする場合

WordPress などと同じドキュメントルートを共有しながら `/api/notion_webform` や `/api/notion_webhook` のようなサブディレクトリで運用する際は、

1. `.env` に `APP_URL_PREFIX=api/notion_webform`（末尾スラッシュなし）を設定します。
2. `public_html/.htaccess` などで対象パスをアプリの `public/index.php` にリライトしてください。

```apacheconf
# 既存のファイルやディレクトリはそのまま扱う
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# 直下とそれ以外でルールを分け、末尾スラッシュによる 403 を回避
RewriteRule ^api/notion_webform$ /api/notion/public/index.php [L,QSA]
RewriteRule ^api/notion_webform/(.+)$ /api/notion/public/index.php/$1 [L,QSA]
RewriteRule ^api/notion_webhook$ /api/notion/public/index.php [L,QSA]
RewriteRule ^api/notion_webhook/(.+)$ /api/notion/public/index.php/$1 [L,QSA]
```

実際の配置ディレクトリに合わせて書き換えてください。

## テスト

```bash
php artisan test
```

SQLite を利用する場合はテスト実行前に `database/database.sqlite` を作成してください。
