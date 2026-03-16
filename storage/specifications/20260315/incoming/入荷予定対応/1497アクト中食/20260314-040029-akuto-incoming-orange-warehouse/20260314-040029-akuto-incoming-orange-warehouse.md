# アクト中食 入荷予定CSV対応 + オレンジ冷凍倉庫新設 + 発注ロジック変更

- **作成日**: 2026-03-14
- **ステータス**: ドラフト（DB調査完了・全確認事項解決済み）
- **ディレクトリ**: `storage/specifications/incoming/入荷予定対応/1497アクト中食/20260314-040029-akuto-incoming-orange-warehouse/`

## 背景・目的

アクト中食（仕入先コード: 1497）のCSVデータから入荷予定を生成する機能を完成させる。加えて、アクト中食の冷凍商品は新設する「オレンジ冷凍倉庫（コード901）」で管理し、従来の本部むすびの蔵（91）には移動依頼をかけない運用に変更する。

### 解決すべき課題

1. **CSVの得意先コード→倉庫変換**: アクト中食CSVの得意先コードを酒丸倉庫コードに変換するマッピングが未実装
2. **オレンジ冷凍倉庫の新設**: コード901の倉庫レコードが存在しない
3. **発注ロジックの変更**: オレンジ倉庫管理商品はサテライト倉庫から91ではなくオレンジ倉庫への移動依頼が必要。オレンジ倉庫で不足した場合はアクト中食（1497）への外部発注が必要
4. **item_contractors の更新**: 対象商品のitem_contractors設定をオレンジ倉庫発注に変更

## 現状の実装

### ActCsvIncomingParser（実装済み）
- `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php`
- CSV（Shift_JIS）を3層構造（file/slip/detail）にパース
- 商品マッピング: `item_connections.partner_item_code` → `item_id`
- エラー管理: 商品不明 → `wms_incoming_import_errors` に記録

### IncomingReceiveService（実装済み）
- `app/Services/AutoOrder/IncomingReceiveService.php`
- `createSchedulesFromSlip()`: `b_shop_code` → `warehouses.code` でLTRIM照合
- 現在は得意先コード→倉庫の変換ロジックなし（JXの shop_code = 倉庫コードの前提）

### 倉庫マスタ（warehouses テーブル）
- `app/Models/Sakemaru/Warehouse.php` extends `CustomModel`（sakemaru connection）
- 主要フィールド: `id`, `code`, `name`, `is_virtual`, `is_active`, `client_id`, `client_calendar_id`
- 現在のコード体系: 91（本部むすびの蔵）, 10（敦賀）, 21（江守）

### 自動発注・移動依頼ルーティング（DB調査結果）

**ハブ倉庫の決定ロジック**:

```
item_contractors (warehouse_id=サテライト, item_id, contractor_id)
        ↓ contractor_id で参照
wms_contractor_settings (contractor_id, transmission_type=INTERNAL, supply_warehouse_id=ハブ倉庫)
        ↓
wms_stock_transfer_candidates (satellite_warehouse_id, hub_warehouse_id=supply_warehouse_id)
```

- `OrderCandidateCalculationService` が起動時に `wms_contractor_settings` の INTERNAL レコードを全読み込み
- `$internalSettings[contractor_id] = supply_warehouse_id` のマッピングを構築
- `item_contractors` から `contractor_id` が INTERNAL のものを抽出し、`supply_warehouse_id` をハブ倉庫として移動候補を生成
- **contractor_id が移動先ハブ倉庫を決定するピボット**

**DB調査で判明した現状**:

| 項目 | 値 |
|------|-----|
| 91のINTERNALコントラクタ | **contractor_id=9012**（"LW華本部 物流担当"） |
| wms_contractor_settings id=116 | contractor_id=9012, transmission_type=INTERNAL, supply_warehouse_id=91 |
| 対象24商品のitem_contractors（全倉庫） | **全て contractor_id=1497**（アクト中食直接発注） |
| 対象24商品 × contractor_id=9012 | **0件**（91へのINTERNAL移動依頼は未設定） |
| サテライト倉庫のis_auto_order | ほぼ全て **0**（自動発注OFF） |
| 倉庫91のis_auto_order | 一部 1（611023, 246710, 612116, 612163, 621004, 621043, 621087, 621095, 621109, 621363） |
| アクト中食(1497) wms_contractor_settings | id=290, **transmission_type=MANUAL_CSV**（既存） |
| contractor_id 901, 9901 | **未使用（利用可能）** |
| contractors auto_increment | 90001 |
| warehouse_stock_transfer_delivery_courses | **0件**（テーブル空） |

