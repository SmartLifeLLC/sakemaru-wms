# 入荷(入庫)機能 詳細仕様

> Android P10-P14 → Web SPA `/handy/incoming`
> **ステータス: 実装済み** — テスト・検証用リファレンス

---

## 1. 実装ファイル

| ファイル | 行数 | 役割 |
|---------|------|------|
| `resources/views/handy/incoming.blade.php` | 56行 | メインテンプレート |
| `resources/views/handy/layouts/app.blade.php` | 152行 | 共通レイアウト |
| `resources/views/handy/incoming/partials/header.blade.php` | — | ヘッダー |
| `resources/views/handy/incoming/partials/login.blade.php` | — | ログインフォーム |
| `resources/views/handy/incoming/partials/warehouse-select.blade.php` | — | 倉庫選択 |
| `resources/views/handy/incoming/partials/product-list.blade.php` | — | 商品リスト |
| `resources/views/handy/incoming/partials/process.blade.php` | — | スケジュール一覧 + 入力 |
| `resources/views/handy/incoming/partials/result.blade.php` | — | 結果表示 |
| `resources/views/handy/incoming/partials/history.blade.php` | — | 履歴 |
| `resources/views/handy/incoming/partials/footer.blade.php` | — | フッター |
| `resources/views/handy/incoming/partials/loading.blade.php` | — | ローディング |
| `resources/views/handy/incoming/partials/notification.blade.php` | — | 通知 |
| `resources/js/handy/incoming-app.js` | 1039行 | Alpine.jsアプリ |

---

## 2. 画面構成

### screen='login': ログイン
- スタッフコード + パスワード入力
- `POST /api/auth/login`
- 成功時 → warehouse画面

### screen='warehouse' (P10): 倉庫選択
- `GET /api/master/warehouses`
- 倉庫カードをタップ → list画面

### screen='list' (P11): 商品リスト
- `GET /api/incoming/schedules?warehouse_id={id}&search={query}`
- `GET /api/incoming/work-items` （作業中ID取得）
- 検索バー（バーコードスキャン対応、300msデバウンス）
- 商品カード表示:
  - JANコード、商品コード、商品名
  - 容量、温度帯
  - 残数量バッジ、済数量バッジ
  - 作業中インジケータ
- 無限スクロール（50件ずつ）

### screen='process' (P12): スケジュールリスト
- 商品サマリーヘッダー（JAN、商品コード、商品名、容量、入数）
- 合計入荷予定数バー
- スケジュールテーブル:
  - 倉庫名
  - 予定日
  - 欠品数（あれば赤表示）
  - ロケーション
  - 出荷数/予定数ボタン

### screen='input' (P13): 入庫入力
- 商品情報ヘッダー
- 入荷日表示
- 入力フォーム:
  - 賞味期限（date picker）
  - ロケーション（オートコンプリート、`GET /api/incoming/locations`）
  - 入庫数量（数値入力、デフォルト=出荷数or予定数）
- フッター:
  - 確定ボタン（`POST /api/incoming/work-items/{id}/complete`）
  - 登録ボタン（`POST /api/incoming/work-items` or `PUT /api/incoming/work-items/{id}`）

### screen='result': 結果表示
- 入庫完了メッセージ

### screen='history' (P14): 入庫履歴
- `GET /api/incoming/work-items?status=all&from_date=today`
- 履歴リスト:
  - JANコード、商品コード、商品名
  - 倉庫名、予定日、入庫日
  - ステータスバッジ（カラーコーディング）
  - 数量
- タップで編集可能（ステータスにより制限あり）

---

## 3. API利用フロー

```
1. ログイン
   POST /api/auth/login → token取得、localStorage保存

2. 倉庫一覧取得
   GET /api/master/warehouses

3. 商品リスト取得
   GET /api/incoming/schedules?warehouse_id={id}&search={query}
   GET /api/incoming/work-items?warehouse_id={id}&status=WORKING
   → 作業中のスケジュールIDを取得

4. スケジュール選択 → 作業開始
   POST /api/incoming/work-items
   { incoming_schedule_id, picker_id, warehouse_id }

5. ロケーション検索（入力中）
   GET /api/incoming/locations?warehouse_id={id}&search={query}

6. 作業内容更新
   PUT /api/incoming/work-items/{id}
   { work_quantity, work_arrival_date, work_expiration_date, location_id }

7. 入荷確定
   POST /api/incoming/work-items/{id}/complete
   → real_stocks に在庫追加

8. 入荷キャンセル
   DELETE /api/incoming/work-items/{id}
   → WORKING時のみ

9. 履歴取得
   GET /api/incoming/work-items?status=all&from_date=today&warehouse_id={id}
```

---

## 4. キーボードナビゲーション

BHT-M60 ハンディターミナルはキーボード操作が主要:

| 画面 | ↑/↓ | Tab | Enter |
|------|------|-----|-------|
| 商品リスト | リスト選択移動 | 次のアイテム | 選択確定 |
| スケジュールリスト | スケジュール選択 | 次のスケジュール | 入力画面へ |
| 入力画面 | フィールド間移動 | 次のフィールド | — |
| 履歴 | 履歴選択移動 | — | 編集 |

---

## 5. テスト検証チェックリスト

### 認証テスト
- [ ] ピッカーコード + パスワードでログイン
- [ ] auth_key パラメータでログインスキップ
- [ ] warehouse_id パラメータで倉庫選択スキップ
- [ ] 401エラー時に再ログインリダイレクト

### 入庫処理テスト
- [ ] 倉庫一覧の取得と選択
- [ ] 商品リストの表示と検索
- [ ] バーコードスキャンでの検索
- [ ] スケジュール一覧の表示
- [ ] 入庫数量の入力
- [ ] 賞味期限の入力
- [ ] ロケーションのオートコンプリート
- [ ] 新規入庫作業の登録
- [ ] 既存作業の更新
- [ ] 入庫確定処理
- [ ] 入庫キャンセル処理
- [ ] 履歴の表示と編集

### エッジケーステスト
- [ ] ネットワークエラー時の表示
- [ ] セッション切れ時のリカバリ
- [ ] 空リスト表示
- [ ] 同時操作時のデータ競合
