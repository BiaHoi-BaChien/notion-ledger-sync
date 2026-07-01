# Notion Ledger Sync

Notion の家計簿データベースから月次の入出金を集計し、繰越ページの自動生成とメール／Slack でのレポート配信を行う Laravel 13 製 API・管理ツールです。
さらに、銀行残高と手持ち現金との差異を調整するための Web フォームを提供し、パスキー（FIDO2）もしくはハッシュ済みクレデンシャルで安全に運用できます。

## 主な機能

- **月次集計 Webhook** — `POST /api/notion_webhook/monthly-sum`（本番では `https://clb-biahoi.net/notion_ledger_sync/api/notion_webhook/monthly-sum`）に対して年月 (`YYYY-MM`) を指定すると、対象期間の Notion ページを取得して口座別・総合計・件数を集計します。年月を省略した場合は先月が対象となり、設定した口座が見つからない場合は 0 円で補完します。繰越ページは集計対象月の翌月 1 日付（年月省略時は今月 1 日付）で Notion に自動登録します。【F:app/Http/Controllers/NotionMonthlySumController.php†L15-L72】【F:app/Services/MonthlySumService.php†L13-L89】
- **通知チャンネル** — 月次集計の結果をメール（Mailable）と Slack DM に送信できます。送信に失敗したチャンネルはログ出力し、他チャンネルには影響しません。【F:app/Http/Controllers/NotionMonthlySumController.php†L33-L63】【F:app/Services/Notify】
- **Ledger 調整フォーム** — ログイン後のフォームで銀行残高と手持ち現金を入力すると、Notion 上の該当口座（既定は `現金/普通預金`）との差分を計算し、ワンクリックで調整レコードを Notion に登録できます。【F:app/Http/Controllers/LedgerAdjustmentController.php†L12-L71】【F:app/Services/Adjustment/AdjustmentService.php†L13-L61】
- **パスキー対応ログイン** — Ledger フォームはパスキー（WebAuthn/FIDO2）による生体認証ログインに対応し、サインカウント検証や登録済みクレデンシャルの除外処理を行います。必要に応じてハッシュ化した ID／パスワードによるログインも併用可能です。【F:app/Http/Controllers/LedgerAuthController.php†L19-L211】【F:config/services.php†L39-L77】

## 動作環境

- PHP 8.5 以上
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
| `APP_URL` | パスキーの RP 情報や通知 URL の生成に利用します。本番では `https://clb-biahoi.net/notion_ledger_sync` のように公開 URL を設定してください。 |
| `APP_TIMEZONE` | 月次集計の既定年月や調整フォームの表示時刻に利用します。 |

### Notion 接続

| キー | 説明 |
| --- | --- |
| `NOTION_API_TOKEN` | Notion の内部統合トークン（必須）。 |
| `NOTION_DATA_SOURCE_ID` | 新 Notion API のデータソース ID。検索・繰越・調整ページ作成で使用します。 |
| `NOTION_DATABASE_ID` | 家計簿データベース ID。`NOTION_DATA_SOURCE_ID` 未設定時にデータソース ID を自動解決するための fallback です。 |
| `NOTION_VERSION` | Notion API バージョン（既定 `2026-03-11`）。 |

### Webhook 認証

| キー | 説明 |
| --- | --- |
| `WEBHOOK_TOKEN` | `X-Webhook-Token` ヘッダーと照合する共有シークレット。 |

### 月次集計

| キー | 説明 |
| --- | --- |
| `MONTHLY_SUM_ACCOUNT` | 集計結果に必ず含めたい口座名のリスト（カンマまたは改行区切り）。未指定時は `MONTHLY_SUM_ACCOUNT_CASH`（既定 `現金/普通預金`）と `MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT`（既定 `定期預金`）を統合した値が使われます。 |
| `MONTHLY_SUM_SKIP_IF_CARRY_OVER_EXISTS` | `true` の場合、対象月の翌月 1 日付の繰越ページが既にあると月次集計を中止し、繰越ページを追加作成しません。既定は `true` です。本番で `.env` を直接変更した場合は `php artisan optimize:clear` と `php artisan config:cache` で設定キャッシュを更新してください。 |

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
| `CASH_OR_SAVING`（`services.adjustment.target_account`） | 給与・調整レコードを登録する口座名。未設定の場合は `現金/普通預金` をターゲットにします。 |

```bash
php -r "echo password_hash('希望するログイン名', PASSWORD_BCRYPT), PHP_EOL;"
php -r "echo password_hash('希望するパスワード', PASSWORD_BCRYPT), PHP_EOL;"
```

生成した2つのハッシュを `.env` に引用符付きで設定すると、ID／パスワードでのログインボタンが表示されます。

```dotenv
LEDGER_FORM_USERNAME_HASH='$2y$...'
LEDGER_FORM_PASSWORD_HASH='$2y$...'
```

両方が未設定または片方のみ設定されている場合、credential login は無効です。hash や元の認証情報は環境ごとに固有の値を生成し、リポジトリへのコミットや別環境での再利用は行わないでください。

## 月次集計 Webhook の利用方法

