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

WordPress などと同じドキュメントルートを共有しながら `/api/notion` のようなサブディレクトリ配下に配置する場合は、以下の 2 点を追加で設定します。

1. `.env` に `APP_URL_PREFIX=api/notion` を設定します。末尾のスラッシュは不要です。
2. `public_html/.htaccess` に次のリライトルールを記述し、`/api/notion` へのアクセスを `public/index.php` に振り向けます。

   ```apacheconf
   # 既存のファイルやディレクトリはそのまま扱う
   RewriteCond %{REQUEST_FILENAME} -f [OR]
   RewriteCond %{REQUEST_FILENAME} -d
   RewriteRule ^ - [L]

   # /api/notion 直下とそれ以外でルールを分け、末尾スラッシュによる 403 を回避
   RewriteRule ^api/notion$ /api/notion/public/index.php [L,QSA]
   RewriteRule ^api/notion/(.+)$ /api/notion/public/index.php/$1 [L,QSA]
   ```

   最初のルールで `/api/notion` へのアクセスを確実に `index.php` に渡し、2 行目で `/api/notion/...` の形のリクエストをパス情報付きで中継します。

## 利用方法

Notion オートメーションなどから下記のリクエストを送信します。

```
POST /api/notion/monthly-sum
ヘッダー: X-Webhook-Token: <WEBHOOK_TOKEN>
ボディ: {"year_month":"YYYY-MM"} ※省略時は現在の年月を使用（APP_TIMEZONE のタイムゾーンで判定）
```

API は指定した年月のレコードを Notion API (バージョン 2025-09-03) で取得し、口座ごとの合計値・総合計・件数を集計します。設定に応じてメールと Slack への通知も実施します。

## テスト

```bash
php artisan test
```
