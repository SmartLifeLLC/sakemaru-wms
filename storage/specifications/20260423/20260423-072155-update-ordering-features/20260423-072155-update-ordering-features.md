# 発注関連機能アップデート

- **作成日**: 2026-04-23
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260423/20260423-072155-update-ordering-features/`

## 背景・目的

発注候補（`wms-order-candidates`）・倉庫間移動候補（`wms-stock-transfer-candidates`）・発注データファイル（`wms-order-data-files`）画面において、以下の運用上の課題を解決する:

1. 「発注点」と「安全在庫」の名称が混在しており、ユーザーが混乱する
2. 発注計算に使われるパラメータ（発注点・発注CD）を詳細モーダルから直接変更できない
3. 生成元カラム（`origin_type`）が英語ラベルで表示されている
4. 検索CDと発注CDの名称が不統一
5. 発注データファイルの一括操作（メール・CSV・FAX）ができない

## 現状の実装

### 発注点 / 安全在庫の関係

- `item_contractors.safety_stock` = 発注点 = 安全在庫（**同一の概念**）
- `WmsMonthlySafetyStock` で月別の安全在庫を管理し、日次バッチで `item_contractors.safety_stock` に同期
- 発注候補生成時に `item_contractors.safety_stock` をスナップショットとして `wms_order_candidates.safety_stock` に保存
- 計算ログ（`wms_order_calculation_logs.calculation_details`）では `'安全在庫'` キーで保存
- Blade詳細モーダルでは `'発注点'` ラベルで表示
- **結論: 同一データだが名称が不統一 → 「発注点」に統一する**

### 発注CD（ordering_code / search_code）

- `item_search_information` テーブルで `is_used_for_ordering=true` かつ `is_active=true` のレコードの `search_string` が発注用コード
- `wms_order_candidates` テーブル: `ordering_code`（13桁ゼロパディング）と `search_code`（元の文字列）の両方を保持
- `wms_stock_transfer_candidates` テーブル: `search_code` のみ保持（`ordering_code` カラムなし）
- 候補テーブルにはスナップショットとして保存済み（生成時に `item_search_information` から取得）
- stock transfer candidates テーブルでは `search_code` のラベルが「検索CD」→「発注CD」に変更が必要

### origin_type の表示

- `OriginType` enum に `label()` メソッドあり（日本語ラベル定義済み）
- `WmsOrderCandidatesTable` の `origin_type` カラムで `formatStateUsing()` が未設定 → 英語の enum 値がそのまま表示
- `WmsStockTransferCandidatesTable` には `origin_type` カラム自体が未表示

### 発注データファイル

- 個別レコードアクション: `downloadCsv`, `downloadFax`, `sendMail` が既存
- バルクアクションは未実装
- `is_mail_order` カラムは未存在
- メールアドレスは `WmsContractorSetting.order_mail` に保存

---

## 変更内容

### 概要

6つのサブタスクに分割。発注点名称統一・編集機能追加、ラベル修正、発注CD変更機能、発注データファイルのバルク操作・メール送信方式カラム追加を実装する。

---

### 1.1 発注点の名称統一 + 詳細モーダルでの編集機能

#### 名称統一

- 計算ログの `'安全在庫'` キーは変更しない（既存データとの互換性維持）
- UI表示は全て「発注点」に統一（現状の詳細Bladeは既に「発注点」表示 → 変更不要）
- テーブルカラムの `safety_stock` ラベルも確認（現状「発注点」→ 変更不要）

#### 詳細モーダルでの発注点編集

**対象画面**: 発注候補詳細モーダル / 移動候補詳細モーダル（PENDINGステータス時のみ）

既存の編集フォーム（ケース数/バラ数/入荷予定日）に「発注点」入力フィールドを追加:

```php
// WmsOrderCandidatesTable.php の viewCalculation スキーマ
TextInput::make('safety_stock')
    ->label('発注点')
    ->numeric()
    ->required()
    ->minValue(0),
