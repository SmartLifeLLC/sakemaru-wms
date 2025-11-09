倉庫フロアプラン｜Laravel + Alpine.js + Tailwind CSS 作業仕様書（v1）
参考コード : [wms_template.html](wms_template.html) (react 実装)

対象: 倉庫フロアプラン（グリッド吸着・しきい値・CSV出力/Shift_JIS・複数フロア・ロケーション編集）
1. 目的 / スコープ
   既存のブラウザ単体版（Canvas上HTML）を Laravel + Alpine.js + Tailwind CSS で再実装し、サーバー永続化・権限・CSVエクスポート(Shift_JIS/CRLF) を安定運用できるようにする。
   「レイアウト（フロア構成）」と「ブロック（ロケーション）」のCRUD、ドラッグ&リサイズ、グリッド吸着（ピッチ/しきい値可変）、編集モーダル、複数フロア切替、
   
2. 技術スタック / バージョン
   Backend: Laravel 11.x (PHP 8.2+), MySQL 8.x 
   Frontend: Blade + Alpine.js 3.x, Tailwind CSS 3.x, Vite

3. USE TABLE
- 在庫　: real_stock + wms_reservations 
- フロア : floors 
- ロケーション : locations 
区画の保存は locationsの x1,y1,x2,y2を利用
一つの区画はLocationのcode1 ( 通路) code 2(棚)まで
- 区画をクリックすると棚の段の確認ができるように（サンプルコードでは区画クリック時に段(1-4)の設定は現状ない。

保存した場合にデータベースに保存できるようにする。I
