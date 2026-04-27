# 発注関連機能アップデート 作業計画

## 前提

- 仕様書: `20260423-072155-update-ordering-features.md`
- ブランチ: `release/v1.0` から作業
- 確認事項5件: ユーザー回答済み（boot.md「確認済み仕様決定」参照）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | マイグレーション | `ordering_code` / `is_mail_order` カラム追加 | `php artisan migrate` 成功、バックフィル完了 |
| P1 | ラベル修正 | origin_type 日本語化 + 検索CD→発注CD | ブラウザで表示確認 |
| P2 | 発注点編集機能 | 詳細モーダルで発注点を編集・保存 | PENDING候補の発注点変更→wms_monthly_safety_stocks更新確認 |
| P3 | 発注CD変更機能 | 詳細モーダルで発注CDをセレクト変更 | 候補レコードのordering_code/search_code更新確認 |
| P4 | 発注データファイルバルク操作 | 送信方式カラム+バルクアクション3種 | メール一括送信・CSV/FAX一括DL動作確認 |
| P5 | データ生成ロジック更新 | is_mail_order設定 + ordering_code保存 | 候補生成→ファイル生成フローで正しく値が入る |

---

## P0: マイグレーション

### 目的

後続Phase（P3〜P5）で必要なDBカラムを先に追加する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `database/migrations/XXXX_add_ordering_code_to_wms_stock_transfer_candidates_table.php` | 新規作成 |
| `database/migrations/XXXX_add_is_mail_order_to_wms_order_data_files_table.php` | 新規作成 |
| `app/Models/WmsStockTransferCandidate.php` | `ordering_code` を `$fillable` に追加 |
| `app/Models/WmsOrderDataFile.php` | `is_mail_order` を `$fillable` と `$casts` に追加 |

### 修正手順

#### 1. `wms_stock_transfer_candidates` に `ordering_code` 追加

```bash
php artisan make:migration add_ordering_code_to_wms_stock_transfer_candidates_table
```

マイグレーション内容:
```php
public function up(): void
{
    Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
        $table->string('ordering_code', 13)->nullable()->after('search_code');
    });

    // バックフィル: item_search_information から is_used_for_ordering=true のコードを取得
    DB::connection('sakemaru')->statement('
        UPDATE wms_stock_transfer_candidates c
        JOIN item_search_information si ON c.item_id = si.item_id
          AND si.is_used_for_ordering = 1
          AND si.is_active = 1
        SET c.ordering_code = LPAD(si.search_string, 13, \'0\')
        WHERE c.ordering_code IS NULL
    ');
}
```

#### 2. `wms_order_data_files` に `is_mail_order` 追加

```bash
php artisan make:migration add_is_mail_order_to_wms_order_data_files_table
```

マイグレーション内容:
```php
public function up(): void
{
    Schema::connection('sakemaru')->table('wms_order_data_files', function (Blueprint $table) {
        $table->boolean('is_mail_order')->default(false)->after('total_quantity');
    });

    // バックフィル: contractor_settings の order_mail 有無で判定
    DB::connection('sakemaru')->statement('
        UPDATE wms_order_data_files f
        JOIN wms_contractor_settings cs ON f.contractor_id = cs.contractor_id
        SET f.is_mail_order = CASE
            WHEN cs.order_mail IS NOT NULL AND cs.order_mail != \'\' THEN 1
            ELSE 0
        END
    ');
}
```

#### 3. モデル更新

**`WmsStockTransferCandidate.php`**: `$fillable` 配列に `'ordering_code'` を追加

**`WmsOrderDataFile.php`**: `$fillable` に `'is_mail_order'` を追加、`$casts` に `'is_mail_order' => 'boolean'` を追加

#### 4. 実行

```bash
php artisan migrate
```

### 完了条件

- `php artisan migrate` が成功
- `wms_stock_transfer_candidates` テーブルに `ordering_code` カラムが存在
- `wms_order_data_files` テーブルに `is_mail_order` カラムが存在
- バックフィルにより既存データに値が入っている

---

## P1: ラベル修正

### 目的

