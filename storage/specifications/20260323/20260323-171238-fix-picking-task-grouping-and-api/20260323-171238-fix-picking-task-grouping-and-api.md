# ピッキングタスク生成グルーピング修正 & API パラメータ整理

- **作成日**: 2026-03-23
- **ステータス**: ドラフト
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms

## 背景・目的

ピッキングタスクは「倉庫別 × 配送コース別 × 階数(floor)別 × エリア(picking_area)別」に分離される必要がある。
現状の波動生成では `floor_id` のみでグルーピングしており、同じフロアに複数のピッキングエリアが存在する場合、異なるエリアのアイテムが1つのタスクにまとめられてしまう。

また、API側の `GET /api/picking/tasks` に不要な `picking_area_id` パラメータが存在する。`picking_area_id` が決まれば `floor_id` も一意に決まるため、タスク自体がエリア別に分離されていれば `warehouse_id` + `picker_id` でのフィルタで十分。

## 現状の実装

### 波動生成（GenerateWavesCommand）

- **Earning処理（258行目）**: `$groupKey = ($floorId ?? 'null')` — floor_id のみでグルーピング
- **Stock Transfer処理（489行目）**: `$groupKey = 'ST_'.($floorId ?? 'null')` — 同じく floor_id のみ
- `picking_area_id` はグループの最初のアイテムの値を代表値として使用（262行目）
- 同じフロアに異なるエリアのアイテムがあっても1つのタスクにまとまる

### API（PickingTaskController::index）

- パラメータ: `warehouse_id`（必須）, `picker_id`（任意）, `picking_area_id`（任意）
- `picking_area_id` でフィルタ可能だが、生成時にエリア別分離されていないため不正確

## 変更内容

### 概要

1. 波動生成のグルーピングキーを `floor_id` → `floor_id × picking_area_id` に変更
2. API `GET /api/picking/tasks` から `picking_area_id` パラメータを削除

### 詳細設計

#### 1. GenerateWavesCommand — Earning処理のグルーピング変更

**ファイル**: `app/Console/Commands/GenerateWavesCommand.php`

**変更箇所**: 258行目付近

```php
// 変更前
$groupKey = ($floorId ?? 'null');

// 変更後
$groupKey = ($floorId ?? 'null') . '_' . ($pickingAreaId ?? 'null');
```

グループデータの `picking_area_id` は既に保持されているため（262行目）、グルーピングが細分化されることで各グループ内のエリアは自動的に統一される。追加の変更は不要。

#### 2. GenerateWavesCommand — Stock Transfer処理のグルーピング変更

**ファイル**: `app/Console/Commands/GenerateWavesCommand.php`

**変更箇所**: 489行目付近

```php
// 変更前
$groupKey = 'ST_'.($floorId ?? 'null');
$existingTask = DB::connection('sakemaru')
    ->table('wms_picking_tasks')
    ->where('wave_id', $wave->id)
    ->where('floor_id', $floorId)
    ->first();

// 変更後
$groupKey = 'ST_'.($floorId ?? 'null') . '_' . ($pickingAreaId ?? 'null');
$existingTask = DB::connection('sakemaru')
    ->table('wms_picking_tasks')
    ->where('wave_id', $wave->id)
    ->where('floor_id', $floorId)
    ->where('wms_picking_area_id', $pickingAreaId)
    ->first();
```

既存タスクの検索条件にも `wms_picking_area_id` を追加する。これにより、同じフロアでもエリアが異なれば別タスクとして生成される。

#### 3. API パラメータから picking_area_id を削除

**ファイル**: `app/Http/Controllers/Api/PickingTaskController.php`

**変更箇所**: index メソッド（260-290行目付近）

- バリデーションから `picking_area_id` を削除
- `$pickingAreaId` 変数と対応するクエリフィルタを削除
- Swagger ドキュメントから `picking_area_id` パラメータを削除（142-149行目）

### 影響範囲

| 影響対象 | 内容 |
|---|---|
| 波動生成コマンド | タスク数が増加する可能性（同フロア・異エリアが分離されるため） |
| Android アプリ | `picking_area_id` パラメータを送信している場合、削除が必要（無視されるだけなので破壊的変更ではない） |
| ピッキングリストPDF | タスク単位で出力している場合、出力単位が細分化される |
| Filament管理画面 | ピッキングタスク一覧の表示件数が増加する可能性 |

## 制約

- `php artisan migrate:fresh` / `migrate:refresh` は禁止（共有DB）
- 外部キーは使用しない
- 既存データのピッキングタスクは再生成（`--reset`）しない限り旧グルーピングのまま

## 対象ファイル

### 既存変更
- `app/Console/Commands/GenerateWavesCommand.php` — グルーピングキー変更（Earning + Stock Transfer）
- `app/Http/Controllers/Api/PickingTaskController.php` — `picking_area_id` パラメータ削除 + Swagger更新

### 参照のみ
- `app/Models/WmsPickingArea.php` — エリアモデル構造の確認
- `app/Models/WmsPickingTask.php` — タスクモデル構造の確認

## 確認事項

1. **Androidアプリ**: `picking_area_id` パラメータを現在送信しているか？送信している場合、バリデーションで弾かれないようにするか、アプリ側も同時に修正するか
アプリ修正予定（来ても無視するように）
2. **既存データ**: 本番環境で既に生成済みのタスクについて、再生成（`--reset`）の実施タイミング
既存データは考慮しなくても良い
3. **picking_area_id が null のケース**: ロケーションにエリアが未設定の商品は `picking_area_id = null` グループとしてまとめられるが、それで問題ないか
`picking_area_id = null` グループとしてまとめられるが、それで問題ない.
