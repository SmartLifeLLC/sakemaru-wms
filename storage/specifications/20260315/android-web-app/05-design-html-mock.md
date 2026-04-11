# UI/UX デザイン・HTMLモック

---

## 1. 共通デザインシステム

### CSS変数（全画面共通）
```css
:root {
    --handy-width: 480px;
    --header-height: 40px;
    --footer-height: 40px;
    --font-xs: 13px;
    --font-sm: 15px;
    --font-base: 17px;
    --font-lg: 20px;
    --font-xl: 22px;
    --font-2xl: 27px;
    --font-3xl: 31px;
    --spacing-1: 4px;
    --spacing-2: 8px;
    --spacing-3: 12px;
    --spacing-4: 16px;
}
```

### コンテナ
```css
.handy-container {
    width: 480px;
    height: 100vh;
    max-width: 480px;
}
```

### カラーパレット

#### 出荷(出庫)アプリ — Androidの配色を踏襲
```
ヘッダー背景:    #FDFBF2 (クリーム)
タイトル:        #C0392B (レッド)
アクセント:      #E67E22 (オレンジ)
区切り線:        #F9A825 (ゴールド)
バッジ:          #27AE60 (グリーン)
ボディ背景:      #F5F5F5 (ニュートラル100)
カード背景:      #FFFFFF
Amber-50:       #FFFBEB
Amber-200:      #FDE68A
Amber-300:      #FCD34D
Amber-600:      #D97706
Amber-700:      #B45309
```

#### 入荷(入庫)アプリ — 既存Webの配色
```
ヘッダー背景:    #2563EB (ブルー600)
フッター背景:    #1E293B (スレート800)
アクセント:      #2563EB (ブルー)
成功:            #22C55E (グリーン)
エラー:          #EF4444 (レッド)
ボディ背景:      #F8FAFC (スレート50)
```

#### タスクカード ステータス色
```
未着手:
  背景: #FFFDE7    ボーダー: #F9A825    タイトル: #E67E22
作業中:
  背景: #E8F5E9    ボーダー: #4CAF50    タイトル: #2E7D32
完了:
  背景: #F5F5F5    ボーダー: #BDBDBD    タイトル: #757575
```

---

## 2. P20 コース選択画面 HTMLモック

```html
<!-- P20: コース選択画面 -->
<div class="handy-container bg-white flex flex-col">
    <!-- Header -->
    <header class="h-10 flex items-center justify-between px-3 shadow"
            style="background: #FDFBF2;">
        <div class="flex items-center gap-2">
            <button class="p-1"><i class="ph ph-caret-left" style="color: #C0392B;"></i></button>
            <i class="ph ph-truck" style="color: #E67E22;"></i>
            <span style="color: #C0392B; font-weight: bold; font-size: 18px;">配送コース選択</span>
            <span style="color: #E67E22; font-size: 14px;">｜メイン倉庫</span>
        </div>
    </header>
    <hr style="border-color: #F9A825; border-width: 2px;">

    <!-- Body -->
    <main class="flex-1 overflow-y-auto p-4" style="background: #F5F5F5;">
        <p style="color: #555; font-size: 14px;" class="mb-3">配送コースを選択してください</p>

        <!-- 2カラムグリッド -->
        <div class="grid grid-cols-2 gap-3">

            <!-- 未着手カード -->
            <div class="rounded-2xl p-4 shadow-sm" style="
                background: #FFFDE7;
                border: 2px solid #F9A825;
                min-height: 120px;">
                <div class="flex items-center gap-2 mb-1">
                    <i class="ph ph-truck" style="color: #E67E22;"></i>
                    <span style="color: #E67E22; font-weight: bold; font-size: 16px;">Aコース</span>
                </div>
                <p style="color: #555; font-size: 13px;">1F 冷凍エリア</p>
                <p style="color: #555; font-size: 13px;">出荷指示: 10件　検品済: 0件</p>
            </div>

            <!-- 作業中カード -->
            <div class="rounded-2xl p-4 shadow-sm" style="
                background: #E8F5E9;
                border: 2px solid #4CAF50;
                min-height: 120px;">
                <div class="flex items-center gap-2 mb-1">
                    <i class="ph ph-truck" style="color: #2E7D32;"></i>
                    <span style="color: #2E7D32; font-weight: bold; font-size: 16px;">Bコース</span>
                    <span class="text-xs text-white font-bold px-2 py-0.5 rounded-full"
                          style="background: #4CAF50;">作業中</span>
                </div>
                <p style="color: #555; font-size: 13px;">2F 常温エリア</p>
                <p style="color: #555; font-size: 13px;">出荷指示: 10件　検品済: 5件</p>
            </div>

            <!-- 完了カード -->
            <div class="rounded-2xl p-4 shadow-sm" style="
                background: #F5F5F5;
                border: 2px solid #BDBDBD;
                min-height: 120px;">
                <div class="flex items-center gap-2 mb-1">
                    <i class="ph ph-truck" style="color: #757575;"></i>
                    <span style="color: #757575; font-weight: bold; font-size: 16px;">Cコース</span>
                    <span class="text-xs text-white font-bold px-2 py-0.5 rounded-full"
                          style="background: #757575;">完了</span>
                </div>
                <p style="color: #555; font-size: 13px;">3F 冷蔵エリア</p>
                <p style="color: #555; font-size: 13px;">出荷指示: 10件　検品済: 10件</p>
            </div>

        </div>
    </main>
</div>
```

