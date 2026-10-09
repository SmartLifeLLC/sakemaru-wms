# 外部発注（本部）

本部 91 番倉庫（華むすびの蔵センター）専用の発注登録画面。
既存の「（新）外部発注」に対する本部からの修正依頼を、既存画面を変えずに別メニューとして実装したもの。

- メニュー: 発注 > 発注処理 > 外部発注（本部）
  - 発注処理は5件になりメガメニューが2列表示になるため、（新）外部発注が従来どおり左列の2番目に出る並びにしている。
    左列: 物流発注(店間） / （新）外部発注 / 発注確定待ち、右列: 外部発注（本部） / 外部発注
- URL: `/admin/wms-order-registration-hq`
- 権限: 既存の（新）外部発注と同じ `wms.wms-order-candidate.view`（権限マスタの追加は不要）

## 既存の（新）外部発注との関係（独立性）

- ページは `WmsOrderRegistrationHq`。`WmsOrderRegistration` を継承し、本部向けの違いだけを上書きしている。
- 既存のページ・Blade・サービス（`WmsOrderRegistration` / `order-registration-*.blade.php` /
  `OrderRegistrationSearchService` / `OrderRegistrationService` など）は1行も変更していない。
- 既存側の変更は `EMenu` へのメニュー1件追加と、それに伴う並び順の番号（（新）外部発注 2→3、外部発注 3→4、発注確定待ち 4→5）だけ。
- 発注確定（発注確定済みデータ・入荷予定・FAX/PDF・JX 生成）は親の処理をそのまま使う。登録されるデータの形は既存と同じ。
- 継承なので、既存の（新）外部発注に入れた修正は本部画面にも反映される。本部だけ変えたい表示は本部用 Blade を直す。

## 倉庫

`config/wms_hq_order_registration.php` の `warehouse_code`（既定 91）に固定。
ログインユーザーの選択倉庫には関係なく、常に本部倉庫で検索・登録する。

## 登録リスト

- 発注先CD: クリックで開く検索付きプルダウン。商品に紐づく発注先を先頭に表示し、発注先CD（数字）または名前（2文字以上）で検索できる。
  変更後の扱い（EOS可否・FAX固定・入荷予定日・仕入原価の再計算）は既存の「変更」ボタンと同じ。
  同じ発注先を選び直した場合は何も変えない。
- 納入先: 倉庫コードのみ表示（倉庫名はマウスオーバーで表示）。発注先の前（入荷予定日の次）に置く。
- 発注先名: 発注先CD の下に省略せず表示する（発注先名だけの列は持たない）。
- 商品: 列名は「[商品CD]商品名」。`[100123] 商品名` の形で1列にまとめる。列幅は固定（14rem）で、長い名前は自動改行する。
- 容量x入数: `750ml x 6` の形で表示する。容量は商品マスタの容量＋単位（`items.volume` / `volume_unit`）、入数はケース入数。
  容量が未登録（0）の商品は `- x 6`。入数だけの列は持たない。
- 発注区分のプルダウンは文字幅に合わせた幅にしている。
- 合計表示と列の並びは「総ケース数 → 総バラ数」（既存画面と同じ）。
- 発注数の判断に使う参考列を追加: 発注点 / 理論在庫 / 最終入荷予定（日・数）/ 最終仕入（日・数）/ 1週 / 2週 / 3週 / 前月。
  - 行を追加した時点の値を保持する。「参考データ更新」ボタンで取り直す。
  - 納入先を他倉庫に複製した行は、その倉庫の値を表示する。

## 発注候補検索

- 削除: 納品予定数・見込在庫。
- 最終発注日 → 最終入荷予定日。あわせて最終入荷予定数をケース・バラで表示。
- 納品予定日 → 最終仕入日。あわせて最終仕入数をケース・バラで表示。
- 1週・2週・3週・前月は、店舗（敦賀・小浜）の卸実績を除いた数量。

## 外部発注候補生成（販売履歴から生成）

- 中央: 発注先。EOS発注先・FAX発注先を1つの一覧にまとめ、EOS 対応の発注先には「EOS」バッジを付ける。
- 右: 仕入先。チェックした発注先に紐づく仕入先だけを表示する。
  - 発注先をチェックすると、紐づく仕入先はチェック済みで表示される。外したい仕入先のチェックを外して絞り込む。
  - 発注先のチェックを外すと、紐づきが無くなった仕入先は一覧から消える。
- 候補リストは、選択した発注先かつ選択した仕入先の商品だけになる。
- 候補リストの列は発注候補検索と同じ（＋実績合計）。実績合計・候補の抽出条件も店舗の卸実績を除く。
  除いた結果、期間内の実績が 0 以下になる商品は候補に出さない。

## モーダルの重なり順

- ページ本体は `.fi-page-header-main-ctn`（`theme.css` で `position: relative; z-index: 60`）の中に描画される。
  上部メニューは `z-index: 100` なので、ページ内のモーダルは自分の z-index をいくら上げてもメニューの下になる。
- 本部画面では、全画面モーダルに `data-hq-modal` を付け、モーダル表示中だけ入れ物ごと前面に上げている
  （ページ Blade 先頭の `<style>`: `.fi-page-header-main-ctn:has([data-hq-modal]) { z-index: 9999 !important; }`）。
  通知（z-index: 10000）よりは下。
- 全画面モーダルを追加するときは `data-hq-modal` を付けること（テストで確認している）。
- 既存の（新）外部発注は未対応で、モーダル上端がメニューに隠れる（既存ファイルは変更しない方針のため）。

## データの定義

### 最終入荷予定日 / 最終入荷予定数

- `wms_order_incoming_schedules` のうち、キャンセル・一部キャンセル・削除以外で一番新しい `expected_arrival_date`。
- 数量はその日の `expected_quantity` の合計。ケース発注分はケース、バラ発注分はバラ（ボールはバラ換算）。
- 入荷確定済みの予定も対象。

### 最終仕入日 / 最終仕入数

- 予定ではなく確定済みの仕入データ。`purchases`（有効・通常伝票）＋ `trade_items`（数量 > 0）。
- 一番新しい `purchases.delivered_date` と、その日の数量（ケース・バラ）。返品は含めない。
- 探す範囲はシステム日付から直近3ヶ月（`last_purchase_lookback_months`）。それより前にしか仕入が無ければ「-」。

### 店舗（敦賀・小浜）の卸実績の除外

- 対象: 売上（`earnings`）の計上倉庫が本部で、配送コースの倉庫が除外対象倉庫（既定 10 敦賀店 / 22 小浜店、
  およびそれらを在庫倉庫とする仮想倉庫）のもの。計上は本部だが本部倉庫からは出荷されない。
- 実績は既存と同じ `stats_item_warehouse_daily_sales`（計上倉庫単位）から取り、上記の数量（バラ換算）を差し引く。
- 実績集計バッチ（30分ごと）と売上データの時点差でマイナスにならないよう、元の実績が 0 以上のときは 0 で止める。
- 除外対象倉庫は `excluded_sales_course_warehouse_codes` で変更できる。本部倉庫以外の行には適用しない。

### 発注先と仕入先の紐づけ

- 本部倉庫の商品発注先マスタ（`item_contractors`）に実在する（発注先, 仕入先）の組。
- 候補リストは商品発注先マスタから作るので、ここに無い組は選んでも候補が出ない。

## ファイル

- `config/wms_hq_order_registration.php`
- `app/Filament/Pages/WmsOrderRegistrationHq.php`
- `app/Services/AutoOrder/HqOrderRegistrationReferenceService.php`
- `resources/views/filament/pages/wms-order-registration-hq.blade.php`
- `resources/views/filament/components/hq-order-registration-candidate-create-items.blade.php`
- `resources/views/filament/components/hq-order-registration-sales-preview-edit.blade.php`
- `resources/views/filament/components/hq-order-registration-contractor-supplier-selection.blade.php`
- `app/Enums/EMenu.php`（`WMS_ORDER_REGISTRATION_HQ` を追加）
- `tests/Unit/Services/AutoOrder/HqOrderRegistrationReferenceServiceTest.php`
- `tests/Unit/Filament/WmsOrderRegistrationHqViewTest.php`
- `tests/Feature/AutoOrder/HqOrderRegistrationIntegrationTest.php`

## 反映時の注意

- マイグレーションは無い。
- 新しい設定ファイルと Blade を追加しているので、`php artisan cache:hard-clear` と `npm run build` が必要（deploy.sh に含まれる）。
- 設定キャッシュが古くても動くよう、倉庫CD・除外倉庫は未設定時に 91 / 10・22 を既定値にしている。
