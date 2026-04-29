# モーダルデザイン統一 修正済みチェックリスト

全34件修正完了（2026-04-22）。全25ファイル `php -l` PASS。

## 手動チェック方法

1. https://wms.sakemaru.test にログイン
2. 各メニューに移動し、該当アクションボタンをクリック
3. 確認ポイント:
   - ヘッダーが紺色（#1e293b）になっているか
   - フッターボタンが右寄せになっているか
   - 送信ボタンが赤色（danger）になっているか
   - キャンセルラベルが「〜せず閉じる」形式になっているか

---

## カテゴリA: 確認モーダル（1件）

| # | メニューパス | アクション | CSSクラス | チェック |
|---|-------------|----------|----------|---------|
| A1 | 発注処理 > 移動候補一覧 | toggleAutoOrder（発注OFF） | incoming-detail-modal | [ ] |

## カテゴリB: 表示専用モーダル（8件）

| # | メニューパス | アクション | CSSクラス | cancel | チェック |
|---|-------------|----------|----------|--------|---------|
| B1 | ログ > 自動発注実行ログ | viewTransmission（送信詳細） | incoming-detail-modal | 閉じる | [ ] |
| B2 | ログ > 自動発注実行ログ | viewError（エラー詳細） | incoming-detail-modal | 閉じる | [ ] |
| B3 | 発注処理 > 発注・移動候補生成 | viewResult（結果） | incoming-detail-modal | 閉じる | [ ] |
| B4 | 発注履歴 > 発注確定済み | viewDetail（詳細） | incoming-detail-modal | 閉じる | [ ] |
| B5 | 入荷管理 > 入荷データ受信 | viewSlips（伝票一覧） | incoming-detail-modal | 閉じる | [ ] |
| B6 | ログ > Queueジョブ | viewDetail（詳細） | incoming-detail-modal | 閉じる | [ ] |
| B7 | 出荷管理 > 配送コース変更 | viewDetails（伝票） | incoming-detail-modal | 閉じる | [ ] |
| B8 | 在庫管理 > 在庫管理 | view（詳細） | incoming-detail-modal | 閉じる | [ ] |

## カテゴリC: フォームモーダル（18件）

| # | メニューパス | アクション | CSSクラス | submit | cancel | チェック |
|---|-------------|----------|----------|--------|--------|---------|
| C1 | （全テーブル共通） | export（ダウンロード） | incoming-detail-modal | エクスポート | エクスポートせず閉じる | [ ] |
| C2 | 倉庫マスタ > 移動配送コース設定 | create（新規作成） | incoming-detail-modal | 作成 | 作成せず閉じる | [ ] |
| C3 | 倉庫マスタ > 移動配送コース設定 | edit（編集） | incoming-detail-modal | 更新 | 更新せず閉じる | [ ] |
| C4 | 出荷管理 > 出荷波動管理 | printPickingList（ピッキングリスト出力） | picking-list-modal | PDF出力 | 出力せず閉じる | [ ] |
| C5 | 出荷管理 > 出荷波動管理 | generateWave（出荷波動生成） | wave-modal | 波動を生成 | 生成せず閉じる | [ ] |
| C6 | 発注処理 > 発注・移動候補生成 | orderGenerationWizard（発注・移動候補生成） | incoming-detail-modal | (ウィザード) | (ウィザード) | [ ] |
| C7 | 入荷管理 > 入荷データ受信 | uploadJxFile（JXデータ取込） | incoming-detail-modal | アップロード | アップロードせず閉じる | [ ] |
| C8 | 入荷管理 > 入荷データ受信 | uploadCsvFile（CSVデータ取込） | incoming-detail-modal | アップロード | アップロードせず閉じる | [ ] |
| C9 | 入荷管理 > 入荷予定 | uploadCsv（CSV一括登録） | incoming-detail-modal | 登録 | 登録せず閉じる | [ ] |
| C10 | 入荷管理 > 入荷予定 | bulkUpdateDates（入荷日・賞味期限を更新） | incoming-detail-modal | 一括更新 | 更新せず閉じる | [ ] |
| C11 | 発注履歴 > 発注データファイル | sendMail（メール） | incoming-detail-modal | 送信 | 送信せず閉じる | [ ] |
| C12 | 発注マスタ > 月別発注点 | importCsv（発注点CSVインポート） | incoming-detail-modal | インポート | インポートせず閉じる | [ ] |
| C13 | 発注マスタ > 月別発注点 | importAnalysisCsv（発注点分析CSVインポート） | incoming-detail-modal | インポート | インポートせず閉じる | [ ] |
| C14 | 出荷管理 > ピッキングタスク | assignPickers（ピッカー割り当て） | picker-assign-modal | 割当 | 割当せず閉じる | [ ] |
| C15 | 出荷管理 > ピッキングタスク | unassignPickers（割り当て解除） | picker-assign-modal | 解除 | 解除せず閉じる | [ ] |
| C16 | 発注処理 > 移動候補一覧 | bulkUpdateCourseAndDate（コース・入荷日変更） | bulk-update-course-date-modal | 変更を適用 | 変更せず閉じる | [ ] |
| C17 | 入荷管理 > 仕入連携済み | viewDetail（詳細） | incoming-detail-modal | (表示のみ) | 閉じる | [ ] |
| C18 | 出荷管理 > ピッキングタスク | picking_ready（ピッキング準備完了） | incoming-detail-modal | 出庫準備完了 | 出庫準備せず閉じる | [ ] |

## カテゴリD: 欠品・横持ち出荷系モーダル（7件）

| # | メニューパス | アクション | CSSクラス | submit | cancel | チェック |
|---|-------------|----------|----------|--------|--------|---------|
| D1 | 欠品管理 > 欠品一覧 | createProxyShipment（欠品対応） | proxy-shipment-modal | 欠品対応確定 | 確定せず閉じる | [ ] |
| D2 | 欠品管理 > 欠品一覧 | viewProxyShipment（対応確認） | incoming-detail-modal | (表示のみ) | 閉じる | [ ] |
| D3 | 欠品管理 > 欠品承認済み | viewProxyShipment（詳細） | incoming-detail-modal | (表示のみ) | 閉じる | [ ] |
| D4 | 欠品管理 > 承認待ち欠品 | editProxyShipment（欠品編集） | proxy-shipment-modal | 保存 | 保存せず閉じる | [ ] |
| D5 | 欠品管理 > 承認待ち欠品 | viewProxyShipment（詳細） | incoming-detail-modal | (表示のみ) | 閉じる | [ ] |
| D6 | 倉庫移動 > 横持ち出荷依頼 | addPartialShipment（修正） | proxy-shipment-modal | 確定 | 確定せず閉じる | [ ] |
| D7 | 倉庫移動 > 横持ち出荷依頼 | syncAllocations（データ同期） | incoming-detail-modal | (既存維持) | (既存維持) | [ ] |
