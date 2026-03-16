# 入荷予定データ受信・照合機能 作業計画

## 前提

- 入荷予定（`wms_order_incoming_schedules`）に伝票番号カラムが存在しない
- JXファイル生成時に伝票番号を動的生成しているがDBに保存していない
- JX納品伝票は128バイト固定長・Shift_JIS。A/B/Dレコード構成
- Bレコード[4-14]の伝票番号（11桁: `YYYYMMDD+seq`）が照合キー
- 欠品判定は×マークではなく、伝票番号照合+数量比較で行う
- 受信データは全項目を3層テーブル（files/slips/details）に保存
- 入荷予定は3箇所で生成: 自動発注確定、手動発注、移動候補確定
- 既存データは全クリア済み（バックフィル不要）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | マイグレーション＆モデル | `slip_number` カラム追加、採番メソッド | migrate成功、tinkerで採番確認 |
| P2 | 全生成箇所に slip_number 適用 | 3つの入荷予定生成箇所で必須セット | 自動/手動/移動の全てで伝票番号が生成される |
| P3 | JXファイル生成で保存済み伝票番号使用 | 確定後はDB値を参照、確定前は従来通り | JXファイル内番号とDB値が一致 |
| P4 | UI表示＆検証 | Filamentテーブルに伝票番号カラム追加 | 一覧表示・検索OK、構文チェック成功 |
| P5 | 受信データテーブル＆モデル | 3層テーブル作成、モデル定義 | migrate成功、モデル関連OK |
| P6 | JX受信パーサー | 128バイト固定長パース、全項目DB保存 | サンプルデータのパース成功 |
| P7 | 照合サービス | slip_numberベースのマッチング、欠品判定 | 照合結果が正しくDBに記録される |
| P8 | 受信データ確認画面 | Filamentリソース、照合結果表示 | 一覧表示・照合・適用アクションが動作 |
| P9 | 発注先受信設定 | contractor_settings に受信カラム追加 | 設定画面で受信ON/OFF切替可能 |
| P10 | スケジューラー連携 | 自動受信コマンド、cron登録 | 定期受信が動作 |

---

## P1: マイグレーション＆モデル

### 目的

`wms_order_incoming_schedules` に `slip_number` カラムを追加。モデルに自動採番メソッドを追加。

### 伝票番号フォーマット

```
{YYYYMMDD}-{連番5桁}
例: 20260305-00001, 20260305-00002
```

- 日付: `order_date`（発注日）ベース
- 連番: 同日内でインクリメント（5桁、最大99999件/日）
- ユニーク制約あり

### 採番ロジック

```php
public static function generateSlipNumber(?string $orderDate = null): string
{
    $date = $orderDate ?? now()->format('Y-m-d');
    $dateStr = Carbon::parse($date)->format('Ymd');
    $prefix = $dateStr . '-';

    $maxSlip = self::where('slip_number', 'like', $prefix . '%')
        ->orderByRaw("CAST(SUBSTRING(slip_number, 10) AS UNSIGNED) DESC")
        ->value('slip_number');

    $nextSeq = $maxSlip ? (int) substr($maxSlip, 9) + 1 : 1;

    return $prefix . str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
}
```

### マイグレーション

```php
Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
    $table->string('slip_number', 20)->nullable()->after('order_source');
    $table->unique('slip_number');
});
```

### 修正対象

| ファイル | 変更 |
|---|---|
| `database/migrations/xxxx_add_slip_number_to_wms_order_incoming_schedules.php` | 新規 |
| `app/Models/WmsOrderIncomingSchedule.php` | fillable追加、`generateSlipNumber()` 追加 |

### 完了条件

1. `php artisan migrate` 成功
2. `generateSlipNumber()` がtinkerで正しい連番を返す
3. ユニーク制約が機能

---

## P2: 全生成箇所に slip_number 適用

### 修正対象

| ファイル | 箇所 | 変更 |
|---|---|---|
| `OrderExecutionService.php` | L166 | demand_breakdown有: `'slip_number' => WmsOrderIncomingSchedule::generateSlipNumber(...)` |
| `OrderExecutionService.php` | L187 | demand_breakdown無: 同上 |
| `OrderExecutionService.php` | L259 | 手動発注: 同上 |
| `TransferCandidateExecutionService.php` | L205 | 移動確定: 同上 |

### 注意点

- バッチ処理での連番重複防止: ループ内で毎回DB取得 or バッチ用ヘルパーで一括予約

### 完了条件

1. 構文チェック成功
2. 全3箇所で `slip_number` がセットされる
3. ユニーク制約違反が発生しない

---

## P3: JXファイル生成で保存済み伝票番号使用

### 方針

- **確定前（プレビュー）**: 従来通り動的生成（`YYYYMMDD + seq`）
- **確定後（実送信）**: DB保存済みの `slip_number` を使用

```php
private function generateBRecord($candidate, int $seq, ?string $slipNumber = null): string
{
    $slipNumber = $slipNumber ?? $orderDate->format('Ymd') . str_pad($seq, 3, '0', STR_PAD_LEFT);
    // ...
}
```

### 修正対象

| ファイル | 変更 |
|---|---|
| `HanaOrderJXFileGenerator.php` | `generateBRecord()` に `slipNumber` 引数追加、確定後はDB値使用 |