origin_type カラムの英語ラベルを日本語化し、検索CD→発注CDのラベル変更を行う。（仕様 1.2, 1.3）

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` | origin_type に `formatStateUsing` 追加 |
| `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` | search_code ラベル「検索CD」→「発注CD」 |

### 修正手順

#### 1. WmsOrderCandidatesTable.php — origin_type 日本語化

現在（約398行目）:
```php
TextColumn::make('origin_type')
    ->label('生成元')
    ->badge()
    ->color(fn($record) => $record->origin_type?->color() ?? 'gray')
```

修正後:
```php
TextColumn::make('origin_type')
    ->label('生成元')
    ->badge()
    ->formatStateUsing(fn(OriginType $state): string => $state->label())
    ->color(fn($record) => $record->origin_type?->color() ?? 'gray')
```

**フィルターの確認**: `SelectFilter::make('origin_type')->options(OriginType::class)` — Filament 4 が enum の `label()` を自動で使うか確認。使われない場合:
```php
SelectFilter::make('origin_type')
    ->label('生成元')
    ->options(collect(OriginType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
```

#### 2. WmsStockTransferCandidatesTable.php — 検索CD → 発注CD

現在（約92行目）:
```php
TextColumn::make('search_code')
    ->label('検索CD')
```

修正後:
```php
TextColumn::make('search_code')
    ->label('発注CD')
```

### 完了条件

- 発注候補テーブルの「生成元」カラムが日本語で表示される（例: 「自動発注（安全在庫）」）
- 移動候補テーブルの「検索CD」が「発注CD」に変わっている
- フィルターの選択肢も日本語で表示される

---

## P2: 発注点編集機能

### 目的

発注候補・移動候補の詳細モーダルで発注点（safety_stock）を編集可能にし、保存時に `wms_monthly_safety_stocks` の当月レコードも更新する。（仕様 1.1）

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` | viewCalculation モーダルに発注点フィールド追加 + 保存ロジック |
| `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` | edit モーダルに発注点フィールド追加 + 保存ロジック |

### 修正手順

#### 1. WmsOrderCandidatesTable.php — viewCalculation アクション

**fillForm に追加**（約454行目）:
```php
->fillForm(fn($record) => [
    'case_quantity' => $record->case_quantity,
    'piece_quantity' => $record->piece_quantity,
    'expected_arrival_date' => $record->expected_arrival_date,
    'safety_stock' => $record->safety_stock,  // 追加
])
```

**schema に追加**（約537-555行目、`$isEditable` ブロック内）:

Grid を 3→4 カラムに変更するか、発注点フィールドを別のGrid行として追加:
```php
if ($isEditable) {
    $schema[] = Grid::make(4)->schema([
        TextInput::make('case_quantity')
            ->label('発注ケース')
            ->numeric()
            ->required()
            ->minValue(0)
            ->disabled($capacityCase <= 1),

        TextInput::make('piece_quantity')
            ->label('発注バラ')
            ->numeric()
            ->required()
            ->minValue(0),

        DatePicker::make('expected_arrival_date')
            ->label('入荷予定日')
            ->required(),

        TextInput::make('safety_stock')
            ->label('発注点')
            ->numeric()
            ->required()
            ->minValue(0),
    ]);
}
```

**action（保存ロジック）に発注点更新を追加**（約559行目以降）:

既存の保存ロジックに発注点変更検出と `wms_monthly_safety_stocks` 更新を追加:
```php
// 発注点変更の検出と保存
if (isset($data['safety_stock']) && (int) $data['safety_stock'] !== (int) $record->safety_stock) {
    $newSafetyStock = (int) $data['safety_stock'];

    // 候補レコードのスナップショット更新
    $record->safety_stock = $newSafetyStock;

    // wms_monthly_safety_stocks の当月レコードを更新
    WmsMonthlySafetyStock::updateOrCreate(
        [
            'item_id' => $record->item_id,
            'warehouse_id' => $record->warehouse_id,
            'contractor_id' => $record->contractor_id,
            'month' => now()->month,
        ],
        [
            'safety_stock' => $newSafetyStock,
        ]
    );
}
```

※ `use App\Models\WmsMonthlySafetyStock;` の import を追加すること。

#### 2. WmsStockTransferCandidatesTable.php — edit アクション

同様のパターンで発注点フィールドと保存ロジックを追加。

**注意**: 移動候補の場合、`contractor_id` が nullable のケースがある。`WmsMonthlySafetyStock::updateOrCreate` のキーで `contractor_id` が null の場合の挙動を確認すること。null の場合はスナップショット更新のみ（マスタ更新スキップ）とする。

#### 3. 保存ロジックの共通化検討

発注候補と移動候補で保存ロジックが重複するが、無理に共通化せず各テーブルファイル内にインラインで実装する（YAGNI原則）。

### 完了条件

- PENDING状態の発注候補詳細モーダルに「発注点」入力フィールドが表示される
- 発注点を変更して「変更を保存」→ `wms_order_candidates.safety_stock` が更新される
- 同時に `wms_monthly_safety_stocks` の当月レコードが更新される（新規作成 or 更新）
- PENDING以外（APPROVED等）では発注点フィールドが非表示
- 移動候補でも同様に動作する

---

## P3: 発注CD変更機能

### 目的

発注候補・移動候補の詳細モーダルで発注CD（ordering_code）をセレクトボックスから変更可能にする。（仕様 1.5）

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` | viewCalculation モーダルに発注CDセレクト追加 |
| `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` | edit モーダルに発注CDセレクト追加 |

### 修正手順

#### 1. WmsOrderCandidatesTable.php — viewCalculation アクション

**fillForm に追加**:
```php
'ordering_code' => $record->search_code ?? $record->ordering_code,
```

**schema に追加**（`$isEditable` ブロック内、Grid の後に）:
```php
if ($isEditable) {
    // item_search_information から該当商品の全コードを取得
    $codes = DB::connection('sakemaru')
        ->table('item_search_information')
        ->where('item_id', $record->item_id)
        ->where('is_active', true)
        ->select('search_string', 'code_type', 'is_used_for_ordering')
        ->get();

    $codeOptions = $codes->mapWithKeys(function ($code) {
        $label = $code->search_string;
        if ($code->is_used_for_ordering) {
            $label .= ' (現在の発注用)';
        }
        return [$code->search_string => $label];
    })->toArray();

    if (count($codeOptions) > 0) {
        $schema[] = Select::make('ordering_code')
            ->label('発注CD')
            ->options($codeOptions)
            ->searchable()
            ->helperText('この商品に登録されている検索コードから発注CDを選択');
    }
}
```

※ `use Filament\Forms\Components\Select;` の import を追加すること。

**action（保存ロジック）に発注CD更新を追加**:
```php
// 発注CD変更の検出と保存
if (isset($data['ordering_code']) && $data['ordering_code'] !== ($record->search_code ?? $record->ordering_code)) {
    $newSearchCode = $data['ordering_code'];
    $record->search_code = $newSearchCode;
    $record->ordering_code = str_pad($newSearchCode, 13, '0', STR_PAD_LEFT);
}
```

#### 2. WmsStockTransferCandidatesTable.php — edit アクション

同様にセレクトボックスと保存ロジックを追加。移動候補の場合は `ordering_code` カラムが P0 で追加済みなので同様に更新。

### 完了条件

- PENDING状態の発注候補詳細モーダルに「発注CD」セレクトボックスが表示される
- 商品に紐づく `item_search_information` の全コードが選択肢として表示される
- 発注CDを変更して保存 → `ordering_code`（13桁ゼロパディング）と `search_code` が更新される
- `is_manually_modified = true` が設定される
- 移動候補でも同様に動作する

---

## P4: 発注データファイルバルク操作

### 目的

発注データファイルテーブルに送信方式カラムを追加し、メール一括送信・CSV一括ダウンロード・FAX一括ダウンロードのバルクアクションを実装する。（仕様 1.6）

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php` | 送信方式カラム + バルクアクション3種 |
| `app/Filament/Resources/Contractors/Schemas/ContractorForm.php` | メールアドレスにhelperText追加 |

### 修正手順

#### 1. 送信方式カラム追加

`total_quantity` カラムの直後に追加:
```php
TextColumn::make('is_mail_order')
    ->label('送信方式')
    ->badge()
    ->formatStateUsing(fn (bool $state): string => $state ? 'メール送信' : '手動送信')
    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
    ->alignCenter(),
```

#### 2. メール一括送信（bulkSendMail）

```php
BulkAction::make('bulkSendMail')
    ->label('メール一括送信')
    ->icon('heroicon-o-envelope')
    ->color('warning')
    ->requiresConfirmation()
    ->modalHeading('選択した発注データをメールで一括送信')
    ->modalDescription(function (Collection $records): string {
        $total = $records->count();
        $alreadySent = $records->filter(fn ($r) => $r->mail_sent_at !== null)->count();
        $noMail = $records->filter(fn ($r) => !$r->is_mail_order)->count();
        $sendable = $total - $alreadySent - $noMail;
        $skipped = $alreadySent + $noMail;

        return "選択: {$total}件 → 送信対象: {$sendable}件"
            . ($skipped > 0 ? "（スキップ: {$skipped}件 — 送信済み{$alreadySent}件 / メール未設定{$noMail}件）" : '');
    })
    ->action(function (Collection $records) {
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($records as $record) {
            // 二重送信防止
            if ($record->mail_sent_at !== null) { $skipped++; continue; }
            // メール未設定
            if (!$record->is_mail_order) { $skipped++; continue; }

            try {
                $setting = WmsContractorSetting::where('contractor_id', $record->contractor_id)->first();
                $email = $record->mail_to ?? $setting?->order_mail ?? $record->contractor?->email;
                if (!$email) { $skipped++; continue; }

                // FAX PDF生成（通信欄なし）
                $pdfService = app(PurchaseOrderPdfService::class);
                $pdfService->generateAndStore($record, null);
                $record->refresh();

                // メール送信（テンプレートから自動生成）
                $subject = self::replaceMailVariables($setting?->order_mail_title, $record);
                $content = self::replaceMailVariables($setting?->order_mail_content, $record);

                Mail::to($email)->send(new OrderDataMail(
                    dataFile: $record,
                    attachCsv: true,
                    attachFax: true,
                    fromName: $setting?->order_mail_from,
                    subject: $subject,
                    content: $content,
                ));

                $record->markAsMailSent(auth()->id(), $email);
                $sent++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        Notification::make()
            ->title("メール一括送信完了")
            ->body("送信: {$sent}件 / スキップ: {$skipped}件 / 失敗: {$failed}件")
            ->success()
            ->send();
    })
```

#### 3. CSV一括ダウンロード（bulkDownloadCsv）

選択レコードのCSVファイルを1つのCSVファイルに結合してダウンロード:
```php
BulkAction::make('bulkDownloadCsv')
    ->label('CSV一括ダウンロード')
    ->icon('heroicon-o-document-text')
    ->color('primary')
    ->action(function (Collection $records) {
        // S3から各CSVファイルを取得し、1つのCSVに結合
        // ヘッダー行は最初のファイルのみ保持
        // 各レコードの csv_downloaded_at を更新
        // StreamedResponse で返却
    })
```

#### 4. FAX一括ダウンロード（bulkDownloadFax）

選択レコードのFAX PDFを1つのPDFにマージしてダウンロード:
```php
BulkAction::make('bulkDownloadFax')
    ->label('FAX一括ダウンロード')
    ->icon('heroicon-o-document')
    ->color('success')
    ->action(function (Collection $records) {
        // 各レコードのFAX PDFを生成（通信欄なし）
        // PDFをマージ（setasign/fpdi 等のライブラリ使用 or TCPDF の既存実装確認）
        // 各レコードの fax_downloaded_at を更新
        // StreamedResponse で返却
    })
```

**注意**: PDFマージには `setasign/fpdi` ライブラリが必要。既にインストール済みか確認:
```bash
composer show setasign/fpdi 2>/dev/null || echo "fpdi not installed"
```
未インストールの場合は `composer require setasign/fpdi` を実行。

#### 5. バルクアクションの登録

テーブルの `->toolbarActions()` の前に `->bulkActions()` を追加:
```php
->bulkActions([
    BulkActionGroup::make([
        BulkAction::make('bulkSendMail')...,
        BulkAction::make('bulkDownloadCsv')...,
        BulkAction::make('bulkDownloadFax')...,
    ]),
])
```

#### 6. ContractorForm.php — メールアドレス helperText

現在:
```php
TextInput::make('wms_order_mail')
    ->label('発注先メールアドレス')
```

修正後:
```php
TextInput::make('wms_order_mail')
    ->label('発注先メールアドレス')
    ->email()
    ->helperText('このメールアドレスが登録されていると、メール送信発注先になります。')
```

### 完了条件

- テーブルに「送信方式」カラムが表示される（メール送信 / 手動送信のバッジ）
- チェックボックスでレコードを選択 → バルクアクション3種が表示される
- メール一括送信: 送信対象のみ送信、送信済み/メール未設定はスキップ、結果通知あり
- CSV一括ダウンロード: 選択レコードのCSVが1ファイルにまとまってDL
- FAX一括ダウンロード: 選択レコードのFAXが1PDFにまとまってDL
- 発注先設定画面のメールアドレス欄に注意書きが表示される

---

## P5: データ生成ロジック更新

### 目的

発注データファイル生成時に `is_mail_order` を自動設定し、移動候補生成時に `ordering_code` を保存するようにする。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/AutoOrder/OrderDataFileService.php` or `OrderExecutionService.php` | データ生成時に `is_mail_order` 設定 |
| `app/Services/AutoOrder/TransferCreateJobHandler.php` | `ordering_code` を候補生成時に保存 |

### 調査手順

#### 1. is_mail_order の設定箇所を特定

```bash
grep -rn 'WmsOrderDataFile::create\|order_data_files.*insert\|->create(' app/Services/AutoOrder/ --include="*.php" | grep -i 'file\|data'
```

`WmsOrderDataFile` のレコード生成箇所を特定し、生成時に以下を追加:
```php
'is_mail_order' => (bool) WmsContractorSetting::where('contractor_id', $contractorId)
    ->whereNotNull('order_mail')
    ->where('order_mail', '!=', '')
    ->exists(),
```

#### 2. ordering_code の保存（TransferCreateJobHandler）

`TransferCreateJobHandler.php` の候補レコード生成箇所（約381行目付近）で `ordering_code` も保存されるよう追加:

既に `search_code` は保存されているはずなので、同じデータソースから13桁ゼロパディングした値を `ordering_code` に設定:
```php
'ordering_code' => str_pad($searchCode, 13, '0', STR_PAD_LEFT),
```

### 完了条件

- 新しく発注データファイルを生成した時、`is_mail_order` が正しく設定される
  - メールアドレス設定済みの発注先 → `true`
  - メールアドレス未設定 / JX送信 → `false`
- 新しく移動候補を生成した時、`ordering_code` が正しく保存される
- 既存の候補生成フロー（`OrderCreateJobHandler`）で `ordering_code` が既に保存されていることを確認

---

## 制約（厳守）

1. **FK禁止**: 外部キー制約を作成しない
2. **migrate:fresh/refresh 禁止**: `php artisan migrate` のみ使用
3. **二重送信禁止**: `mail_sent_at` が非nullのレコードへの再送信をブロック
4. **マスタ変更範囲**: 発注点は `wms_monthly_safety_stocks` の当月のみ更新。`item_contractors` は変更しない
5. **発注CD変更はスナップショットのみ**: `item_search_information.is_used_for_ordering` は変更しない
6. **Filament 4 名前空間**: `Filament\Actions\Action`、`Filament\Schemas\Components\*` を使用
7. **計算ロジック変更禁止**: `OrderCandidateCalculationService` の計算ロジックは変更しない

## 全体完了条件

1. 全マイグレーション成功
2. 発注候補テーブル: origin_type が日本語表示される
3. 移動候補テーブル: 「検索CD」→「発注CD」になっている
4. 発注候補/移動候補の詳細モーダル: 発注点・発注CDの編集・保存が動作する
5. 発注データファイル: 送信方式カラム表示 + バルクアクション3種が動作する
6. 発注先設定画面: メールアドレスに注意書きが表示される
7. データ生成フロー: is_mail_order と ordering_code が正しく保存される
8. `composer test` でテスト失敗がないこと