**重要な発見**: 対象24商品はサテライト倉庫からの91へのINTERNAL移動が元々設定されていない（contractor_id=1497でEXTERNAL扱い）。つまり現在は各店舗が直接アクト中食に発注する想定だった。これをオレンジ倉庫経由に変更する。

## 変更内容

### 概要

1. オレンジ冷凍倉庫（901）をSeederで新設
2. オレンジ倉庫用の「INTERNAL コントラクタ」を `wms_contractor_settings` に登録（supply_warehouse_id=901）
3. アクト中食CSV得意先コード→倉庫マッピングをパーサーに追加
4. 対象24商品の item_contractors を2種類設定:
   - サテライト倉庫: contractor_id=オレンジ倉庫用INTERNALコントラクタ（→移動依頼）
   - オレンジ倉庫(901): contractor_id=アクト中食(1497)（→外部発注）
5. オレンジ倉庫の `wms_warehouse_auto_order_settings` を登録

### 詳細設計

#### Phase 1: オレンジ冷凍倉庫の新設

**Seeder で新規倉庫レコード作成**

倉庫91（本部むすびの蔵）のレコードをコピーし、以下を変更:
- `code`: `901`
- `name`: `オレンジ冷凍倉庫`
- `kana`: `オレンジソウコ`（指示通り）
- `is_virtual`: false（実倉庫）
- その他フィールドは91のコピー（client_calendar_id含む）

```php
// database/seeders/OrangeWarehouseSeeder.php
$warehouse91 = Warehouse::where('code', '91')->first();
if (!$warehouse91) return;

// 冪等: 既存チェック
if (Warehouse::where('code', '901')->exists()) {
    $this->command->info('オレンジ冷凍倉庫は既に存在します');
    return;
}

$newWarehouse = $warehouse91->replicate();
$newWarehouse->code = '901';
$newWarehouse->name = 'オレンジ冷凍倉庫';
$newWarehouse->kana = 'オレンジソウコ';
$newWarehouse->save();
```

**InitSystemSeeder に追加**: `OrangeWarehouseSeeder` を呼び出し一覧に追加。

**特徴**: オレンジ倉庫は外部の倉庫だが内部倉庫のような動きをとる:
- 在庫管理対象（real_stocksに在庫レコードがある）
- サテライト倉庫からの移動依頼先として機能（INTERNAL）
- オレンジ倉庫自身はアクト中食に外部発注する（EXTERNAL）

#### Phase 2: コントラクタ設定（contractors + wms_contractor_settings + wms_warehouse_auto_order_settings）

**2-A: オレンジ倉庫用 INTERNAL コントラクタの新規作成**

91のINTERNALコントラクタ（id=9012, "LW華本部 物流担当"）と同じパターンで、オレンジ倉庫用のコントラクタを新規作成する。

**contractors テーブル**（明示的ID指定、auto_increment=90001なので衝突なし）:

```php
// contractor_id = 9901（倉庫コード901に対応する採番）
DB::connection('sakemaru')->table('contractors')->updateOrInsert(
    ['id' => 9901],
    [
        'client_id' => 6,
        'code' => '9901',
        'name' => 'LW華本部 物流課（オレンジ）',
        'kana_name' => 'ｵﾚﾝｼﾞ',
        'postal_code' => '918-8231',           // 91と同じ
        'address1' => '福井市問屋町2丁目35番地',  // 91と同じ
        'tel' => '0776-24-1160',               // 91と同じ
        'is_auto_change_order' => true,
        'delivery_type' => 'ARRIVAL',
        'supplier_id' => 9901,
        'is_active' => true,
    ]
);
```

