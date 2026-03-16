# 入庫処理 API 仕様書

## ステータス: 完了 ✅

現在web viewの実装になっている入庫画面をandorid上でnative実装を実施したい。

## 要件

1. このプロジェクトはandoridからのAPIリクエストに対して対応するサーバ側
2. このプロジェクトはすでにAPIの認証機能を持っている。認証はこの機能を利用する
3. 現在実装されている。/handy/incomingの現在の各段階の作業を サーバ側はAPI化し、android側では動作用のviewとapi連携を作る。
4. /handy/incomingの各段階別のロジックを洗い出しAPIの作成
5. 作成されたAPIの仕様書を swaggerで作成
6. android handy側にAPIの仕様を伝えるpromptを作成
7. テスト時にＤＢのリフレッシュは決して行わないこと
8. 入庫用のデータはすでにDBに準備されているデータを利用

## 完了項目

### ✅ ブランチ作成
- `feature/incoming-api` ブランチを作成

### ✅ API 整理・作成
入庫処理に必要な全APIは既に実装済み（`app/Http/Controllers/Api/IncomingController.php`）:

| API | 説明 |
|-----|------|
| `POST /api/auth/login` | ログイン |
| `POST /api/auth/logout` | ログアウト |
| `GET /api/me` | 認証ユーザー情報取得 |
| `GET /api/master/warehouses` | 倉庫一覧取得 |
| `GET /api/incoming/schedules` | 入庫予定一覧取得 |
| `GET /api/incoming/schedules/{id}` | 入庫予定詳細取得 |
| `GET /api/incoming/work-items` | 作業データ一覧取得 |
| `POST /api/incoming/work-items` | 作業開始 |
| `PUT /api/incoming/work-items/{id}` | 作業データ更新 |
| `POST /api/incoming/work-items/{id}/complete` | 作業完了 |
| `DELETE /api/incoming/work-items/{id}` | 作業キャンセル |
| `GET /api/incoming/locations` | ロケーション検索 |

### ✅ Swagger仕様書
- `storage/api-docs/api-docs.json` に生成済み
- Swagger UI: `https://{server}/api/documentation`（要Filament認証）

### ✅ Android開発者向けプロンプト
- `storage/specifications/api/incoming-api-android-prompt.md`
- API使用方法、認証、画面フロー、データモデル、エラーハンドリングを解説

### ✅ Android UI/UX仕様書
- `storage/specifications/api/incoming-android-ui-specification.md`
- 画面構成、画面遷移、ボタン配置、キーボード操作、バーコードスキャン対応を解説

### ✅ APIテスト
- `tests/Feature/Api/IncomingApiTest.php`
- 全5テスト合格（15アサーション）
  - ✓ can list schedules
  - ✓ can get schedule details
  - ✓ can list work items
  - ✓ incoming workflow
  - ✓ cancel work item

## 成果物一覧

| ファイル | 説明 |
|---------|------|
| `app/Http/Controllers/Api/IncomingController.php` | 入庫API（既存） |
| `storage/api-docs/api-docs.json` | Swagger仕様書（JSON） |
| `storage/specifications/api/incoming-api-android-prompt.md` | Android開発者向けAPI解説 |
| `storage/specifications/api/incoming-android-ui-specification.md` | Android UI/UX仕様書 |
| `tests/Feature/Api/IncomingApiTest.php` | APIテスト |

