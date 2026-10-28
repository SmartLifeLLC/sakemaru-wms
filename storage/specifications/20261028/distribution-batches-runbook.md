# 本部分配の分配管理・Excel取込：反映・検証手順

## 対象と互換性

本部分配のみ親IDと親単位の更新番号へ移行する。直送の保存形式と倉庫単位の更新番号、通常・自動発注、FAX/JXの対象判定は維持する。新UIは `/admin/distribution-adjustment` が一覧、`/{batch}` が詳細。店舗別管理は複数の分配を横断して表示する。

今回の実装検証は独立worktreeと専用DBで実施。本番への適用、コミット・マージは別途指示が必要。

## 切替前

1. 接続先、現在のコミット、対象DB、`wms_distribution_rows` の件数・倉庫別件数を記録する。DBバックアップと、行のID・業務キー・並び順・row_data・生成関連・作成更新日時のハッシュをGit外へ保管する。
2. 本部編集を停止し、既存タブを閉じる。受付中の倉庫移動・発注workerを完了させる。移行処理で伝票や発注を生成しない。
3. 基幹側の既存前提 `stock_transfer_queue.picking_date` と対応workerが適用済みか確認する。今回、基幹コードの追加修正はない。
4. PHP `upload_max_filesize` を20M以上、`post_max_size` をそれより大きく、Webサーバーの本文上限も20MiB超にする。アプリ上限は20MiB。大量ファイルに備えPHP memory_limit=512Mを推奨する。
5. 追加する2本のmigrationを確認する。既存明細へnullable列と2索引を追加するため、件数に応じたDDLの所要時間・ロックを事前確認する。

## 適用

1. 編集停止中にコードとビルド済みassetsを反映する。
2. `php artisan migrate` で未適用migrationを確認のうえ適用する。対象は `2026_10_28_000001_create_distribution_batches` と `2026_10_28_000002_create_distribution_batch_events`。
3. `php artisan wms:migrate-distribution-batches` でdry-run。顧客・倉庫・未所属行数をバックアップの集計と照合する。
4. `php artisan wms:migrate-distribution-batches --execute` で「初回分配」へ関連付ける。変更は親IDだけ。直送は対象外。再実行しても親は増殖しない。
5. 未所属allocation行が0、業務項目ハッシュ・数量・ID・業務キー・並び順・伝票関連が変更されていないことを確認する。論理削除行も所属を持つが復活しない。
6. 旧APIからの本部保存・生成が409、新一覧と初回分配詳細、直送、店舗別管理が正常なことを確認し、編集を再開する。旧タブは開き直す。

## Excel操作

数量シートを物理的な先頭に置く。アップロード時に数量単位（ケース／バラ）と見出し（店舗名／店舗コード）を選択する。不明列は店舗または対象外を明示し、再確認する。

先頭シート・見出し行・新規／更新／変更なし／スキップ・店舗別の前後数量・入数差・直送参考欄を確認して実行する。ケースは商品マスタ入数で換算。既存商品は数量を上書きし、店舗列の空欄は0、店舗列の欠落は既存数を保持する。生成受付済み行、数量不正、重複商品、列構成変更は取込を止める。

上限：20MiB、10,000行、256列、商品6,000行、数量99,999,999。マクロ・外部リンク・数式の再計算は実行せず、保存済みの数式結果のみ参照する。取込自体は倉庫移動・発注を生成しない。

## 同時利用と監査

別分配は独立して保存。同じ分配の古い更新番号は409とし、入力内容を控えて再読込する。共同セル編集の自動統合は行わない。ヘッダー変更、生成、Excel取込も同じ親ロック下で処理する。生成受付はworker完了と区別し、処理状況は実受付の状態から算出する。

`wms_distribution_imports` に実行者・ファイルSHA-256・シート・設定・前後差分を記録。`wms_distribution_batch_events` に通常保存とヘッダー変更を記録。原本ファイルは永続保存せず、非公開の解析JSONは30分有効、24時間後に削除。日次schedulerの `wms:prune-distribution-previews` を有効にする。

## 切戻し

追加列・親データ・監査履歴は残す。2本のmigrationのdownは、入力済みの所属を失わないよう停止する仕様。旧UIへの無条件なコード巻戻しは、親を無視して全分配をまとめて保存するため禁止。編集を停止し、親IDの範囲制約と旧APIガードを保持した互換版へ戻す。移動・発注・在庫を切戻しのために削除しない。

## 再検証

専用test DBを明示して `tests/Unit/Distribution*Test.php`、`tests/Feature/Api/Distribution*Test.php`、`tests/Feature/AutoOrder/DirectDistributionOrderIntegrationTest.php` を実行する。`CLIENT_ID`、mysql・sakemaruの両接続が専用DBか確認する。DB全消去系コマンドとRefreshDatabaseは使用しない。

`node --test tests/js/distribution-review.test.mjs`、`npm run build`、`php artisan view:cache` を確認する。添付5件、再取込、別利用者の別分配保存・同分配競合、ブラウザ操作、実workerから在庫・ロット・受け払い・月次集計までを検証する。
