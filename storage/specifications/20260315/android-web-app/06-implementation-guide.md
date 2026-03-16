# 実装ガイド

---

## 1. ファイル構成

### 新規作成・修正するファイル

```
sakemaru-wms/
├── app/Http/Controllers/Handy/
│   ├── HandyController.php          # 修正不要（outgoing既に対応済み）
│   └── HandyIncomingController.php  # 修正不要
├── routes/
│   └── web.php                      # 修正不要（ルート定義済み）
├── resources/
│   ├── views/handy/
│   │   ├── outgoing.blade.php       # ★要大幅改修（入荷SPAと同じ構造に）
│   │   └── outgoing/partials/       # ★新規作成
│   │       ├── header.blade.php
│   │       ├── task-list.blade.php   # P20: コース選択
│   │       ├── picking.blade.php     # P21: データ入力
│   │       ├── history.blade.php     # P22: 出庫履歴
│   │       ├── complete.blade.php    # 完了確認
│   │       ├── result.blade.php      # 結果表示
│   │       ├── image-dialog.blade.php # 画像ビューア
│   │       ├── loading.blade.php
│   │       ├── footer.blade.php
│   │       └── notification.blade.php
│   └── js/handy/
│       └── outgoing-app.js          # ★要大幅改修
```

### 方針: 入荷SPAの構造を踏襲