---

## 3. P21 データ入力画面 HTMLモック

```html
<!-- P21: データ入力画面 -->
<div class="handy-container bg-white flex flex-col">
    <!-- Header -->
    <header class="h-10 flex items-center justify-between px-3 shadow"
            style="background: #FDFBF2;">
        <div class="flex items-center gap-1">
            <button class="p-1"><i class="ph ph-caret-left" style="color: #C0392B;"></i></button>
            <i class="ph ph-package" style="color: #E67E22;"></i>
            <span style="color: #C0392B; font-weight: bold; font-size: 18px;">出庫</span>
            <!-- コース名バッジ -->
            <span class="text-xs text-white font-bold px-2 py-0.5 rounded-xl"
                  style="background: #27AE60;">Aコース（午前便）</span>
            <!-- 進捗バッジ -->
            <span class="text-xs text-white font-bold px-2 py-0.5 rounded-xl"
                  style="background: #E67E22;">3 / 10</span>
        </div>
        <button><i class="ph ph-house" style="color: #C0392B;"></i></button>
    </header>
    <hr style="border-color: #F9A825; border-width: 2px;">

    <!-- Body: 2ペイン -->
    <main class="flex-1 flex gap-1.5 p-1.5 overflow-hidden" style="background: #F5F5F5;">

        <!-- LEFT: 商品情報 -->
        <div class="flex-1 bg-white rounded-xl border p-2.5 flex flex-col gap-1.5 overflow-y-auto"
             style="border-color: #E5E5E5;">
            <!-- 商品名 -->
            <div class="flex items-start">
                <div class="flex-1">
                    <p style="font-size: 18px; font-weight: 800;">サッポロ生ビール黒ラベル 500ml缶</p>
                    <p style="font-size: 16px; color: #737373;" class="mt-0.5">
                        4901777123456 / 500ml / 入数:24
                    </p>
                    <p style="font-size: 16px; font-weight: bold; color: #737373;" class="mt-1.5">
                        得意先名: ○○酒店
                    </p>
                </div>
                <!-- 画像ボタン -->
                <button class="w-11 h-14 rounded-lg flex items-center justify-center"
                        style="background: #FFFBEB; border: 1px solid #FDE68A;">
                    <i class="ph ph-image" style="color: #D97706; font-size: 24px;"></i>
                </button>
            </div>

            <!-- ロケーション -->
            <div class="flex items-center gap-1.5">
                <span style="font-size: 16px; font-weight: bold; color: #737373; width: 100px;">ロケーション</span>
                <div class="flex-1 h-8 rounded-md flex items-center px-2"
                     style="background: #FFFBEB; border: 1px solid #FCD34D;">
                    <span style="font-size: 16px; font-weight: bold;">1F 冷凍エリア</span>
                </div>
            </div>

            <!-- 伝票番号 -->
            <div class="flex items-center gap-1.5">
                <span style="font-size: 16px; font-weight: bold; color: #737373; width: 100px;">伝票番号</span>
                <div class="flex-1 h-8 rounded-md flex items-center px-2"
                     style="background: #FFFBEB; border: 1px solid #FCD34D;">
                    <span style="font-size: 16px; font-weight: bold;">2024031501</span>
                </div>
            </div>
        </div>

        <!-- RIGHT: 数量入力 -->
        <div class="flex-1 bg-white rounded-xl border p-2.5 flex flex-col gap-1.5"
             style="border-color: #E5E5E5;">
            <!-- ケース・バラ入力 -->
            <div class="flex gap-1.5">
                <!-- ケース -->
                <div class="flex-1">
                    <p style="font-size: 14px; font-weight: bold; color: #737373;" class="mb-1">
                        ケース（受注数：10）
                    </p>
                    <input type="number" value="10"
                           class="w-full h-12 border rounded-lg text-center"
                           style="font-size: 16px; font-weight: bold; border-color: #D97706;">
                </div>
                <!-- バラ -->
                <div class="flex-1">
                    <p style="font-size: 14px; font-weight: bold; color: #737373;" class="mb-1">
                        バラ（受注数：0）
                    </p>
                    <input type="number" value="" disabled
                           class="w-full h-12 border rounded-lg text-center bg-gray-50"
                           style="font-size: 16px; border-color: #D4D4D4;">
                </div>
            </div>

            <div class="flex-1"></div>

            <!-- ボタン -->
            <div class="flex gap-2">
                <button class="flex-1 h-12 rounded-lg text-white font-bold"
                        style="background: #D97706; font-size: 16px;">
                    登録
                </button>
                <button class="flex-1 h-12 rounded-lg font-bold"
                        style="background: #FFFBEB; color: #B45309; border: 1px solid #FCD34D; font-size: 16px;">
                    履歴
                </button>
            </div>
        </div>

    </main>
</div>
```