```
POST /notion_ledger_sync/api/notion_webhook/monthly-sum
X-Webhook-Token: <WEBHOOK_TOKEN>
{ "year_month": "2024-12" }
```

- `year_month` を省略すると `APP_TIMEZONE` 上の先月を対象とし、繰越ページの日付は今月 1 日になります。【F:app/Http/Controllers/NotionMonthlySumController.php†L25-L72】【F:app/Services/MonthlySumService.php†L13-L89】
- Notion API から取得したページを集計し、結果を Slack／メールに送信します。【F:app/Services/MonthlySumService.php†L21-L52】【F:app/Http/Controllers/NotionMonthlySumController.php†L43-L63】
- 各口座の合計値と総合計、処理件数、繰越ページの作成成否を含むペイロードを通知します。【F:app/Services/MonthlySumService.php†L54-L89】【F:resources/views/mail/monthly_sum_report.blade.php†L1-L88】
- 成功時のレスポンスは HTTP 204（ボディなし）です。【F:app/Http/Controllers/NotionMonthlySumController.php†L72】

## Ledger 調整フォームの使い方

1. `/notion_ledger_sync/login` にアクセスし、パスキー登録またはクレデンシャルログインでサインインします。初回登録時は「端末を登録」ボタンからパスキーを作成します。【F:app/Http/Controllers/LedgerAuthController.php†L42-L118】
2. ログイン後に表示されるフォームで今回の給与振込額、普通預金残高、手持ちの金額を入力し、「調整額計算」を押すと、普通預金残高と手持ち現金の合計から Notion の対象口座の記録を差し引いた調整額が表示されます。
3. 「給与・調整額を家計簿に登録」をクリックすると、給与額が正の場合はカテゴリー・摘要を「給料」、種類を「収入」として登録します。調整額が非ゼロの場合は、金額の正負に応じて種類を判定し、カテゴリーを「調整」、摘要を「調整額」として登録します。どちらも `CASH_OR_SAVING`（または `services.adjustment.target_account`）で指定した口座を使用します。
4. 使い終わったらヘッダーの「ログアウト」からセッションを終了します。【F:resources/views/ledger/adjustment.blade.php†L24-L36】

## 本番デプロイ

本番では共有ドキュメントルートの `.htaccess` に個別 rewrite を追加せず、`https://clb-biahoi.net/notion_ledger_sync` で直接アクセスできる構成にします。

1. アプリ一式を `/home/u685478147/public_html/public_html/notion_ledger_sync` に配置します。
2. GitHub Actions が Laravel の `public/` の中身を deploy root へコピーし、root 用の `index.php` を生成して、リポジトリ root の `.htaccess` を配置します。
3. `.htaccess` は `app/`、`bootstrap/`、`config/`、`database/`、`resources/`、`routes/`、`storage/`、`vendor/`、hidden files、設定・依存・DB・鍵・ログ類などへの直接アクセスを拒否します。
4. 検索エンジンや AI クローラーに発見されにくくするため、`robots.txt` で全パスのクロールを拒否し、Apache と Laravel の両方で `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex` を返します。これは協調的なクローラー向けの抑止であり、URLを知っている第三者からのアクセス制御には認証を使ってください。
5. 共有側の `/home/u685478147/public_html/public_html/.htaccess` には、このアプリ用の rewrite を追加しません。
6. `.env` は以下のように設定します。

```dotenv
APP_URL=https://clb-biahoi.net/notion_ledger_sync
APP_TIMEZONE=Asia/Saigon
```

旧運用で使っていた `APP_URL_PREFIX=api/notion_webform` は設定しません。パスキーの RP ID は未指定で `clb-biahoi.net` が使われます。明示する場合は `LEDGER_PASSKEY_RP_ID=clb-biahoi.net` を設定してください。

GitHub Actions で自動デプロイする場合は、以下の repository secrets を設定してください。

| Secret | 説明 |
| --- | --- |
| `HOSTINGER_HOST` | Hostinger の SSH ホスト名。 |
| `HOSTINGER_PORT` | Hostinger の SSH ポート。 |
| `HOSTINGER_USER` | Hostinger の SSH ユーザー名。 |
| `HOSTINGER_SSH_KEY` | Hostinger に登録済み公開鍵と対になる秘密鍵。 |
| `HOSTINGER_DEPLOY_PATH` | デプロイ先。例: `/home/u685478147/public_html/public_html/notion_ledger_sync` |
| `HOSTINGER_ENV_BASE64` | 任意。サーバー上に `.env` がない場合に復元する `.env` の base64 文字列。 |

`HOSTINGER_ENV_BASE64` は PowerShell で以下のように作成できます。

```powershell
[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes((Get-Content -Raw .env)))
```

サーバー上の deploy path に `.env` が既にある場合、workflow は既存の `.env` を優先し、`HOSTINGER_ENV_BASE64` では上書きしません。

月次集計 Webhook の本番 URL は以下です。

```text
https://clb-biahoi.net/notion_ledger_sync/api/notion_webhook/monthly-sum
```

## テスト

```bash
php artisan test
```

SQLite を利用する場合はテスト実行前に `database/database.sqlite` を作成してください。
