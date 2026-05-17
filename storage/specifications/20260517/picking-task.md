https://wms.sakemaru.test/admin/wms-picking-waitings
で統合引き当て欠品処理ができるものが必要。
1.ピッカー割り当ての左に[統合引当欠品処理] ボタンを追加
2. クリックすると引き当て欠品メンテモーダルがひらく。
3. このモーダルでするのは引き当て時に欠品だったがあるので、ピッキングするものを選別して引き当て数をあげること。
4. https://wms.sakemaru.test/admin/wms-picking-item-edit?tableFilters[picking_task_id][value]=1196 ここでしているものを全てまとめて一気にしたい。
5. モーダルで数量を入力したあと、最後に確定を押すと引き当て欠品のメンテが官僚になるイメージ。

その後はhttps://wms.sakemaru.test/admin/wms-picking-waitings　もどりピッカー割り当てを一括で行うので問題ない。
つまり引き当て数の変更をいっきにしたい
