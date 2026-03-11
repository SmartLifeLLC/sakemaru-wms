# ピッキングリスト出力モーダル 仕様

- **作成日**: 2026-03-12
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260312/20260312-042241-picking-list-generation/20260312-060006-picking-list-output-modal/`
- **デザインベース**: `~/.claude/design-knowledge/modal-design.md`

## 目的

Wave一覧画面（admin/waves）に「ピッキングリスト出力」ボタンを設置し、モーダルで出荷日・倉庫・リスト種別を選択して、該当する全Waveを対象にまとめてPDFを出力する。

現状のWave行ごとのrecordActionでは1Wave単位でしかPDFを出力できないため、出荷日・倉庫単位で複数Waveをまとめたピッキングリストを一括出力する機能が必要。

## モーダル基本設定

| 項目 | 値 |
|------|-----|
| 配置先 | `app/Filament/Resources/Waves/Pages/ListWaves.php` の `getHeaderActions()` |
| トリガー | ヘッダーツールバーボタン「ピッキングリスト出力」 |
| モーダル幅 | `4xl` |
| CSSクラス | `picking-list-modal` |
| ヘッダースタイル | 紺色 (#1e293b) |
| フッターボタン | 右寄せ |
| 送信ボタンラベル | 「PDF出力」 |

## レイアウト構造

```
┌──────────────────────────────────────────────────┐
│ ■ ピッキングリスト出力                          × │
│ 出荷日・倉庫を選択し、対象リストを出力します      │
├──────────────────────────────────────────────────┤
│                                                    │
│  リスト種別                                        │
│  ○ 1次（Wave集約）  ○ 2次（作業者別）  ○ 3次（納品先別仕分け）  │
│                                                    │
│  [倉庫セレクト      ▼] │ [出荷日 📅          ▼]  │ ← Grid(2)
│                                                    │
│  ─── 対象Wave一覧 ──────────────────────────────  │
│  ┌─────────────────────────────────────────────┐  │
│  │ Wave番号           │ コース名    │ 状況     │  │
│  │ W091-C910146-...   │ 上中 孝浩  │ 未出荷   │  │
│  │ W091-C910156-...   │ 吉川 裕之  │ 未出荷   │  │
│  │ W091-C910288-...   │ 吉村 真一  │ 未出荷   │  │
│  │ ...                │ ...        │ ...      │  │
│  └─────────────────────────────────────────────┘  │
│                                                    │
│  ┌─ 合計 ──────────────────── 12 Wave ──────────┐  │
│  └──────────────────────────────────────────────┘  │
│                                                    │
├──────────────────────────────────────────────────┤
│                                      [PDF出力]    │
└──────────────────────────────────────────────────┘
```

## フォームフィールド定義

### list_type（リスト種別）

| 項目 | 値 |
|------|-----|
| タイプ | ViewField（ラジオボタン風セグメントコントロール） |
| ViewField | `filament.forms.components.picking-list-type-select` |
| ラベル | リスト種別 |
| 選択肢 | `primary` = 1次（Wave集約）, `secondary` = 2次（作業者別）, `tertiary` = 3次（納品先別仕分け） |
| 必須 | Yes |
| Live | Yes |
| デフォルト値 | `primary` |

### warehouse_id（倉庫）

| 項目 | 値 |
|------|-----|
| タイプ | Searchable Select |
| ViewField | `filament.forms.components.warehouse-select`（既存再利用） |
| ラベル | 倉庫 |
| データソース | `Warehouse::query()` (WaveのPickingTaskから使用されている倉庫のみ) |
| 必須 | Yes |
| Live | Yes |
| デフォルト値 | WarehouseResolver から取得 |

### shipping_date（出荷日）

| 項目 | 値 |
|------|-----|
| タイプ | Date Input |
| ViewField | `filament.forms.components.date-input`（既存再利用） |
| ラベル | 出荷日 |
| 必須 | Yes |
| Live | Yes |
| デフォルト値 | ClientSetting::systemDateYMD() |

### wave_preview（対象Wave一覧プレビュー）

| 項目 | 値 |
|------|-----|
| タイプ | Placeholder + HtmlString |
| ラベル | 対象Wave |
| データソース | warehouse_id + shipping_date で動的フィルタ |
| Live依存 | warehouse_id, shipping_date |
| 表示内容 | 対象Waveのテーブル（Wave番号、コース名、状況）+ 件数サマリー |

## Grid レイアウト

```php
->schema([
    // リスト種別（全幅）
    ViewField::make('list_type')
        ->label('リスト種別')
        ->view('filament.forms.components.picking-list-type-select')
        ->default('primary')
        ->required()
        ->live(),

    Grid::make(2)->schema([
        // 左: 倉庫セレクト
        ViewField::make('warehouse_id')
            ->label('倉庫')
            ->view('filament.forms.components.warehouse-select')
            ->viewData([...])
            ->default(fn () => WarehouseResolver::defaultId())
            ->required()
            ->live(),

        // 右: 出荷日
        ViewField::make('shipping_date')
            ->label('出荷日')
            ->view('filament.forms.components.date-input')
            ->default(fn () => ClientSetting::systemDateYMD())
            ->required()
            ->live(),
    ]),

    // 対象Waveプレビュー（全幅）
    Placeholder::make('wave_preview')
        ->label('対象Wave')
        ->content(function (Get $get) {
            // warehouse_id + shipping_date で対象Wave取得
            // テーブル形式でHTMLを生成
        }),
])
```

## Blade コンポーネント

### 新規作成が必要なもの

| ファイル | ベース | カスタマイズ |
|---------|--------|------------|
| `resources/views/filament/forms/components/picking-list-type-select.blade.php` | なし（新規） | 3択のセグメントコントロール（ラジオボタン風カード） |

### 既存コンポーネントの再利用

| ファイル | 用途 |
|---------|------|
| `warehouse-select.blade.php` | 倉庫選択 |
| `date-input.blade.php` | 出荷日選択 |

## CSS 定義

```css
/* ピッキングリスト出力モーダル */
.picking-list-modal .fi-modal-header {
    background-color: #1e293b !important;
    border-radius: 0.75rem 0.75rem 0 0;
    padding: 0.75rem 1rem;
}
.picking-list-modal .fi-modal-header .fi-modal-heading {
    color: #ffffff !important;
}
.picking-list-modal .fi-modal-header .fi-modal-description {
    color: #94a3b8 !important;
}
.picking-list-modal .fi-modal-header .fi-modal-close-btn {
    color: #ffffff !important;
}
.picking-list-modal .fi-modal-header .fi-modal-close-btn:hover {
    color: #cbd5e1 !important;
}
.picking-list-modal .fi-modal-footer {
    justify-content: flex-end !important;
}
.picking-list-modal .fi-modal-footer > * {
    justify-content: flex-end !important;
}
```

theme.css に追記。

## アクション処理

```php
->action(function (array $data): mixed {
    $listType = $data['list_type'];
    $warehouseId = $data['warehouse_id'];
    $shippingDate = $data['shipping_date'];

    // 対象Waveを取得
    $waves = Wave::query()
        ->join('wms_wave_settings as ws', 'wms_waves.wms_wave_setting_id', '=', 'ws.id')
        ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
        ->where('dc.warehouse_id', $warehouseId)
        ->where('wms_waves.shipping_date', $shippingDate)
        ->select('wms_waves.*')
        ->orderBy('wms_waves.wave_no')
        ->get();

    if ($waves->isEmpty()) {
        Notification::make()->title('対象Waveがありません')->warning()->send();
        return null;
    }

    $service = new PickingListService();
    $pdfService = new PickingListPdfService();

    // リスト種別に応じてPDF生成
    // ※ 複数Waveをまとめる場合は、PickingListPdfServiceに
    //    renderBatchPrimaryPdf / renderBatchSecondaryPdf / renderBatchTertiaryPdf を追加
    //    または TCPDF インスタンスを跨いで連結する方式

    match ($listType) {
        'primary' => $pdf = $pdfService->renderBatchPrimaryPdf(
            $waves->map(fn ($w) => $service->generatePrimaryList($w->id))->toArray()
        ),
        'secondary' => $pdf = $pdfService->renderBatchSecondaryPdf(
            // 全Wave配下のPickingTask一覧を取得
            $waves->flatMap(fn ($w) => $w->pickingTasks)
                ->map(fn ($t) => $service->generateSecondaryList($t->id))->toArray()
        ),
        'tertiary' => $pdf = $pdfService->renderBatchTertiaryPdf(
            $waves->map(fn ($w) => $service->generateTertiaryList($w->id))->toArray()
        ),
    };

    $dateStr = str_replace('-', '', $shippingDate);
    $filename = "picking-list-{$listType}-{$dateStr}.pdf";

    return response()->streamDownload(
        fn () => print($pdf),
        $filename,
        ['Content-Type' => 'application/pdf']
    );
})
```

## PickingListPdfService への追加メソッド

一括出力のために以下のバッチレンダリングメソッドを追加:

```php
/**
 * 複数Waveの1次リストを1つのPDFにまとめる
 * @param array $dataList generatePrimaryListの戻り値の配列
 */