### 完了条件

1. JXファイル内の伝票番号とDB `slip_number` が一致
2. 128バイト固定長フォーマット維持
3. 既存テスト通過

---

## P4: UI表示＆検証

### 修正対象

| ファイル | 変更 |
|---|---|
| `WmsOrderIncomingSchedulesTable.php` | `slip_number` カラム追加、searchable |
| `WmsIncomingCompletedTable.php` | 同上 |
| `ListWmsOrderIncomingSchedules.php` | 手動入荷予定追加で `slip_number` 自動生成 |

### 完了条件

1. 一覧で伝票番号が表示・検索可能
2. 手動入荷予定追加時に自動付与
3. 構文チェック成功

---

## P5: 受信データテーブル＆モデル

### 目的

JX/CSV受信データを全項目保存する3層テーブルを作成。

### テーブル構成

| テーブル | 対応 | 役割 |
|---|---|---|
| `wms_incoming_received_files` | Aレコード | ファイル単位の管理 |
| `wms_incoming_received_slips` | Bレコード | 伝票単位、照合キー（`slip_number`） |
| `wms_incoming_received_details` | Dレコード | 明細単位、全項目保存 |

### 修正対象

| ファイル | 変更 |
|---|---|
| `database/migrations/xxxx_create_wms_incoming_received_tables.php` | 新規（3テーブル一括） |
| `app/Models/WmsIncomingReceivedFile.php` | 新規 |
| `app/Models/WmsIncomingReceivedSlip.php` | 新規 |
| `app/Models/WmsIncomingReceivedDetail.php` | 新規 |

### 完了条件

1. migrate成功
2. モデル間リレーション動作確認

---

## P6: JX受信パーサー

### 目的

128バイト固定長Shift_JISデータをパースし、3層テーブルに全項目保存。

### 処理フロー

1. FINETラッパー除去（`JxDataWrapper::hasHeader()`/`hasFooter()`で判定）
2. 128バイトずつレコード分割
3. レコード区分（A/B/D）で分岐、各フィールドをバイト位置で切り出し
4. Shift_JIS → UTF-8変換
5. 3層テーブルにINSERT

### 修正対象

| ファイル | 変更 |
|---|---|
| `app/Contracts/IncomingFormatParserInterface.php` | 新規 |
| `app/Services/AutoOrder/IncomingParsers/JxIncomingParser.php` | 新規 |
| `app/Services/AutoOrder/IncomingReceiveService.php` | 新規 |

### 完了条件

1. `real_data.txt`（FINETラッパーあり）のパース成功
2. 旧システムサンプル（FINETラッパーなし）のパース成功
3. 全項目がDBに正しく保存される

---

## P7: 照合サービス

### 照合ロジック

1. `wms_incoming_received_slips.slip_number` → `wms_order_incoming_schedules.slip_number` でマッチ
2. `wms_incoming_received_details.item_code` / `jan_code` → `items.code` で商品特定
3. 欠品判定:
   - 発注商品が納品データに存在しない → 欠品
   - 出荷数量0 → 欠品
   - 出荷数量 < 発注数量 → 一部欠品
4. 照合結果を `match_status` に記録

### 照合速度考慮

- `slip_number` にUNIQUEインデックス（入荷予定側）
- `slip_number` にインデックス（受信slips側）
- `item_code`, `jan_code` にインデックス（受信details側）

### 修正対象

| ファイル | 変更 |
|---|---|
| `app/Services/AutoOrder/IncomingReceiveService.php` | `matchWithSchedules()`, `applyMatched()` メソッド追加 |

### 完了条件

1. 伝票番号ベースの照合が正しく動作
2. 欠品判定が正しい
3. 照合結果がDBに記録される

---

## P8〜P10: 後続Phase（概要のみ）

### P8: 受信データ確認画面

- `admin/wms-incoming-received-data` — Filamentリソース
- 受信ファイル一覧、伝票単位の照合結果表示
- CSV手動アップロード、照合実行、一括適用アクション

### P9: 発注先受信設定

- `wms_contractor_settings` に受信カラム追加
- 発注先設定画面に「入荷データ受信設定」セクション追加

### P10: スケジューラー連携

- `wms:incoming-receive-scheduled` コマンド
- 受信曜日・時刻に基づく自動受信
- JX/FTPからデータ取得→パース→照合→DB保存

---

## 制約（厳守）

1. `migrate:fresh` / `migrate:refresh` 禁止
2. FK禁止
3. 既存の入庫フロー（Handy/Web手動）に影響を与えない
4. `slip_number` はユニーク制約
5. 受信データは自動適用しない（担当者確認必須）
6. 受信データの全項目を保存
7. 品名先頭「×」での欠品判定はしない（伝票番号照合+数量比較で判定）

## 全体完了条件

1. 全入荷予定に `slip_number` が付与される
2. JXファイルの伝票番号とDB値が一致
3. JX受信データが3層テーブルに全項目保存される
4. 伝票番号ベースの照合が正しく動作
5. 欠品が正しく検出される
6. 担当者確認画面で照合結果をレビュー・適用可能
7. 全ファイルの構文チェック成功
