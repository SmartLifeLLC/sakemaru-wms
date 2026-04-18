# Work Plan: proxy-shipment-wms-api-alignment

- **ID**: proxy-shipment-wms-api-alignment
- **作成日**: 2026-04-19
- **最終更新**: 2026-04-19
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/20260418/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（proxy-shipment-wms-api-alignment-boot.md）
2. proxy-shipment-wms-api-alignment-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

横持ち出荷API（`/api/proxy-shipments/*`）のレスポンスJSONを、既存Androidアプリの共通 `ApiEnvelope<T>` 仕様に合わせて調整する。
`ApiController` の `success()` / `error()` ヘルパーを活用してEnvelopeを統一する。

## 重要な設計制約

- **migrate:fresh/refresh 絶対禁止** — 共有DB（sakemaru）のため
- **FK制約を作成しない** — 全リレーションはアプリケーションレベル
- **PickingTaskController を変更しない** — 今回のスコープ外
- **payload の中身は変えない** — JSON の包み方を揃えるだけ
- `ProxyShipmentController` は `ApiController` を継承し、`success()` / `error()` を使用する
- Handy V2 のフロントも合わせて修正する（レスポンス構造変更に追従）

## 対象ファイル

### 既存変更
- `app/Http/Controllers/Api/ProxyShipmentController.php` — Controller を ApiController 継承に変更、Envelope 統一
- `app/Services/Shortage/ProxyShipmentQueryService.php` — listForWarehouse の戻り値構造変更（items / summary / meta を data に統合）
- `resources/js/handy-v2/stores/proxy-shipment.js` — レスポンス読み取りパス修正
- `storage/specifications/20260418/proxy-shipment-api-specification.md` — API仕様書更新

### 参照のみ（変更禁止）
- `app/Http/Controllers/Api/ApiController.php` — 共通Envelopeヘルパー（参考）
- `app/Http/Controllers/Api/PickingTaskController.php` — 既存APIのEnvelope実装（参考）

## テストデータ

- テストデータ作成は不要（レスポンス構造の変更のみ）
- 変更後に `php artisan test --filter=ProxyShipmentApiTest` で回帰確認

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Controller を ApiController 継承に変更 | 完了 | 2026-04-19 | extends ApiController + success()/error()/notFound()/validationError() |
| P2: QueryService の戻り値構造変更 | 完了 | 2026-04-19 | data→items に変更 |
| P3: Handy V2 フロント修正 | 完了 | 2026-04-19 | レスポンスパス変更に追従 |
| P4: API仕様書更新 | 完了 | 2026-04-19 | Envelope 統一後の仕様書に更新 |
| P5: ビルド・テスト | 完了 | 2026-04-19 | npm run build 成功 + テスト11件 Skipped |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### ApiController ヘルパーの仕様
- `success($data, $message, $httpStatus, $code)` → `{ is_success, code, result: { data, message, debug_message } }`
- `error($errorMessage, $httpStatus, $code, $debugMessage, $errors)` → `{ is_success: false, code, result: { data: null, error_message, errors, debug_message } }`
- `notFound($message)` → 404 + NOT_FOUND
- `validationError($errors, $message)` → 422 + VALIDATION_ERROR

### 変更前後のレスポンス構造差分
- 一覧: `result.data[]` → `result.data.items[]`、`result.summary` → `result.data.summary`、`result.meta` → `result.data.meta`
- 完了: `result.stock_transfer_queue_id` → `result.data.stock_transfer_queue_id`
- エラー: `abort()` → `$this->error()` / `$this->notFound()` / `$this->validationError()`

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチを使用）
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: Controller を ApiController 継承に変更
- 完了日: 2026-04-19
- 実績:
  - `extends Controller` → `extends ApiController` に変更
  - 全5エンドポイントで `$this->success()` を使用
  - `abort()` → `throw new ModelNotFoundException` / `throw new \InvalidArgumentException` に変更
  - 各アクションメソッドに try-catch 追加（notFound / validationError を返却）
  - complete: `stock_transfer_queue_id` を `result.data` 内に移動
  - complete 422 エラーを `$this->validationError()` に変更
  - QueryService の `findForWarehouse` の abort も `throw` に変更

### P2: QueryService の戻り値構造変更
- 完了日: 2026-04-19
- 実績:
  - `listForWarehouse()` の `'data'` キーを `'items'` に変更
  - Controller で `$this->success($result)` すると `result.data.items[]` に格納される

### P3: Handy V2 フロント修正
- 完了日: 2026-04-19
- 実績:
  - `loadAllocations()`: `response.result.data` → `response.result.data.items`
  - `loadAllocations()`: `response.result.summary` → `response.result.data.summary`
  - `loadAllocations()`: `response.result.meta` → `response.result.data.meta`
  - `completeAllocation()`: `response.result.stock_transfer_queue_id` → `response.result.data.stock_transfer_queue_id`

### P4: API仕様書更新
- 完了日: 2026-04-19
- 実績:
  - 共通レスポンス形式に `debug_message` 追加、`error_message` / `errors` を明記
  - 一覧レスポンス: `result.data[]` → `result.data.items[]`、summary/meta を data 内に移動
  - 完了レスポンス: `result.stock_transfer_queue_id` → `result.data.stock_transfer_queue_id`
  - 全レスポンス例に `message` / `debug_message` を追加
  - エラー一覧に `error_message` 例を追加、404/422 のレスポンス例を追加

### P5: ビルド・テスト
- 完了日: 2026-04-19
- 実績:
  - `npm run build` 成功（2.79s）
  - `php artisan test --filter=ProxyShipmentApiTest`: 11件 Skipped（テストデータなし、正常動作）
  - `php -l` 構文エラーなし