**suppliers テーブル**（contractor_id=9012 → supplier_id=9012 のパターンに倣う）:

```php
DB::connection('sakemaru')->table('suppliers')->updateOrInsert(
    ['id' => 9901],
    [
        'client_id' => 6,
        'partner_id' => 9901,
        'partner_category' => 'SUPPLIER',
        'delivery_price_payer' => 'PARTNER',
        'payee_bank_type' => 'SAME_BANK_SAME_BRANCH',
    ]
);
```

**wms_contractor_settings**（INTERNAL、supply_warehouse_id=オレンジ倉庫）:

```php
// 9012の設定をベースにコピー、supply_warehouse_idのみオレンジ倉庫に変更
WmsContractorSetting::updateOrCreate(
    ['contractor_id' => 9901],
    [
        'transmission_type' => TransmissionType::INTERNAL,
        'supply_warehouse_id' => $orangeWarehouseId,  // warehouses WHERE code='901'
        'transmission_time' => '10:30',
        'auto_order_generation_time' => '09:30',
        'is_transmission_sun' => true,
        'is_transmission_mon' => true,
        'is_transmission_tue' => true,
        'is_transmission_wed' => true,
        'is_transmission_thu' => true,
        'is_transmission_fri' => true,
        'is_transmission_sat' => true,
        'is_auto_transmission' => false,
        'is_receive_enabled' => false,
    ]
);
```

**2-B: アクト中食（1497）の設定更新**

既存の `wms_contractor_settings` (id=290) は `receive_format=JX`, `is_receive_enabled=0`。
CSV受信を有効化するため更新:

```php
WmsContractorSetting::where('contractor_id', 1497)->update([
    'is_receive_enabled' => true,
    'receive_format' => 'CSV',
]);
```

**2-C: オレンジ倉庫の自動発注設定**

```php
WmsWarehouseAutoOrderSetting::updateOrCreate(
    ['warehouse_id' => $orangeWarehouseId],
    [
        'is_auto_order_enabled' => true,
        'exclude_sunday_arrival' => true,
        'exclude_holiday_arrival' => true,
        'confirmation_level' => ConfirmationLevel::STATUS2, // 承認まで
    ]
);
```

#### Phase 3: アクト中食CSV 得意先コード→倉庫マッピング

**ActCsvIncomingParser に固定マッピングを追加**

CSVの得意先コード（col 0: `得意先コード`）を酒丸倉庫コードに変換:

| CSV得意先コード | 酒丸倉庫名 | 倉庫コード |
|----------------|-----------|-----------|
| 934            | 本部むすびの蔵 | 91 |
| 607            | オレンジ冷凍倉庫 | 901 |
| 618            | 敦賀 | 10 |
| 122            | 江守 | 21 |

```php
// ActCsvIncomingParser.php に追加
private const SHOP_WAREHOUSE_MAP = [
    '934' => '91',   // 本部むすびの蔵
    '607' => '901',  // オレンジ冷凍倉庫
    '618' => '10',   // 敦賀
    '122' => '21',   // 江守
];
```

**変更箇所**: `saveSlipGroup()` で `b_shop_code` をCSVの得意先コードからマッピング後の倉庫コードに変換して保存。

現在のコード:
```php
$shopCode = trim($firstRow[self::COL_SHOP_CODE] ?? '');
```

変更後:
```php
$rawShopCode = ltrim(trim($firstRow[self::COL_SHOP_CODE] ?? ''), '0');
$shopCode = self::SHOP_WAREHOUSE_MAP[$rawShopCode] ?? $rawShopCode;
```

これにより `IncomingReceiveService::createSchedulesFromSlip()` の既存の倉庫解決ロジック（`LTRIM(code)` 照合）で正しい倉庫に紐付く。

#### Phase 4: item_contractors の更新

**対象商品**: `storage/seeders/akuto-frozoz-items.csv` に記載の24商品

**4-A: オレンジ倉庫(901)の外部発注用 item_contractors**

オレンジ倉庫自身がアクト中食に発注するための設定。`safety_stock` は各商品の入数（＝最低仕入単位）に設定:

| 単品CD | 商品名 | 入数 | safety_stock |
|--------|--------|------|-------------|
| 611023 | 中国産塩味枝豆500g | 20 | 20 |
| 611032 | 中国産カットいんげん500g | 20 | 20 |
| 612163 | 中国産 いか串 45g×10本入 | 24 | 24 |
| 621109 | 日東ベストJG 新牛丼の素150g | 40 | 40 |
| 621294 | ニチレイ 和風唐揚げ | 12 | 12 |
| 621004 | ちぬや冷食さめてもおいしい牛肉コロッケ750g | 12 | 12 |
| 612049 | クラレイシーフードミックス400g | 20 | 20 |
| 621052 | ジェイフーズネット鉄板焼レストランハンバーグ200g | 50 | 50 |
| 621043 | ジェイフーズネットJFN釜揚げうどん5食1.25Kg | 8 | 8 |
| 621087 | ちぬや冷食鶏軟骨唐揚500g | 20 | 20 |
| 246548 | 国産 ネギ塩 豚ホルモン | 24 | 24 |
| 621095 | 広島県倉橋産かきフライ | 20 | 20 |
| 621363 | たこ焼40個 | 12 | 12 |
| 621203 | アクト中食カレーコロッケ600g | 20 | 20 |
| 612116 | 広島県倉橋産冷凍かき L1Kg | 10 | 10 |
| 611030 | 中国産ブロッコリー500g | 20 | 20 |
| 621332 | ハイファイフーズ 舞茸照り焼きソースハンバーグ135g | 80 | 80 |
| 621275 | 味のちぬやおつまみささみチーズフライ500g | 24 | 24 |
| 621353 | ジャパンフードたこ焼き 20g50個 | 10 | 10 |
| 621035 | アクト中食こだわりの焼おにぎり 8個560g | 15 | 15 |
| 286343 | ジャパンフードサービス 鶏皮餃子10個 | 40 | 40 |
| 613133 | アメリカ産 牛塩ホルモン250g | 25 | 25 |
| 286448 | オーストラリア産 牛タンスライス320g | 15 | 15 |
| 246710 | 緒方商店 鶏皮せんべい1Kg | 10 | 10 |

```php
// database/seeders/AkutoFrozenItemContractorSeeder.php
foreach ($csvItems as $row) {
    $item = Item::where('code', $row['単品ＣＤ'])->first();
    if (!$item) continue;

    // オレンジ倉庫 × アクト中食（EXTERNAL発注用）
    DB::connection('sakemaru')->table('item_contractors')->updateOrInsert(
        [
            'warehouse_id' => $orangeWarehouseId,
            'item_id' => $item->id,
            'contractor_id' => $akutoContractorId,
        ],
        [
            'supplier_id' => $akutoSupplierId,
            'is_auto_order' => true,
            'safety_stock' => $row['入数'],  // 入数 = 最低仕入単位
        ]
    );
}
```

**4-B: サテライト倉庫の移動依頼用 item_contractors**

各サテライト倉庫（店舗）の対象24商品について、**既存の contractor_id=1497（EXTERNAL直接発注）を contractor_id=9901（INTERNAL→オレンジ倉庫移動依頼）に変更**:

対象サテライト倉庫（DB調査済み）: 1(本店), 2(二の宮), 3(坂井), 4(サンドーム前), 7(光陽), 8(プラザ), 9(ヴィオ), 10(敦賀), 11(越前), 21(江守), 22(小浜), 23(クロスゲート金沢)
※ 71(特販課), 80(輸入課), 98(営業部石川) は店舗ではないため要確認

```php
// サテライト倉庫（店舗）の対象商品: contractor_id 1497→9901 に変更
// 同時に is_auto_order=true, supplier_id=9901 に設定
$satelliteWarehouseIds = [1, 2, 3, 4, 7, 8, 9, 10, 11, 21, 22, 23];

DB::connection('sakemaru')->table('item_contractors')
    ->whereIn('warehouse_id', $satelliteWarehouseIds)
    ->whereIn('item_id', $frozenItemIds)
    ->where('contractor_id', 1497)
    ->update([
        'contractor_id' => 9901,        // オレンジ倉庫用INTERNAL
        'supplier_id' => 9901,          // 対応するsupplier
        'is_auto_order' => true,        // 自動発注ON
    ]);
```