出荷SPAは現在単一ファイル（outgoing.blade.php）にインラインで全HTMLを記述。
入荷SPAと同じように **layouts/app.blade.php + partials/** に分割する。

---

## 2. outgoing.blade.php のリファクタリング

### Before（現在: 362行の単一ファイル）
```html
<!DOCTYPE html>
<html>
<head>...</head>
<body>
    <div x-data="handyOutgoingApp()">
        <!-- ヘッダー -->
        <!-- task-list テンプレート -->
        <!-- picking テンプレート -->
        <!-- complete テンプレート -->
        <!-- result テンプレート -->
        <!-- フッター -->
        <!-- 通知 -->
    </div>
</body>
</html>
```

### After（目標: 入荷SPAと同構造）
```html
@extends('handy.layouts.app')

@section('title', 'HANDY 出荷処理システム')

@push('styles')
<!-- 出荷固有のスタイル -->
@endpush

@section('content')
<div x-data="handyOutgoingApp()" x-init="init()"
     class="handy-container bg-white flex flex-col relative overflow-hidden">

    @include('handy.outgoing.partials.header')

    <main class="handy-main overflow-y-auto no-scrollbar bg-slate-50 relative">
        @include('handy.outgoing.partials.loading')
        @include('handy.outgoing.partials.task-list')
        @include('handy.outgoing.partials.picking')
        @include('handy.outgoing.partials.history')
        @include('handy.outgoing.partials.complete')
        @include('handy.outgoing.partials.result')
        @include('handy.outgoing.partials.image-dialog')
    </main>

    @include('handy.outgoing.partials.footer')
    @include('handy.outgoing.partials.notification')
</div>

<script>
    window.HANDY_CONFIG = {
        apiKey: '{{ $apiKey }}',
        baseUrl: '{{ url('/api') }}',
        authKey: {!! $authKey ? "'" . e($authKey) . "'" : 'null' !!},
        warehouseId: {!! $warehouseId ? (int)$warehouseId : 'null' !!}
    };
</script>
@endsection
```

---

## 3. outgoing-app.js の拡張

### 現在: 約410行 → 目標: 約800-1000行

### 追加が必要な機能

#### 3.1 ステータスベースナビゲーション
```javascript
// selectTask() を改修
async selectTask(task) {
    this.currentTask = task;
    this.isLoading = true;
    this.loadingMessage = 'タスクを開始中...';

    try {
        await api.post(`/picking/tasks/${task.wave.wms_picking_task_id}/start`);

        const pendingItems = task.picking_list.filter(i => i.status === 'PENDING');
        const pickingItems = task.picking_list.filter(i => i.status === 'PICKING');

        if (pendingItems.length > 0) {
            this.initPickingScreen(pendingItems);
            this.currentScreen = 'picking';
        } else if (pickingItems.length > 0) {
            this.initHistoryScreen();
            this.currentScreen = 'history';
        } else {
            this.initHistoryScreen();
            this.currentScreen = 'history';
        }
    } catch (error) {
        // PICKING状態でも続行
        if (error.message?.includes('PICKING')) {
            // 上記と同じ分岐
        } else {
            this.showNotification('タスク開始に失敗: ' + error.message, 'error');
        }
    } finally {
        this.isLoading = false;
    }
}
```

#### 3.2 PENDINGアイテム管理
```javascript
// PENDING items only for picking screen
get pendingItems() {
    if (!this.currentTask) return [];
    return this.currentTask.picking_list.filter(i => i.status === 'PENDING');
},

// Current PENDING item
get currentItem() {
    return this.pendingItems[this.currentItemIndex] || null;
},

// Init picking screen
initPickingScreen(pendingItems) {
    this.currentItemIndex = 0;
    if (pendingItems.length > 0) {
        this.pickedQty = Number(pendingItems[0].planned_qty);
    }
},
```

#### 3.3 履歴画面
```javascript
// History items (non-PENDING)
get historyItems() {
    if (!this.currentTask) return [];
    return this.currentTask.picking_list.filter(i => i.status !== 'PENDING');
},

// Init history screen
initHistoryScreen() {
    // Nothing to init — computed from currentTask
},

// Cancel item (P22)
async cancelItem(item) {
    this.isLoading = true;
    this.loadingMessage = '取り消し中...';
    try {
        await api.post(`/picking/tasks/${item.wms_picking_item_result_id}/cancel`);
        await this.refreshCurrentTask();
        this.showNotification('取り消しました', 'success');
    } catch (error) {
        this.showNotification('取り消しに失敗: ' + error.message, 'error');
    } finally {
        this.isLoading = false;
    }
},
```

#### 3.4 タスクリフレッシュ
```javascript
async refreshCurrentTask() {
    const response = await api.get(
        `/picking/tasks?warehouse_id=${this.selectedWarehouse.id}&picker_id=${this.picker.id}`
    );
    if (response.is_success && response.result?.data) {
        const tasks = response.result.data;
        const refreshed = tasks.find(t =>
            t.wave.wms_picking_task_id === this.currentTask.wave.wms_picking_task_id
        );
        if (refreshed) {
            this.currentTask = refreshed;
            // Re-evaluate navigation
            const pending = refreshed.picking_list.filter(i => i.status === 'PENDING');
            if (pending.length > 0 && this.currentScreen === 'history') {
                // Items returned to PENDING — go back to picking
                this.initPickingScreen(pending);
                this.currentScreen = 'picking';
            }
        }
    }
},
```

#### 3.5 登録後のフロー
```javascript
async submitPicking() {
    const item = this.currentItem;
    if (!item) return;

    this.isLoading = true;
    this.loadingMessage = 'ピッキング登録中...';

    try {
        // 1. Update item
        await api.post(`/picking/tasks/${item.wms_picking_item_result_id}/update`, {
            picked_qty: this.pickedQty,
            picked_qty_type: item.planned_qty_type
        });

        // 2. Refresh task from server
        await this.refreshCurrentTask();

        // 3. Check remaining PENDING items
        const remaining = this.pendingItems;
        if (remaining.length > 0) {
            this.currentItemIndex = Math.min(this.currentItemIndex, remaining.length - 1);
            this.pickedQty = Number(remaining[this.currentItemIndex]?.planned_qty || 0);
        } else {
            // All done → complete screen
            this.currentScreen = 'complete';
        }

        this.showNotification('登録しました', 'success');
    } catch (error) {
        this.showNotification('登録に失敗: ' + error.message, 'error');
    } finally {
        this.isLoading = false;
    }
},
```

#### 3.6 画像ダイアログ
```javascript
// Image dialog state
showImageDialog: false,

showImages() {
    if (this.currentItem?.images?.length > 0) {
        this.showImageDialog = true;
    }
},

dismissImages() {
    this.showImageDialog = false;
},
```

#### 3.7 カウンター計算
```javascript
// Counters (computed)
get totalCount() {
    return this.currentTask?.picking_list?.length || 0;
},

get registeredCount() {
    if (!this.currentTask) return 0;
    return this.currentTask.picking_list.filter(i => i.status !== 'PENDING').length;
},

get pendingCount() {
    if (!this.currentTask) return 0;
    return this.currentTask.picking_list.filter(i => i.status === 'PENDING').length;
},

get isFullyProcessed() {
    return this.pendingCount === 0 &&
           this.currentTask?.picking_list?.every(i =>
               i.status === 'COMPLETED' || i.status === 'SHORTAGE'
           );
},
```

---

## 4. Vite 設定

`vite.config.js` で `outgoing-app.js` が既にエントリポイントとして登録されていることを確認:

```javascript
// vite.config.js
export default defineConfig({
    plugins: [laravel({
        input: [
            'resources/css/app.css',
            'resources/js/handy/login-app.js',
            'resources/js/handy/home-app.js',
            'resources/js/handy/incoming-app.js',
            'resources/js/handy/outgoing-app.js',  // ← 確認
        ],
    })],
});
```

---

## 5. テスト方法

### ブラウザテスト
1. `php artisan serve` でローカルサーバー起動
2. ブラウザで `/handy/login` にアクセス
3. ピッカーコードでログイン
4. ホーム画面 → 倉庫選択 → 出荷 or 入荷

### テストURL（auth_keyパラメータ使用）
```
# 入荷テスト
http://localhost:8000/handy/incoming?auth_key={token}&warehouse_id=991

# 出荷テスト
http://localhost:8000/handy/outgoing?auth_key={token}&warehouse_id=991
```

### テストデータ
- テスト環境にWaveを生成してピッキングタスクを作成
- `php artisan wms:generate-waves` でWave自動生成
- 管理画面でピッカー割当

---

## 5.5 Android版からの追加実装ポイント

### Idempotency-Key ヘッダー
Android版は全POSTリクエスト（start/update/complete/cancel）にUUIDベースの`Idempotency-Key`ヘッダーを付与。
Web版でも同様に実装を推奨:

```javascript
async postWithIdempotency(endpoint, data) {
    const idempotencyKey = crypto.randomUUID();
    const url = `${this.baseUrl}${endpoint}`;
    const headers = {
        ...this.getHeaders(),
        'Idempotency-Key': idempotencyKey,
    };
    const response = await fetch(url, {
        method: 'POST',
        headers,
        body: data ? JSON.stringify(data) : undefined,
    });
    // ... error handling
}
```

### 削除確認ダイアログ
Android版PickingHistoryScreenでは削除前にAlertDialogで確認。
Web版では`confirm()`ダイアログまたはカスタムモーダルで実装:

```javascript
async cancelItem(item) {
    if (!confirm(`「${item.item_name}」の登録を取り消しますか？`)) return;
    // POST /api/picking/tasks/{itemResultId}/cancel
}
```

### 確定前の全件確認ダイアログ
Android版では確定ボタン押下時に「すべての商品登録を完了しました。確定しますか？」と確認。
Web版でも同様の確認フローを実装する

---

## 6. Android → Web 機能対応表（最終確認用）

### 出荷(出庫)

| Android機能 | Webでの実装方針 | 優先度 |
|------------|---------------|--------|
| P20 タスク2カラムグリッド | CSS grid-cols-2 | 高 |
| P20 ステータス色分け | Tailwind + inline style | 高 |
| P20 Pull-to-refresh | ボタンでリフレッシュ（Webにはpull-to-refreshなし） | 中 |
| P21 2ペインレイアウト | flex row（幅480px内では1カラムに変更も検討） | 高 |
| P21 商品画像表示 | Phosphor icon + ダイアログ | 中 |
| P21 ケース/バラ分離入力 | 2つのinput（active/disabled切り替え） | 高 |
| P21 得意先名表示 | テキスト表示 | 高 |
| P21 伝票番号表示 | テキスト表示 | 高 |
| P22 履歴リスト | LazyColumn → ul/li | 高 |
| P22 削除ボタン | PIKINGステータスのみ表示 | 高 |
| P22 確定ボタン | フッター固定 | 高 |
| Denso F1-F4キー | Webでは非対応（ボタンUIで代替） | 低 |

### 入荷(入庫) — 実装済み確認

| Android機能 | Web実装状態 |
|------------|-----------|
| P10 倉庫選択 | ✅ |
| P11 商品リスト + 検索 | ✅ |
| P11 バーコードスキャン | ✅（テキスト入力で代替） |
| P12 スケジュール一覧 | ✅ |
| P13 入庫入力 | ✅ |
| P13 ロケーションオートコンプリート | ✅ |
| P14 履歴 | ✅ |
| キーボードナビゲーション | ✅ |

---

## 7. 注意事項

### 480px幅での2ペインレイアウト
Android版P21は横向き（landscape）前提で左右2ペイン。
Web版は480px固定幅のため、**1カラム縦並び**に変更することを推奨:
- 上部: 商品情報（折りたたみ可能）
- 下部: 数量入力 + ボタン

あるいは、Web版では幅制限を緩和（max-width: 800px等）して2ペインを維持する選択肢もある。

### バーコードスキャン
Web版ではハードウェアスキャナー非対応。テキスト入力で代替。
USB接続のバーコードリーダーはキーボード入力として動作するため、
入力フィールドにフォーカスがあれば自動的に対応可能。

### セッション管理
- `localStorage` でトークンとピッカー情報を保存
- 401エラー時は自動的にログイン画面へリダイレクト
- `auth_key` URLパラメータでの認証もサポート
