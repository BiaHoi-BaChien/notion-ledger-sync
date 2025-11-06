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
# Notion 接続
NOTION_API_TOKEN=
NOTION_DATABASE_ID=
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

# レスポンスキーのカスタマイズ（任意）
RESPONSE_KEY_YEAR_MONTH=year_month
RESPONSE_KEY_RANGE=range
RESPONSE_KEY_RANGE_START=start
RESPONSE_KEY_RANGE_END=end
RESPONSE_KEY_TOTALS=totals
RESPONSE_KEY_TOTAL_ALL=total_all
RESPONSE_KEY_RECORDS_COUNT=records_count
RESPONSE_KEY_NOTIFIED=notified
RESPONSE_KEY_NOTIFIED_MAIL=mail
RESPONSE_KEY_NOTIFIED_SLACK=slack
```

## 利用方法

Notion オートメーションなどから下記のリクエストを送信します。

```
POST /api/notion/monthly-sum
ヘッダー: X-Webhook-Token: <WEBHOOK_TOKEN>
ボディ: {"year_month":"YYYY-MM"}
```

API は指定した年月のレコードを Notion API (バージョン 2025-09-03) で取得し、口座ごとの合計値・総合計・件数を JSON として返します。設定に応じてメールと Slack への通知も実施します。レスポンスの各項目名は環境変数で変更できます。

## テスト

```bash
php artisan test
```