**4-C: 倉庫91のitem_contractors無効化**

91は今後これらの商品を発注しない（オレンジ倉庫が担当）:

```php
DB::connection('sakemaru')->table('item_contractors')
    ->where('warehouse_id', 91)
    ->whereIn('item_id', $frozenItemIds)
    ->where('contractor_id', 1497)
    ->update(['is_auto_order' => false]);
```

**注意**: レコード自体は削除せず `is_auto_order=false` に更新。ロールバック時に復旧可能。

#### Phase 5: オレンジ倉庫発注時のCSV出力

**要件**: オレンジ倉庫で発注が必要だった場合、アクト中食への発注依頼データとしてCSV作成。

**実装**: 既存の `OrderDataFileService` で対応。
- 発注先別にCSVを生成する機能が既にある
- オレンジ倉庫(901) × アクト中食(1497) の組み合わせも既存ロジックで自動対応
- `wms_contractor_settings` に `transmission_type=MANUAL_CSV` を設定すれば、管理画面からCSVダウンロード可能
- **追加実装不要**

### DB変更（マイグレーション不要・Seederのみ）

| # | 操作 | テーブル | 内容 |
|---|------|---------|------|
| 1 | INSERT | `warehouses` | オレンジ冷凍倉庫（code=901）、倉庫91のコピー |
| 2 | INSERT | `contractors` | id=9901 "LW華本部 物流課（オレンジ）" |
| 3 | INSERT | `suppliers` | id=9901（contractor 9901に対応） |
| 4 | INSERT | `wms_contractor_settings` | contractor_id=9901, INTERNAL, supply_warehouse_id=901のID |
| 5 | UPDATE | `wms_contractor_settings` | contractor_id=1497: receive_format='CSV', is_receive_enabled=true |
| 6 | INSERT | `wms_warehouse_auto_order_settings` | オレンジ倉庫の自動発注有効化 |
| 7 | INSERT | `item_contractors` | 24商品 × オレンジ倉庫(901) × アクト中食(1497)、safety_stock=入数 |
| 8 | UPDATE | `item_contractors` | サテライト12倉庫 × 24商品: contractor_id 1497→9901, is_auto_order=true |
| 9 | UPDATE | `item_contractors` | 倉庫91 × 24商品: is_auto_order=false |

### モデル変更

変更なし（既存モデルで対応可能）。

### サービス変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php` | 得意先コード→倉庫マッピング定数 + `saveSlipGroup()` 変換ロジック追加 |

**`OrderCandidateCalculationService` の変更は不要**: データ設定（wms_contractor_settings + item_contractors）のみで既存ロジックが自動的にオレンジ倉庫への移動依頼を生成する。

### UI変更

なし（既存の入荷予定一覧で自動的にオレンジ倉庫分が表示される）。

## 影響範囲

| 機能 | 影響 | リスク |
|------|------|--------|
| 入荷予定生成 | CSVアップロード時の倉庫マッピング変更 | 低: マッピング定義追加のみ |
| 自動発注計算 | サテライト→オレンジ倉庫移動依頼が新規追加 | 低: データ設定のみで既存コード変更なし |
| 自動発注計算 | オレンジ倉庫→アクト中食の外部発注が新規追加 | 低: データ設定のみ |
| 倉庫間移動 | サテライト→オレンジ倉庫の新経路追加 | 中: delivery_course_id のマッピング（warehouse_stock_transfer_delivery_courses）が必要か確認 |
| 入荷検品（Handy） | オレンジ倉庫の入荷検品対応 | 低: 倉庫マスタ追加で自動対応 |
| 仕入データ生成 | オレンジ倉庫の仕入データ生成 | 低: 既存フローで対応 |
| CSV出力 | オレンジ倉庫発注CSV | 低: 既存OrderDataFileServiceで自動対応 |

## 制約