```

**保存処理**:
- 「変更を保存」ボタン押下時に `safety_stock` の変更を検出
- 変更があれば:
  1. `wms_order_candidates.safety_stock` を更新（スナップショット値）
  2. `wms_monthly_safety_stocks` の該当月レコードを更新（マスタ値）
    - キー: `(item_id, warehouse_id, contractor_id, month=現在月)`
    - 存在しない場合は新規作成（`updateOrCreate`）
  3. `is_manually_modified = true`, `modified_by`, `modified_at` を設定
- 倉庫別の設定が変わる（`wms_monthly_safety_stocks` は `warehouse_id` を持つ）

**fillForm に追加**:
```php
->fillForm(fn($record) => [
    'case_quantity' => $record->case_quantity,
    'piece_quantity' => $record->piece_quantity,
    'expected_arrival_date' => $record->expected_arrival_date,
    'safety_stock' => $record->safety_stock,  // 追加
])
```

**移動候補（WmsStockTransferCandidatesTable）も同様に対応**。

---

### 1.2 origin_type の日本語ラベル表示

**対象**: `WmsOrderCandidatesTable.php` の `origin_type` カラム

```php
// 修正前
TextColumn::make('origin_type')
    ->label('生成元')
    ->badge()
    ->color(fn($record) => $record->origin_type?->color() ?? 'gray')

// 修正後
TextColumn::make('origin_type')
    ->label('生成元')
    ->badge()
    ->formatStateUsing(fn(OriginType $state): string => $state->label())
    ->color(fn($record) => $record->origin_type?->color() ?? 'gray')
```

**フィルターも確認**: `SelectFilter::make('origin_type')->options(OriginType::class)` — Filament 4 で enum の `label()` が自動的に使われるか要確認。使われない場合は明示的にオプションを定義。

---

### 1.3 検索CD → 発注CD ラベル変更

**対象**: `WmsStockTransferCandidatesTable.php`

```php
// 修正前（92行目）
TextColumn::make('search_code')
    ->label('検索CD')

// 修正後
TextColumn::make('search_code')
    ->label('発注CD')
```

---

### 1.4 発注CDの取得元確認

**確認結果**: 発注CDは `item_search_information.is_used_for_ordering` を使用している（`can_use_for_ordering` ではない）。

以下の箇所で一貫して `is_used_for_ordering=true` AND `is_active=true` で取得:
- `OrderCandidateCalculationService.php` (L375, L545)
- `SalesBasedOrderCandidateService.php` (L247, L375)
- `OrderExecutionService.php` (L362)
- `TransferCreateJobHandler.php` (L381)
- `ListWmsOrderCandidates.php` (L73, L228)

→ **変更不要。正しく実装されている。**

---

### 1.5 詳細モーダルでの発注CD変更機能

**対象画面**: 発注候補詳細モーダル / 移動候補詳細モーダル（PENDINGステータス時のみ）

#### 動作仕様

1. モーダルオープン時に、該当商品の `item_search_information` から全コードを取得:
   ```php
   $codes = DB::connection('sakemaru')
       ->table('item_search_information')
       ->where('item_id', $record->item_id)
       ->where('is_active', true)
       ->select('id', 'search_string', 'code_type', 'is_used_for_ordering')
       ->get();
   ```

2. セレクトボックスで表示（現在の発注CDをデフォルト選択）:
   ```php
   Select::make('ordering_code')
       ->label('発注CD')
       ->options($codeOptions)  // [search_string => "[code_type] search_string (発注用)"] 形式
       ->default($record->ordering_code ?? $record->search_code)
       ->searchable()
       ->helperText('この商品に登録されている検索コードから発注CDを選択')
   ```

3. 「変更を保存」押下時:
   - `wms_order_candidates.ordering_code` を更新（13桁ゼロパディング）
   - `wms_order_candidates.search_code` を更新（元の文字列）
   - `wms_stock_transfer_candidates.search_code` を更新（移動候補の場合）
   - **候補テーブルのスナップショットのみ更新。`item_search_information.is_used_for_ordering` フラグは変更しない。**
   - `is_manually_modified = true` を設定

#### 移動候補テーブルへの `ordering_code` カラム追加

`wms_stock_transfer_candidates` には `ordering_code` カラムが存在しないため、マイグレーションで追加:

```php
Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
    $table->string('ordering_code', 13)->nullable()->after('search_code');
});
```

既存データのバックフィル:
```sql
UPDATE wms_stock_transfer_candidates c
JOIN item_search_information si ON c.item_id = si.item_id
  AND si.is_used_for_ordering = 1
  AND si.is_active = 1
