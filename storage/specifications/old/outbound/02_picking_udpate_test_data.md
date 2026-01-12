test データ生成を改修

1. WMSマスタデータを生成
- 在庫データはここで生成しない。
- Floorデータを生成するように変食お
- オプション
  生成倉庫を指定
　倉庫に対してFloorとLocation数指定。
　例：
  Floor : 1 => code (倉庫コード + 001  ) name (1F)  -1 => code (倉庫コード + 901) name (B1F) 
  Location数(CASE) :
  Location数(PIECE) :
  Location数(CASE and PIECE) :
  　
  上記を必要な分追加可能

2. 売上データ生成
倉庫指定。配送コース指定(複数)。倉庫のロケーション指定（複数）。生成件数指定
上記指定した伝票データを作成できるように


3.locationsのアップデート
- wms_locationsは廃止するので削除する。 locationsのみを利用
- locationsのcode 1 =>通路,　code 2 棚番号, code 3 棚段番号になる。
- テストデータ生成時に code1 は(A-Z) /　CODE2は (001 - 999) / CODE 3 / (1-3)で locationを生成する。

4. wms_locations.walking_ordersの利用は廃止する。
今後APIのpicking orderはlocationのx_pos, y_posより計算される。