1. **FK禁止**: `warehouses` テーブルへのFK制約は追加しない（プロジェクト方針）
2. **migrate:fresh/refresh 禁止**: Seederのみでデータ投入（CLAUDE.md準拠）
3. **共有DB**: `warehouses`, `item_contractors` は基幹システム（sakemaru）と共有。変更は慎重に
4. **冪等性**: Seederは複数回実行しても安全であること（upsert or 存在チェック）
5. **wms_contractor_settings.contractor_id は UNIQUE**: 1コントラクタ1設定のみ

## 対象ファイル

### 新規作成
| ファイル | 内容 |
|---------|------|
| `database/seeders/OrangeWarehouseSeeder.php` | オレンジ冷凍倉庫の新設 + wms_contractor_settings + wms_warehouse_auto_order_settings |
| `database/seeders/AkutoFrozenItemContractorSeeder.php` | 対象24商品のitem_contractors設定（オレンジ倉庫EXTERNAL + サテライト倉庫INTERNAL変更 + 倉庫91無効化） |

### 既存変更
| ファイル | 内容 |
|---------|------|
| `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php` | 得意先コード→倉庫マッピング追加（SHOP_WAREHOUSE_MAP定数 + saveSlipGroup変換） |
| `database/seeders/InitSystemSeeder.php` | OrangeWarehouseSeeder + AkutoFrozenItemContractorSeeder 呼び出し追加 |

### 参照のみ
| ファイル | 参照理由 |
|---------|---------|
| `app/Services/AutoOrder/IncomingReceiveService.php` | 倉庫解決ロジック確認（変更不要） |
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | 移動依頼ルーティング確認（変更不要） |
| `app/Services/AutoOrder/TransferCandidateExecutionService.php` | 移動実行ロジック確認（変更不要） |
| `app/Models/WmsContractorSetting.php` | コントラクタ設定構造確認 |
| `app/Models/Sakemaru/Warehouse.php` | 倉庫モデル構造確認 |
| `app/Models/Sakemaru/ItemContractor.php` | item_contractors構造確認 |
| `storage/seeders/akuto-frozoz-items.csv` | 対象商品リスト（24商品 + 入数） |

## 確認事項（全件解決済み）

### 解決済み（DB調査結果含む）

| # | 質問 | 回答 |
|---|------|------|
| 1 | サテライト倉庫の移動依頼ルーティング | `wms_contractor_settings.supply_warehouse_id` で決定。contractor_idを変更すれば自動ルーティング。コード変更不要 |
| 2 | WmsContractorSetting の作成 | 必要。contractor_id=9901（新規）でINTERNAL + supply_warehouse_id=901 |
| 3 | safety_stock 値 | 入数（＝最低仕入単位）に設定。CSVの入数カラムから取得 |
| 4 | 発注CSVフォーマット | 既存のOrderDataFileServiceのフォーマットでよい。追加実装不要 |
| 5 | 91のINTERNALコントラクタID | **contractor_id=9012**（"LW華本部 物流担当"、wms_contractor_settings id=116） |
| 6 | オレンジ倉庫用INTERNALコントラクタの用意方法 | **新規作成: contractors id=9901, suppliers id=9901**（9012のパターンに倣う。id 9901は未使用） |
| 7 | warehouse_stock_transfer_delivery_courses | **テーブルは空**（0件）。現状使われていないため、オレンジ倉庫用の設定も不要 |
| 8 | 既存のサテライトitem_contractors | **contractor_id=1497を9901に UPDATE**（削除ではなく更新）。倉庫91は is_auto_order=false に更新 |
| 9 | 対象商品数 | CSVは25行だが、items テーブルで **24件** ヒット（全件存在確認済み。621332のJANコードが611030と重複しているがitem自体は別レコード） |

### 実装時の注意点

| # | 内容 |
|---|------|
| 1 | サテライト倉庫のうち 71(特販課), 80(輸入課), 98(営業部石川) は店舗ではない可能性。これらのitem_contractorsも更新するか要判断 |
| 2 | contractors/suppliers テーブルにid=9901を明示的にINSERTする際、auto_increment(90001)との衝突はないが、partners テーブルにも id=9901 のレコードが必要か確認 |
| 3 | Seeder冪等性: 全INSERT/UPDATEは `updateOrInsert` または存在チェック付きで実装すること |