SET c.ordering_code = LPAD(si.search_string, 13, '0')
WHERE c.ordering_code IS NULL;
```

---

### 1.6 発注データファイルのバルク操作 + メール送信方式カラム

#### DB変更

**マイグレーション: `wms_order_data_files` に `is_mail_order` カラム追加**

```php
Schema::connection('sakemaru')->table('wms_order_data_files', function (Blueprint $table) {
    $table->boolean('is_mail_order')->default(false)->after('total_quantity');
});
```

- `is_mail_order = true`: 発注先にメールアドレスが設定されている → メール送信
- `is_mail_order = false`: メールアドレス未設定 or JX送信 → 手動送信（デフォルト）
- **データ生成時**（`OrderDataFileService` または `OrderExecutionService`）に `WmsContractorSetting.order_mail` の有無で判定して保存

**既存データのバックフィル**:
```sql
UPDATE wms_order_data_files f
JOIN wms_contractor_settings cs ON f.contractor_id = cs.contractor_id
SET f.is_mail_order = CASE
    WHEN cs.order_mail IS NOT NULL AND cs.order_mail != '' THEN 1
    ELSE 0
END;
```

#### テーブルカラム追加

`total_quantity` カラムの右側に「送信方式」カラムを追加:

```php
TextColumn::make('is_mail_order')
    ->label('送信方式')
    ->badge()
    ->formatStateUsing(fn (bool $state): string => $state ? 'メール送信' : '手動送信')
    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
    ->alignCenter(),
```

#### バルクアクション

3つのバルクアクションを追加: **メール一括送信**, **CSV一括ダウンロード**, **FAX一括ダウンロード**

##### メール一括送信（`bulkSendMail`）

```php
BulkAction::make('bulkSendMail')
    ->label('メール一括送信')
    ->icon('heroicon-o-envelope')
    ->color('warning')
    ->requiresConfirmation()
    ->modalHeading('選択した発注データをメールで一括送信')
    ->modalDescription(fn (Collection $records) => /* 送信対象件数と除外件数を表示 */)
    ->action(function (Collection $records) {
        // 1. メール送信済みレコードを除外（二重送信防止）
        // 2. is_mail_order=false のレコードを除外（メールアドレス未設定）
        // 3. 残りの対象レコードに対して順次メール送信
        //    - WmsContractorSetting からメールテンプレート取得
        //    - 変数置換（replaceMailVariables）
        //    - FAX PDF生成 → メール送信
        //    - markAsMailSent()
        // 4. 結果通知: 送信成功/スキップ/失敗の件数
    })
```

**ビジネスルール**:
- `mail_sent_at` が既にセットされているレコードは送信スキップ（**二重送信禁止**）
- `is_mail_order = false`（メールアドレス未設定）のレコードは送信スキップ
- スキップされたレコードの `mail_sent_at` は更新しない
- 送信完了時に `mail_sent_at` を更新
- 文言入力はスキップ（テンプレートから自動生成）

##### CSV一括ダウンロード（`bulkDownloadCsv`）

```php
BulkAction::make('bulkDownloadCsv')
    ->label('CSV一括ダウンロード')
    ->icon('heroicon-o-document-text')
    ->color('primary')
    ->action(function (Collection $records) {
        // 選択レコードのCSVファイルをZIPにまとめてダウンロード
        // 各レコードの csv_downloaded_at を更新
    })
```

##### FAX一括ダウンロード（`bulkDownloadFax`）

```php
BulkAction::make('bulkDownloadFax')
    ->label('FAX一括ダウンロード')
    ->icon('heroicon-o-document')
    ->color('success')
    ->action(function (Collection $records) {
        // 選択レコードのFAX PDFを生成・ZIPにまとめてダウンロード
        // 通信欄は空（一括のため個別入力なし）
        // 各レコードの fax_downloaded_at を更新
    })
```

#### 発注先設定画面のメールアドレス注意書き

**対象**: `ContractorForm.php` の `wms_order_mail` フィールド

```php
TextInput::make('wms_order_mail')
    ->label('発注先メールアドレス')
    ->email()
    ->helperText('このメールアドレスが登録されていると、メール送信発注先になります。')
