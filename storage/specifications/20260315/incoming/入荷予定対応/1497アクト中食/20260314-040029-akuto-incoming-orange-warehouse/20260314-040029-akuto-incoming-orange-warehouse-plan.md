# アクト中食 入荷予定CSV対応 + オレンジ冷凍倉庫新設 作業計画

## 前提

- 仕様書: `20260314-040029-akuto-incoming-orange-warehouse.md`（DB調査完了・全確認事項解決済み）
- ActCsvIncomingParser は実装済み（得意先コード→倉庫マッピングのみ未対応）
- IncomingReceiveService の倉庫解決ロジックは既存のまま使用（変更不要）
- OrderCandidateCalculationService はデータ設定のみで対応（コード変更不要）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | OrangeWarehouseSeeder作成 | 倉庫901 + contractor 9901 + supplier 9901 + wms設定 | Seederファイルが構文エラーなく作成される |
| P2 | AkutoFrozenItemContractorSeeder作成 | 24商品のitem_contractors 3種設定 | Seederファイルが構文エラーなく作成される |
| P3 | ActCsvIncomingParserマッピング追加 | 得意先コード→倉庫コード変換 | SHOP_WAREHOUSE_MAP定数 + saveSlipGroup変換が追加される |
| P4 | InitSystemSeeder更新 | 新Seeder呼び出し追加 | InitSystemSeederから2つのSeederが呼ばれる |
| P5 | Seeder実行＋DB確認 | Seeder実行＋データ検証 | DBに正しいデータが投入される |
| P6 | CSVアップロード動作確認 | サンプルCSVで倉庫マッピング検証 | CSV得意先コード607がオレンジ倉庫(901)にマッピングされる |

---

## P1: OrangeWarehouseSeeder作成

### 目的

オレンジ冷凍倉庫（code=901）と関連するコントラクタ・サプライヤー・WMS設定を一括で作成するSeederを実装する。

### 修正対象ファイル

- **新規**: `database/seeders/OrangeWarehouseSeeder.php`

### 実装内容

1つのSeederで以下を順番に作成（全てupdateOrInsertで冪等）:

**Step 1: warehouses レコード**
```
倉庫91のレコードをreplicate → code='901', name='オレンジ冷凍倉庫', kana='オレンジソウコ'
既存チェック: Warehouse::where('code', '901')->exists()
```

**Step 2: contractors レコード**
```
id=9901, code='9901', name='LW華本部 物流課（オレンジ）'
kana_name='ｵﾚﾝｼﾞ', client_id=6
postal_code/address1/tel は contractor 9012 と同じ値をコピー
delivery_type='ARRIVAL', supplier_id=9901, is_active=true, is_auto_change_order=true
```

**Step 3: suppliers レコード**
```
id=9901, client_id=6, partner_id=9901
partner_category='SUPPLIER', delivery_price_payer='PARTNER'
payee_bank_type='SAME_BANK_SAME_BRANCH'
```

**Step 4: wms_contractor_settings（オレンジINTERNAL）**
```
contractor_id=9901
transmission_type=INTERNAL
supply_warehouse_id=（Step 1で作成した倉庫のID）
transmission_time='10:30', auto_order_generation_time='09:30'
is_transmission_sun〜sat=true, is_auto_transmission=false
is_receive_enabled=false
```

**Step 5: wms_contractor_settings（アクト中食1497更新）**
```
contractor_id=1497 の既存レコードを更新:
is_receive_enabled=true, receive_format='CSV'
```

**Step 6: wms_warehouse_auto_order_settings**
```
warehouse_id=（Step 1で作成した倉庫のID）
is_auto_order_enabled=true
exclude_sunday_arrival=true, exclude_holiday_arrival=true
confirmation_level='STATUS2'
```

### 注意事項

- `DB::connection('sakemaru')` を使用すること（warehouses, contractors, suppliers は sakemaru DB）
- `WmsContractorSetting`, `WmsWarehouseAutoOrderSetting` は WmsModel 経由で sakemaru 接続
- contractors/suppliers の INSERT は `DB::connection('sakemaru')->table()` で直接
- 倉庫91のIDは `Warehouse::where('code', '91')->first()->id` で動的取得

### 完了条件

- `php artisan tinker` でSeederクラスがロードエラーなし
- `./vendor/bin/pint database/seeders/OrangeWarehouseSeeder.php` でフォーマットOK

---

## P2: AkutoFrozenItemContractorSeeder作成

### 目的

対象24商品のitem_contractorsを3パターン設定する。

### 修正対象ファイル

- **新規**: `database/seeders/AkutoFrozenItemContractorSeeder.php`

### 実装内容

**Step 1: CSVファイル読み込み**
```
storage/seeders/akuto-frozoz-items.csv を読み込み
カラム: 単品ＣＤ, 表示正式名称, 容量, 入数, ＪＡＮコード
items.code で item_id を一括取得（N+1回避）
```

