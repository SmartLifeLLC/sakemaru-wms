# スマート日付入力フォーム

Filament の日付入力は、業務入力ではキーボードだけで高速に入力できることを優先する。
日付フォームを新規作成・修正する場合は、原則として `ViewField` + `filament.forms.components.smart-date-input` を使う。

## 標準実装

ビュー:

- `resources/views/filament/forms/components/smart-date-input.blade.php`

フォーム:

```php
use Filament\Forms\Components\ViewField;

ViewField::make('target_date')
    ->label('対象日')
    ->view('filament.forms.components.smart-date-input')
    ->default(now()->toDateString())
    ->live()
    ->required();
```

## 入力仕様

- Livewire へ送る値は常に `YYYY-MM-DD`
- フォーカス時は全選択
- `inputmode="numeric"` を指定
- 全角数字は半角に変換
- 数字・ハイフン・スラッシュ以外は除去
- カレンダーアイコンからネイティブ Date Picker を開ける

## 自動変換

- `10` -> 当年当月10日
- `401` -> 当年4月1日
- `1010` -> 当年10月10日
- `241010` -> 2024年10月10日
- `20241010` -> 2024年10月10日
- `2024/10/10` / `2024-10-10` -> 2024年10月10日

存在しない日付は反映せず、直前の有効値へ戻す。

## Filament 4 注意点

このプロジェクトは Filament 4 のため、v3向けの `$wire.$entangle()` ではなく以下を使う。

```js
state: $wire.entangle('{{ $getStatePath() }}').live
```

モーダル内の大量選択やカスタム入力は、標準 `DatePicker` より `ViewField` の利用を優先する。
