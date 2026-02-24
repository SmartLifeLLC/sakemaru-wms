# Work Plan: stock-transfer-map-view

- **ID**: stock-transfer-map-view
- **作成日**: 2026-02-25
- **最終更新**: 2026-02-25
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/stock-transfer-map-view/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

欠品対応-横持ち出荷指示モーダルに Leaflet.js 配送ルートマップを追加する。出発倉庫・候補倉庫・納品先を地図上にマーカー表示し、倉庫選択時にルート線を描画、総移動距離を表示する。

## 重要な設計制約

- **FK禁止**: 全テーブルにForeignKeyは作成しない
- **DB破壊禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` は絶対禁止
- **テーブルプレフィックス**: `wms_` は手動命名
- **Filament 4パターン**: 正しいインポートパスを使用
- **WmsModel基底クラス**: WMS用テーブルは `WmsModel` を継承し `sakemaru` コネクションを使用
- **Leaflet CDN**: npm追加不可、CDN経由で読み込み
- **既存モーダル維持**: 現在の横持ち出荷指示モーダルの機能を壊さない

## 対象ファイル

### 新規作成
- なし（既存Bladeテンプレートにマップを埋め込む）

### 既存変更
- `resources/views/filament/forms/components/proxy-shipment-allocations.blade.php` - マップコンポーネント追加
- `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php` - viewDataに位置情報追加、モーダル幅拡大
- `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php` - 同上

### 参照のみ（変更禁止）
- `app/Models/Sakemaru/Warehouse.php` - latitude, longitude カラム確認済
- `app/Models/Sakemaru/Partner.php` - latitude, longitude カラム確認済
- `app/Models/WmsModel.php`
- `app/Models/WmsShortage.php`

## データ確認結果

- **Warehouse**: `latitude`, `longitude` カラムあり。約30件中27件にデータあり
- **Partner**: `latitude`, `longitude` カラムあり。31,975件中30,249件（94.6%）にデータあり
- **Leaflet**: プロジェクト未導入。CDN経由で新規追加

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: バックエンド（viewData に位置情報追加） | 完了 | 2026-02-25 | locations配列を両テーブルに追加 |
| P1: フロントエンド（Leafletマップ＆ルート描画） | 完了 | 2026-02-25 | Leaflet CDN + Alpine.jsマップ実装 |
| P2: モーダル幅拡大＆レイアウト調整 | 完了 | 2026-02-25 | 7xl + grid 5cols (3+2) |
| P3: 動作確認・テスト | 完了 | 2026-02-25 | php -l OK, Pint OK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 位置情報カラム名
- Warehouse: latitude, longitude
- Partner: latitude, longitude

### 既存モーダル情報
- モーダルクラス: `proxy-shipment-modal`（extraModalWindowAttributesで付与済み）
- スクロール領域: `max-h-[55vh]`
- 現在のモーダル幅: デフォルト（modalWidth未設定）
- ヘッダー色: 紺色(#1e3a5f)・白文字

### Git ブランチ
- 作業ブランチ: feature/stock-transfer-map-view
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P0: バックエンド（viewData に位置情報追加）
- 完了日: 2026-02-25
- 実績:
  - WmsShortagesTable.php: viewDataに locations 配列追加（departure/warehouse/customer）
  - WmsShortagesWaitingApprovalsTable.php: 同上
  - modalWidth('7xl') を両ファイルのアクションに追加

### P1: フロントエンド（Leafletマップ＆ルート描画）
- 完了日: 2026-02-25
- 実績:
  - Leaflet を JavaScript 動的ローダーで読み込み（Livewire モーダル対応）
  - Alpine.js x-data に map初期化、マーカー表示、ルート描画、距離計算メソッド追加
  - $watch('state') で倉庫選択連動のルート更新を実装
  - Haversine距離計算で総移動距離をリアルタイム表示

### P2: モーダル幅拡大＆レイアウト調整
- 完了日: 2026-02-25
- 実績:
  - grid grid-cols-5 gap-4 の2カラムレイアウト（左col-span-3 / 右col-span-2）
  - 左カラム: 既存の在庫リスト＋横持ち出荷指示（max-h-[55vh]スクロール維持）
  - 右カラム: 配送ルートマップ（420px高さ, sticky配置）
  - 凡例: 出発倉庫(黒)、候補倉庫(青)、納品先(赤)、選択倉庫(黄)

### P3: 動作確認・テスト
- 完了日: 2026-02-25
- 実績:
  - php -l: 全3ファイル構文エラーなし
  - Pint: フォーマット適用済み（PASS）
  - 既存機能: Blade内の在庫リスト・横持ち出荷指示の全コードを維持
  - Puppeteer ヘッドレステスト: 全10テスト合格（動的ローダー、マーカー5個、ルート描画、距離計算、マーカー強調）