---

## 4. P22 出庫履歴画面 HTMLモック

```html
<!-- P22: 出庫履歴画面 -->
<div class="handy-container bg-white flex flex-col">
    <!-- Header (同じ) -->
    <header class="h-10 flex items-center justify-between px-3 shadow"
            style="background: #FDFBF2;">
        <div class="flex items-center gap-1">
            <button class="p-1"><i class="ph ph-caret-left" style="color: #C0392B;"></i></button>
            <i class="ph ph-package" style="color: #E67E22;"></i>
            <span style="color: #C0392B; font-weight: bold; font-size: 18px;">出庫履歴</span>
            <span class="text-xs text-white font-bold px-2 py-0.5 rounded-xl"
                  style="background: #27AE60;">Aコース</span>
            <span class="text-xs text-white font-bold px-2 py-0.5 rounded-xl"
                  style="background: #E67E22;">5 / 10</span>
        </div>
    </header>
    <hr style="border-color: #F9A825; border-width: 2px;">

    <!-- Body: 履歴リスト -->
    <main class="flex-1 overflow-y-auto p-3" style="background: #F5F5F5;">
        <!-- 履歴アイテム -->
        <div class="space-y-2">

            <!-- PICKING ステータスアイテム -->
            <div class="bg-white rounded-lg border p-3 flex items-center justify-between"
                 style="border-color: #E5E5E5;">
                <div class="flex-1">
                    <p style="font-weight: bold; font-size: 15px;">サッポロ生ビール黒ラベル 500ml缶</p>
                    <p style="font-size: 13px; color: #737373;">4901777123456 / 伝票: 2024031501</p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs text-white font-bold px-2 py-0.5 rounded"
                              style="background: #3B82F6;">PICKING</span>
                        <span style="font-size: 14px; font-weight: bold;">10 ケース</span>
                    </div>
                </div>
                <button class="px-3 py-1 rounded text-white text-sm font-bold"
                        style="background: #EF4444;">
                    <i class="ph ph-trash"></i> 削除
                </button>
            </div>

            <!-- COMPLETED ステータスアイテム -->
            <div class="bg-white rounded-lg border p-3 flex items-center justify-between"
                 style="border-color: #E5E5E5;">
                <div class="flex-1">
                    <p style="font-weight: bold; font-size: 15px;">アサヒスーパードライ 350ml</p>
                    <p style="font-size: 13px; color: #737373;">4901004012345 / 伝票: 2024031502</p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs text-white font-bold px-2 py-0.5 rounded"
                              style="background: #22C55E;">COMPLETED</span>
                        <span style="font-size: 14px; font-weight: bold;">5 ケース</span>
                    </div>
                </div>
                <!-- 完了済みは削除不可 -->
            </div>

            <!-- SHORTAGE ステータスアイテム -->
            <div class="bg-white rounded-lg border p-3 flex items-center justify-between"
                 style="border-color: #E5E5E5;">
                <div class="flex-1">
                    <p style="font-weight: bold; font-size: 15px;">キリン一番搾り 500ml</p>
                    <p style="font-size: 13px; color: #737373;">4901411012345 / 伝票: 2024031503</p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs text-white font-bold px-2 py-0.5 rounded"
                              style="background: #EF4444;">SHORTAGE</span>
                        <span style="font-size: 14px; font-weight: bold;">
                            3 / 5 ケース <span style="color: #EF4444;">(欠品: 2)</span>
                        </span>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- Footer: 確定ボタン -->
    <footer class="p-3 bg-white border-t flex gap-2" style="border-color: #E5E5E5;">
        <button class="flex-1 h-12 rounded-lg font-bold"
                style="background: #F5F5F5; color: #404040; border: 1px solid #D4D4D4; font-size: 16px;">
            キャンセル
        </button>
        <button class="flex-1 h-12 rounded-lg text-white font-bold"
                style="background: #E67E22; font-size: 16px;">
            確定
        </button>
    </footer>
</div>
```

