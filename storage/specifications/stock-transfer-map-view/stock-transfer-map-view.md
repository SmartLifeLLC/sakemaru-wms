配送ルート可視化マップ（Filament/Alpine.js）実装仕様書
1. 目的
   欠品対応時、「どの拠点倉庫（横持ち先）」から在庫をピックアップして「複数の納品先」へ回るのが最も効率的かを、担当者が地図上で一目で判断できるようにする。
2. 技術スタック
   Frontend: Alpine.js (Filament組み込み)
   Map Library: Leaflet.js (CDN経由)
   Backend: Laravel / Filament (Custom Component)
   Styling: Tailwind CSS
3. 実装要件
   A. マップ上の構成要素
   出発倉庫 (Departure): 固定（1箇所）。アイコン：黒色または家マーク。
   拠点倉庫 (Potential Warehouses): 在庫を持つ候補地（複数）。アイコン：青色。
   納品先 (Customers): 今回の配送ルートに含まれる得意先（複数）。アイコン：赤色。
   B. インタラクション
   倉庫選択: 指示入力フォームで「横持ち倉庫」を選択すると、マップ上の該当倉庫が強調され、[出発倉庫] -> [選択した倉庫] -> [納品先A] -> [納品先B...] というルート線（Polyline）が描画されること。
   距離計算: 選択された倉庫を経由した場合の総移動距離を表示する。
   ホバー/クリック: 各マーカーをクリックすると名称や在庫状況を表示する。
   C. データの受け渡し（Filament -> Alpine）
   PHP側から wire:model または entangle を通じて以下のデータをJSに渡す。
   locations: { id, name, lat, lng, type('departure', 'warehouse', 'customer'), stock_info } の配列。
   selectedWarehouseId: 現在フォームで選択されている倉庫ID。
4. UI/UX 仕様
   レイアウト: 画面右側に大きく配置（1.5倍に拡張した右カラム）。
   レスポンシブ: マップコンテナのサイズ変更に合わせて自動で map.invalidateSize() を実行。
   凡例: 右上に「出発」「拠点」「納品先」の色分けを示す凡例を表示。
5. 生成コードへの指示
   LeafletのCSS/JSの読み込み処理を含めること。
   x-data を使用して、Alpine.js内で地図の初期化、マーカー生成、ルート描画のロジックをカプセル化すること。
   倉庫が変更された際の watch 処理を実装し、ルート線を動的に更新すること。
   複数の納品先がある場合、最適な巡回順（または入力順）で線を結ぶこと。
   この仕様に基づき、Filamentのカスタムビュー（Bladeファイル）内で動作する、完全なAlpine.js + Leaflet.jsのコンポーネントコードを生成してください。


参考スクショ
/Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/stock-transfer-map-view/ref-image.png