public function renderBatchPrimaryPdf(array $dataList): string;

/**
 * 複数タスクの2次リストを1つのPDFにまとめる
 * @param array $dataList generateSecondaryListの戻り値の配列
 */
public function renderBatchSecondaryPdf(array $dataList): string;

/**
 * 複数Waveの3次リストを1つのPDFにまとめる
 * @param array $dataList generateTertiaryListの戻り値の配列
 */
public function renderBatchTertiaryPdf(array $dataList): string;
```

各メソッドは1つのTCPDFインスタンス内で、データごとに改ページしながら描画する。

## 対象Wave一覧プレビュー（Placeholder）

倉庫・出荷日を選択すると、対象Waveのテーブルを表示:

| Wave番号 | コース名 | 状況 |
|----------|---------|------|
| W091-C910146-20260311-25 | 上中 孝浩 | 未出荷 |
| W091-C910156-20260311-19 | 吉川 裕之 | 未出荷 |

- 対象Wave 0件の場合: 空状態アイコン（`fa-file-alt`）+ 「対象Waveがありません」
- サマリーバー: 「合計 N Wave」（青背景）

## 対象ファイル

### 新規作成
- `resources/views/filament/forms/components/picking-list-type-select.blade.php` — リスト種別セレクト

### 既存変更
- `app/Filament/Resources/Waves/Pages/ListWaves.php` — getHeaderActions に出力ボタン追加
- `app/Services/PickingList/PickingListPdfService.php` — バッチレンダリングメソッド追加
- `resources/css/filament/admin/theme.css` — `.picking-list-modal` CSS追加

### 参照のみ
- `~/.claude/design-knowledge/modal-design.md`
- `app/Services/PickingList/PickingListService.php` — データ取得（変更不要）
- `resources/views/filament/forms/components/warehouse-select.blade.php` — 再利用
- `resources/views/filament/forms/components/date-input.blade.php` — 再利用

## 制約

- **読み取り専用**: PDF出力はデータを変更しない
- **FK禁止**: 新規テーブル不要（既存データのみ使用）
- **Filament 4パターン**: `Action` は `Filament\Actions\Action`、`schema()` を使用
- **QuantityType enum**: ケース→「ケース」、バラ→「バラ」
- **TCPDF座標描画**: HTML描画禁止

## 確認事項

1. **2次リスト一括出力時**: ピッカー未割当のタスクも全て出力するか？→ Yes（ピッカー欄空白で出力）
2. **パフォーマンス**: 1出荷日に12Wave × 平均3タスク = 36タスク分のPDF。PickingListServiceの1回あたり7ms × 36 + PDF描画 ≈ 1秒以内で問題なし
