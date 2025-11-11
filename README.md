# Notion Ledger Sync API

Notion の家計簿データベースから特定月のレコードを取得し、口座別に金額を集計してメール・Slack に通知する Laravel 11 製の API です。

## 必要環境

- PHP 8.2 以上
- Composer

## セットアップ手順

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env` に以下の項目を設定してください。

```
# アプリケーション設定
APP_TIMEZONE=UTC

# Notion 接続
NOTION_API_TOKEN=
NOTION_DATA_SOURCE_ID=
# ※省略可。`NOTION_DATABASE_ID` を設定した場合はそちらから自動解決されます。
# 両方設定されている場合は `NOTION_DATA_SOURCE_ID` が優先されます。
NOTION_VERSION=2025-09-03

# Webhook 認証
WEBHOOK_TOKEN=

# メール通知
SYNC_REPORT_MAIL_TO=
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=notify@example.com
MAIL_FROM_NAME="Notion Monthly Sync"

# Slack 通知
SLACK_BOT=true
SLACK_BOT_TOKEN=
SLACK_DM_USER_IDS=
SLACK_UNFURL_LINKS=false
SLACK_UNFURL_MEDIA=false

## サブディレクトリ配下にデプロイする場合

WordPress などと同じドキュメントルートを共有しながら `/api/notion_webform` や `/api/notion_webhook` のようなサブディレクトリ配下に配置する場合は、以下の 2 点を追加で設定します。

1. `.env` に `APP_URL_PREFIX=api/notion_webform` を設定します。末尾のスラッシュは不要です。
2. `public_html/.htaccess` に次のリライトルールを記述し、`/api/notion_webform` と `/api/notion_webhook` へのアクセスをそれぞれの `public/index.php` に振り向けます。アプリを配置した実ディレクトリ名（以下の例では `api/notion`）に合わせて書き換えてください。

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

   各ルールで `/api/notion_webform` および `/api/notion_webhook` へのアクセスを確実に `index.php` に渡し、その配下のリクエストをパス情報付きで中継します。アプリを `public_html/api/notion` に配置している場合は上記のように `RewriteRule` の書き換え先を `api/notion` に変更し、URL だけを `notion_webform` や `notion_webhook` として公開できます。

## 利用方法

Notion オートメーションなどから下記のリクエストを送信します。

```
POST /api/notion_webhook/monthly-sum
ヘッダー: X-Webhook-Token: <WEBHOOK_TOKEN>
ボディ: {"year_month":"YYYY-MM"} ※省略時は現在の年月を使用（APP_TIMEZONE のタイムゾーンで判定）
```

API は指定した年月のレコードを Notion API (バージョン 2025-09-03) で取得し、口座ごとの合計値・総合計・件数を集計します。設定に応じてメールと Slack への通知も実施します。

## テスト

```bash
php artisan test
```
