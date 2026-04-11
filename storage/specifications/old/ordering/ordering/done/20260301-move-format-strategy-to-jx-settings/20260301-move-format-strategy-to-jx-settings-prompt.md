[x] /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/ordering/20260301-move-format-strategy-to-jx-settings/20260301-move-format-strategy-to-jx-settings-boot.md
[x] データなし時にAレコードを送信 => これがDBに設定されているが、これは生成クラスが担保すべき。HanaOrderJXFileGenerator　を継承したHanaOrderJXFileGenerator2を作成。
HanaOrderJXFileGenerator2はデータなし時にAレコードを送信しない。HanaOrderJXFileGeneratorにprotected int add_zero_record = trueにし HanaOrderJXFileGenerator2 ではfalseにする
Enumについか。DB上のadd_zero_recordは削除。ContractorInitSeederを利用し、全てのJX設定にHanaOrderJXFileGeneratorを設定。ステーションコードがMB65D7の場合のみ2を利用
[x] [発注確定時に自動送信]フラグは利用されているか？　「ONにすると、発注確定処理時にJXファイルを自動送信します」　現在発注先別に 自動発注生成時刻および送信時刻が設定されている。また発注データ集約先の設定もある。
実際に自動送信をする際には、1.発注先設定(admin/contractors)より自動送信を全て確認。2.送信時に「発注データ集約先 」があればその発注先でデータをまとめて送信。になる。これらを考えると
1. 発注データ集約先が設定された場合、自動発注生成時刻・送信時刻は集約先の設定に従う必要がある。
2. 1の仕様をみたすため、WMS送信設定で「送信方式」の次に「発注データ集約先」を配置。ここがnull出ない場合。自動発注生成時刻・送信時刻を非表示にし、「集約先の設定にしたがいます」と表示する。
3. 現在の送信ロジックを確認（JX）。送信対象を確認する際には、送信時刻も確認が必要だtが、発注でーた集約先がnull （自分が送信主体）であるひつようがある。
4. 発注データを送る際には自分を集約先と設定しているすべての発注先のデータをまとめる必要がある。
[x] admin/contractors/発注データ集約先設定時自分は選択できないように。発注データ集約先の先頭に[code]発注先　になるように。（これで検索も可能）

