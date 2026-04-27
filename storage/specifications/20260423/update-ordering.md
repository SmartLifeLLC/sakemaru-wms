新規タスク
1. 発注関連
1.1 admin/wms-stock-transfer-candidates および　admin/wms-order-candidates　画面上で詳細ボタンを押した際に、「発注点」があるが、これと計算情報の安全在庫の違いを確認
何が実際に発注・移動候補生成で使われるかを確認。使われるもので統一（安全在庫もしくは発注店が同じものであれば名称のみ統一。）発注計算でつかわれる項目を詳細モーダルでで変更できるように。倉庫別の設定を変えられる。
入力フォームは作るが、変更を保存を押した時に発注点もしくは安全在庫を変更保存するように。
1.2 admin/wms-order-candidates の生成元カラムが英語（MANUAL_SAFETY_STOCK, MANUAL_SALES_BASED)ラベルになっているが日本語に変更
1.3 admin/wms-stock-transfer-candidates で表示される検索CDを発注CDに名称変更
1.4 admin/wms-order-candidates,admin/wms-stock-transfer-candidatesで表示される発注CDはitem_search_informationのcan_use_for_orderingになっているか確認
1.5 admin/wms-order-candidates,admin/wms-stock-transfer-candidatesをの詳細を押した時のモーダルに発注CD変更可能な機能を追加
まず発注候補レコードは発注CDをテーブルに保存しているかを確認（候補テーブルに保存が必須).変更時には該当商品のCDリストをitem_search_informationより全て取得する。それをセレクト表示できるように。 
これはモーダルが呼ばれた時に取得すれば良い。
1.6 admin/wms-order-data-files?activePresetView=all&currentPresetView=all で発注ファイルリストにおいて、bulk actionを作る。 メール、CSV, FAX.
メールとFAXの場合、文言の入力はスキップしてbulk actionになる。　メールの場合メールアドレスが設定されてない発注データは（発注先）は送信しても送信スキップになり、送信完了時刻を表示（更新）しない。
メールは一度送信になったら２度送信は禁止になる。
テーブルカラムの発注数・合計数量の右側に相手送信にメールの設定がされている場合は(admin/contractors/1000/edit?tab=fa-zhumeru-she-ding%3A%3Adata%3A%3Atab)メール送信そうでなければ手動送信と表記するカラムを作る。
これは発注データファイルテーブルにカラムを追加し、データ生成時に保存する。(is_mail_order = true| false). JXの送信の場合も基本falseになるのでdefault false.
   admin/contractors/1000/edit?tab=fa-zhumeru-she-ding%3A%3Adata%3A%3Atab の発注先メールアドレスのinputに注意書きとして、このメールアドレスが登録されていると、メール送信発注先になります。と記載。






