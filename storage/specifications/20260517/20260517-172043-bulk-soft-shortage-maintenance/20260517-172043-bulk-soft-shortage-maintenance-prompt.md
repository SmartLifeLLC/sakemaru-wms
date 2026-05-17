[x] /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260517/20260517-172043-bulk-soft-shortage-maintenance/20260517-172043-bulk-soft-shortage-maintenance-boot.md
[x] https://wms.sakemaru.test/admin/wms-picking-tasks こちらのピッキングタスクに対して
ピッキングタスク別ではなく、ピッキング商品リストの網羅が必要。
そして、クリックしたら、実際のピッキング数量の入力ができる。


現状確定のボタンをなくす。
代わりに全強制出荷ボタンを作成。
全強制出荷ボタンの仕様
1. 引き当て欠品 => そのまま欠品
2. ピッキング数の入力があるもの　=> その入力数を正とする。この場合欠品があれば、ピック欠品
3. ピッキング数の入力がそもそもなかった場合 => ピッキング数をそのまま入力する。
つまりこちらの
   https://wms.sakemaru.test/admin/wms-picking-tasks/1196/execute
個別の対応を一括でするのが目的。

現在までのピッキング対応分はそのまま維持し、ピッキング処理がない分は引き当て数をそのままにゅうりょくし、
すべてを完了するのを簡単にできることが目的