```

---

## 影響範囲

### 直接変更

| ファイル | 変更内容 |
|---------|---------|
| `WmsOrderCandidatesTable.php` | origin_type ラベル修正, 詳細モーダルに発注点・発注CD編集追加 |
| `WmsStockTransferCandidatesTable.php` | 検索CD→発注CD ラベル変更, 詳細モーダルに発注点・発注CD編集追加 |
| `WmsOrderDataFilesTable.php` | 送信方式カラム追加, バルクアクション3種追加 |
| `ContractorForm.php` | メールアドレスにhelperText追加 |
| `WmsOrderDataFile.php` | `is_mail_order` カラム追加 |
| `WmsStockTransferCandidate.php` | `ordering_code` fillable追加 |

### データ生成に影響

| ファイル | 変更内容 |
|---------|---------|
| `OrderDataFileService.php` or `OrderExecutionService.php` | データ生成時に `is_mail_order` を設定 |
| `TransferCreateJobHandler.php` | `ordering_code` を候補生成時に保存 |
| `OrderCreateJobHandler.php` | 確認（既に `ordering_code` 設定済みのはず） |

### マイグレーション

| マイグレーション | 内容 |
|---------------|------|
| `add_ordering_code_to_wms_stock_transfer_candidates` | `ordering_code` VARCHAR(13) nullable + バックフィル |
| `add_is_mail_order_to_wms_order_data_files` | `is_mail_order` BOOLEAN default false + バックフィル |

---

## 制約

1. **FK禁止**: `item_search_information` への外部キーは作成しない
2. **migrate:fresh/refresh 禁止**: `php artisan migrate` のみ使用
3. **二重送信禁止**: メール送信済み（`mail_sent_at` 非null）のレコードへの再送信をブロック
4. **マスタ直接変更の範囲限定**: 発注点変更は `wms_monthly_safety_stocks` のみ。`item_contractors.safety_stock` は日次バッチで同期されるため直接変更しない
5. **発注CD変更はスナップショットのみ**: `item_search_information.is_used_for_ordering` フラグは変更しない（マスタ影響を避ける）
6. **JX送信先は is_mail_order=false**: JX/FTP送信タイプの発注先はデフォルトで手動送信扱い

---

## 対象ファイル

### 新規作成

- `database/migrations/XXXX_add_ordering_code_to_wms_stock_transfer_candidates_table.php`
- `database/migrations/XXXX_add_is_mail_order_to_wms_order_data_files_table.php`

### 既存変更

- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`
- `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php`
- `app/Filament/Resources/Contractors/Schemas/ContractorForm.php`
- `app/Models/WmsOrderDataFile.php`
- `app/Models/WmsStockTransferCandidate.php`
- `app/Services/AutoOrder/OrderDataFileService.php` or `OrderExecutionService.php`（is_mail_order 設定）
- `app/Services/AutoOrder/TransferCreateJobHandler.php`（ordering_code 保存）

### 参照のみ

- `app/Enums/AutoOrder/OriginType.php`（変更不要、`label()` メソッド確認済み）
- `app/Models/WmsMonthlySafetyStock.php`（発注点更新のため参照）
- `app/Models/Sakemaru/ItemSearchInformation.php`（発注CD一覧取得のため参照）
- `app/Models/WmsContractorSetting.php`（メールアドレス確認のため参照）
- `app/Services/AutoOrder/OrderCandidateCalculationService.php`（発注点・発注CD取得ロジック確認）
- `resources/views/filament/components/order-candidate-detail.blade.php`（名称確認済み、変更不要）
- `resources/views/filament/components/transfer-candidate-detail.blade.php`（名称確認済み、変更不要）

---

## 確認事項

1. **発注点変更のスコープ**: 詳細モーダルで発注点を変更した場合、`wms_monthly_safety_stocks` の当月レコードのみ更新する想定。全月一括変更の必要はあるか？
当月分のみ。
2. **バルクCSV/FAXダウンロード**: ZIPファイルとして一括ダウンロードする想定だが、サーバーサイドでのZIP生成は許容されるか？代替としてS3の一括ダウンロードURLを使用するか？
FAXはひとつのPDF化できるか？CSVは一つにファイルにまとめる。
3. **メール一括送信時のFAX PDF**: 個別送信時は通信欄を入力可能だが、一括送信時は通信欄空で自動生成する想定。これでよいか？
通信欄何も書かないように。
4. **発注CD変更時の再計算**: 発注CDを変更した場合、発注数量の再計算は不要か？（CDはファイル出力用であり、数量計算に影響しない想定）
不要
5. **is_mail_order の判定タイミング**: データ生成時に `WmsContractorSetting.order_mail` の有無で判定して保存する。発注先のメール設定が後から変更された場合、既存レコードの `is_mail_order` は更新しない想定でよいか？
更新しない。