**Step 2: オレンジ倉庫(901) × アクト中食(1497)のEXTERNAL発注設定**
```
各商品について item_contractors に updateOrInsert:
- warehouse_id = オレンジ倉庫ID（warehouses WHERE code='901'）
- item_id = CSVの単品CDで特定
- contractor_id = 1497
- supplier_id = 8901（アクト中食のsupplier_id、DB確認済み）
- is_auto_order = true
- safety_stock = CSVの入数カラム
```

**Step 3: サテライト倉庫のcontractor_id変更（1497→9901）**
```
対象倉庫: [1, 2, 3, 4, 7, 8, 9, 10, 11, 21, 22, 23]
対象商品: Step 1で取得した item_id リスト
条件: contractor_id = 1497
更新: contractor_id=9901, supplier_id=9901, is_auto_order=true
```

**Step 4: 倉庫91のis_auto_order無効化**
```
条件: warehouse_id=91, item_id IN (対象24商品), contractor_id=1497
更新: is_auto_order=false
```

### 注意事項

- `DB::connection('sakemaru')->table('item_contractors')` で直接操作
- CSVの `入数` カラムは全角文字列。`mb_convert_kana($val, 'n')` で半角変換後 (int) キャスト
- supplier_id=8901 はアクト中食の既存supplier_id（DB確認済み: item_contractors WHERE contractor_id=1497 の supplier_id）
- 71(特販課), 80(輸入課), 98(営業部石川) はサテライト倉庫リストに含めない（店舗ではない）

### 完了条件

- Seederクラスがロードエラーなし
- Pint フォーマットOK

---

## P3: ActCsvIncomingParserマッピング追加

### 目的

アクト中食CSVの得意先コード（col 0）を酒丸倉庫コードに変換するマッピングを追加する。

### 修正対象ファイル

- **既存変更**: `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php`

### 実装内容

**Step 1: SHOP_WAREHOUSE_MAP 定数を追加**

`SHIPPING_JAN_CODE` 定数の後に追加:

```php
/** 得意先コード → 酒丸倉庫コード マッピング */
private const SHOP_WAREHOUSE_MAP = [
    '934' => '91',   // 本部むすびの蔵
    '607' => '901',  // オレンジ冷凍倉庫
    '618' => '10',   // 敦賀
    '122' => '21',   // 江守
];
```

**Step 2: saveSlipGroup() の shopCode 変換を変更**

現在のコード（行177付近）:
```php
$shopCode = trim($firstRow[self::COL_SHOP_CODE] ?? '');
```

変更後:
```php
$rawShopCode = ltrim(trim($firstRow[self::COL_SHOP_CODE] ?? ''), '0');
$shopCode = self::SHOP_WAREHOUSE_MAP[$rawShopCode] ?? $rawShopCode;
```

### 動作確認ポイント

- CSV得意先コード `00000934` → ltrim '0' → `934` → MAP → `91`
- CSV得意先コード `00000607` → ltrim '0' → `607` → MAP → `901`
- マッピングにない得意先コードはそのまま通過（フォールバック）

### 完了条件

- Pint フォーマットOK
- 既存テストがあれば通過（`php artisan test --filter=ActCsv`）

---

## P4: InitSystemSeeder更新

### 目的

InitSystemSeederから新しい2つのSeederを呼び出すように追加する。

### 修正対象ファイル

- **既存変更**: `database/seeders/InitSystemSeeder.php`

### 実装内容

`WmsPickingAssignmentStrategySeeder` の後に追加:

```php
// オレンジ冷凍倉庫の新設 + コントラクタ設定
$this->call(OrangeWarehouseSeeder::class);

// アクト中食冷凍商品のitem_contractors設定
$this->call(AkutoFrozenItemContractorSeeder::class);
```

**順序が重要**: OrangeWarehouseSeeder が先（倉庫が存在しないとitem_contractorsが設定できない）。

### 完了条件

- Pint フォーマットOK

---

## P5: Seeder実行＋DB確認

### 目的

作成したSeederを実行し、データが正しく投入されたことを確認する。

### 実行手順

**Step 1: Seeder実行**
```bash
php artisan db:seed --class=OrangeWarehouseSeeder
php artisan db:seed --class=AkutoFrozenItemContractorSeeder
```

**Step 2: データ検証（tinker）**

```php
// 1. 倉庫901が存在するか
Warehouse::where('code', '901')->first(); // name='オレンジ冷凍倉庫'

// 2. contractor 9901が存在するか
DB::connection('sakemaru')->table('contractors')->where('id', 9901)->first();

// 3. supplier 9901が存在するか
DB::connection('sakemaru')->table('suppliers')->where('id', 9901)->first();

// 4. wms_contractor_settings に9901のINTERNAL設定があるか
WmsContractorSetting::where('contractor_id', 9901)->first();
// → transmission_type=INTERNAL, supply_warehouse_id=901のID

// 5. wms_contractor_settings 1497のreceive_format='CSV'になっているか
WmsContractorSetting::where('contractor_id', 1497)->first();

// 6. wms_warehouse_auto_order_settings にオレンジ倉庫設定があるか
WmsWarehouseAutoOrderSetting::where('warehouse_id', $orange901Id)->first();

// 7. item_contractors: オレンジ倉庫(901) × 24商品 × 1497 が存在するか
DB::connection('sakemaru')->table('item_contractors')
    ->where('warehouse_id', $orange901Id)
    ->where('contractor_id', 1497)
    ->count(); // → 24

// 8. item_contractors: サテライト12倉庫 × 24商品 × 9901 に変更されたか
DB::connection('sakemaru')->table('item_contractors')
    ->whereIn('warehouse_id', [1,2,3,4,7,8,9,10,11,21,22,23])
    ->where('contractor_id', 9901)
    ->count(); // → 12 × 24 = 288

// 9. item_contractors: 倉庫91 × 24商品 の is_auto_order=false
DB::connection('sakemaru')->table('item_contractors')
    ->where('warehouse_id', 91)
    ->where('contractor_id', 1497)
    ->whereIn('item_id', $frozenItemIds)
    ->where('is_auto_order', false)
    ->count(); // → 24
```