---

## 5. 完了確認画面 HTMLモック

```html
<!-- 完了確認画面 -->
<div class="flex flex-col items-center justify-center h-full p-8">
    <div class="bg-white rounded-2xl shadow-lg p-8 text-center max-w-sm w-full"
         style="border: 1px solid #E5E5E5;">
        <i class="ph ph-check-circle" style="font-size: 48px; color: #27AE60;"></i>
        <h2 style="font-size: 16px; font-weight: bold; color: #212529; margin-top: 12px;">
            すべての商品が登録されました。
        </h2>
        <p style="font-size: 14px; color: #737373; margin-top: 4px;">
            確定を押下してください。
        </p>
        <div class="flex gap-2 mt-4">
            <button class="flex-1 h-10 rounded-lg font-bold"
                    style="border: 1px solid #D4D4D4; color: #404040; font-size: 14px;">
                キャンセル
            </button>
            <button class="flex-1 h-10 rounded-lg text-white font-bold"
                    style="background: #E67E22; font-size: 14px;">
                確定
            </button>
        </div>
    </div>
</div>
```

---

## 6. 画像ビューアダイアログ HTMLモック

```html
<!-- 画像ビューアダイアログ -->
<div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-white rounded-2xl w-full max-w-sm overflow-hidden shadow-xl">
        <!-- ダイアログヘッダー -->
        <div class="flex items-center justify-between px-4 py-2"
             style="background: #FDFBF2;">
            <div class="flex items-center gap-2">
                <i class="ph ph-image" style="color: #E67E22;"></i>
                <span style="color: #C0392B; font-weight: bold; font-size: 16px;">商品画像</span>
                <span class="text-xs text-white font-bold px-2 py-0.5 rounded-xl"
                      style="background: #E67E22;">1 / 3</span>
            </div>
            <button><i class="ph ph-x" style="color: #C0392B;"></i></button>
        </div>
        <hr style="border-color: #F9A825; border-width: 2px;">

        <!-- 画像エリア -->
        <div class="w-full aspect-square bg-gray-100 flex items-center justify-center">
            <img src="product.jpg" class="w-full h-full object-contain">
        </div>

        <!-- ページインジケーター -->
        <div class="flex justify-center gap-1 py-2">
            <span class="w-3 h-3 rounded-full" style="background: #E67E22;"></span>
            <span class="w-2 h-2 rounded-full bg-gray-300"></span>
            <span class="w-2 h-2 rounded-full bg-gray-300"></span>
        </div>

        <!-- 閉じるボタン -->
        <div class="flex justify-end px-3 py-2">
            <button style="color: #E67E22; font-weight: bold;">閉じる</button>
        </div>
    </div>
</div>
```