**Step 3: 冪等性確認**
```bash
# 2回目実行してもエラーなし
php artisan db:seed --class=OrangeWarehouseSeeder
php artisan db:seed --class=AkutoFrozenItemContractorSeeder
```

### 完了条件

- 全9項目のデータ検証がパス
- 2回目実行でエラーなし（冪等性）

---

## P6: CSVアップロード動作確認

### 目的

アクト中食サンプルCSVをアップロードし、得意先コード→倉庫マッピングが正しく動作することを確認する。

### 実行手順

**Step 1: サンプルCSVの得意先コード確認**
```bash
# サンプルCSV（Shift_JIS）の1行目の得意先コードを確認
head -2 'storage/specifications/incoming/入荷予定対応/1497アクト中食/ny93420260218114750.csv' | iconv -f SJIS -t UTF-8
# → 得意先コード=00000934 → マッピング後 91
```

**Step 2: tinkerでパーサー実行テスト**
```php
$parser = new \App\Services\AutoOrder\IncomingParsers\ActCsvIncomingParser();
$content = file_get_contents('storage/specifications/incoming/入荷予定対応/1497アクト中食/ny93420260218114750.csv');
$file = $parser->parse($content, 'test.csv', 1497);

// 作成されたslipのb_shop_codeを確認
$slips = \App\Models\WmsIncomingReceivedSlip::where('received_file_id', $file->id)->get();
foreach ($slips as $slip) {
    echo "slip_number={$slip->slip_number}, b_shop_code={$slip->b_shop_code}\n";
    // b_shop_code が '91' であること（934→91にマッピング）
}
```

**Step 3: 倉庫解決の確認**
```php
// IncomingReceiveServiceの倉庫解決がb_shop_code='91'で倉庫91を見つけるか
$warehouse = \App\Models\Sakemaru\Warehouse::where(DB::raw('LTRIM(code)'), '91')
    ->orWhere('code', '91')
    ->first();
echo $warehouse->id . ' ' . $warehouse->name;
// → 91 華むすびの蔵センター

// b_shop_code='901'の場合（607→901マッピング）
$warehouse901 = \App\Models\Sakemaru\Warehouse::where(DB::raw('LTRIM(code)'), '901')
    ->orWhere('code', '901')
    ->first();
echo $warehouse901->id . ' ' . $warehouse901->name;
// → オレンジ冷凍倉庫
```

### 完了条件

- CSV得意先コード `00000934` → `b_shop_code='91'`（本部むすびの蔵）
- CSV得意先コード `00000607` があれば → `b_shop_code='901'`（オレンジ冷凍倉庫）
- 倉庫解決ロジックでb_shop_code='901'からオレンジ倉庫が正しく特定される

---

## 制約（厳守）

1. **migrate:fresh / migrate:refresh / db:wipe は絶対禁止**（本番データ削除リスク）
2. **FK制約は追加しない**（プロジェクト方針）
3. **参照のみファイルは変更禁止**: IncomingReceiveService, OrderCandidateCalculationService, TransferCandidateExecutionService 等
4. **sakemaru DBへの操作は慎重に**: 共有データベースのため、UPDATE/INSERT は対象を正確に絞る
5. **Seeder冪等性**: 全操作は `updateOrInsert` / 存在チェック付きで実装
6. **Pint フォーマット**: 全PHPファイルは `./vendor/bin/pint` を通すこと

## 全体完了条件

1. オレンジ冷凍倉庫（code=901）がwarehousesテーブルに存在する
2. contractor 9901 + supplier 9901 が作成されている
3. wms_contractor_settings に 9901(INTERNAL, supply_warehouse_id=901のID) が存在する
4. wms_contractor_settings の 1497 が receive_format='CSV', is_receive_enabled=true になっている
5. item_contractors: オレンジ倉庫 × 24商品 × 1497（EXTERNAL発注用）が存在する
6. item_contractors: サテライト12倉庫 × 24商品が contractor_id=9901（INTERNAL移動依頼用）に変更されている
7. item_contractors: 倉庫91 × 24商品の is_auto_order=false になっている
8. ActCsvIncomingParser で得意先コード→倉庫コード変換が正しく動作する
9. Seederが2回実行してもエラーなし（冪等性）
