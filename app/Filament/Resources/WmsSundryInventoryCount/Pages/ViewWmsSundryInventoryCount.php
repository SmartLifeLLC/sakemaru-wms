<?php

namespace App\Filament\Resources\WmsSundryInventoryCount\Pages;

use App\Filament\Resources\WmsSundryInventoryCountResource;
use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use App\Services\SundryInventoryCount\SundryInventoryCountService;
use App\Services\SundryInventoryCount\SundryInventoryDifferenceWorkbookService;
use App\Services\SundryInventoryCount\SundryInventoryInstructionSheetPdfService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 棚卸し（雑貨）詳細。指示書に手書きした実棚を画面で入力し、金額で差異を確認する。
 */
class ViewWmsSundryInventoryCount extends Page implements HasForms
{
    use InteractsWithForms;

    private const LIST_TABS = ['items', 'amounts', 'summary'];

    private const STATE_FILTERS = ['all', 'diff', 'uncounted'];

    protected static string $resource = WmsSundryInventoryCountResource::class;

    protected string $view = 'filament.resources.wms-sundry-inventory-count.pages.view-wms-sundry-inventory-count';

    public WmsSundryInventoryCount $record;

    public string $listTab = 'items';

    public string $categoryFilter = '';

    public string $itemCodeFilter = '';

    public string $itemNameFilter = '';

    public string $stateFilter = 'all';

    public int $itemPage = 1;

    public int $itemPerPage = 200;

    public function mount(WmsSundryInventoryCount $record): void
    {
        $record->load(['createdByUser', 'confirmedByUser']);
        $this->record = $record;
    }

    public function getTitle(): string|Htmlable
    {
        return "棚卸し（雑貨）詳細: {$this->record->count_no}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            WmsSundryInventoryCountResource::getUrl() => '棚卸し（雑貨）',
            '#' => $this->record->count_no,
        ];
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getCachedHeaderActions(): array
    {
        return [];
    }

    // ========================================
    // Tab / Filter / Pagination
    // ========================================

    public function setListTab(string $tab): void
    {
        $this->listTab = in_array($tab, self::LIST_TABS, true) ? $tab : 'items';
        $this->itemPage = 1;
    }

    public function setStateFilter(string $state): void
    {
        $this->stateFilter = in_array($state, self::STATE_FILTERS, true) ? $state : 'all';
        $this->itemPage = 1;
    }

    public function updatedCategoryFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedItemCodeFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedItemNameFilter(): void
    {
        $this->itemPage = 1;
    }

    public function clearFilters(): void
    {
        $this->categoryFilter = '';
        $this->itemCodeFilter = '';
        $this->itemNameFilter = '';
        $this->stateFilter = 'all';
        $this->itemPage = 1;
    }

    public function previousItemPage(): void
    {
        $this->itemPage = max(1, $this->itemPage - 1);
    }

    public function nextItemPage(): void
    {
        $this->itemPage++;
    }

    public function isEditable(): bool
    {
        return $this->record->isEditable();
    }

    /**
     * 数量明細（在庫管理あり）。
     */
    public function rows(): LengthAwarePaginator
    {
        $query = $this->filteredItemsQuery()
            ->orderBy('category2_code')
            ->orderBy('item_code')
            ->orderBy('id');

        $paginator = $query->paginate($this->itemPerPage, ['*'], 'itemPage', $this->itemPage);

        if ($this->itemPage > 1 && $paginator->isEmpty() && $paginator->total() > 0) {
            $this->itemPage = $paginator->lastPage();
            $paginator = $query->paginate($this->itemPerPage, ['*'], 'itemPage', $this->itemPage);
        }

        return $paginator;
    }

    /**
     * 金額明細（在庫管理なし・中分類別）。
     *
     * @return Collection<int, WmsSundryInventoryCountAmount>
     */
    public function amountRows(): Collection
    {
        return WmsSundryInventoryCountAmount::query()
            ->where('sundry_inventory_count_id', $this->record->id)
            ->orderBy('category1_code')
            ->orderBy('category2_code')
            ->orderBy('id')
            ->get();
    }

    /**
     * 在庫金額報告書ベースの残高と、中分類別理論金額の合計との突合（大分類別）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportReferences(): array
    {
        return (new SundryInventoryCountService)->reportReferences($this->record);
    }

    /**
     * @return array{managed: array<int, array<string, mixed>>, unmanaged: array<int, array<string, mixed>>, totals: array<string, array<string, mixed>>}
     */
    public function summary(): array
    {
        return (new SundryInventoryCountService)->summary($this->record);
    }

    /**
     * @return array{all: int, diff: int, uncounted: int}
     */
    public function itemCounts(): array
    {
        $base = fn (): Builder => WmsSundryInventoryCountItem::query()
            ->where('sundry_inventory_count_id', $this->record->id);

        return [
            'all' => $base()->count(),
            'diff' => $base()->whereNotNull('difference_quantity')->where('difference_quantity', '!=', 0)->count(),
            'uncounted' => $base()->whereNull('counted_quantity')->count(),
        ];
    }

    /**
     * @return array<string, string> 中分類コード => "[code]name"
     */
    public function categoryOptions(): array
    {
        return (new SundryInventoryInstructionSheetPdfService)->categoryOptions($this->record);
    }

    public function formatQuantity(mixed $quantity): string
    {
        if ($quantity === null) {
            return '-';
        }

        $formatted = number_format((float) $quantity, 3);

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }

    public function formatAmount(mixed $amount): string
    {
        return $amount === null ? '-' : '¥'.number_format((float) round((float) $amount));
    }

    /**
     * 入力欄の初期値（末尾の0を落とした数値文字列。未入力は空文字）。
     */
    public function inputValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $formatted = number_format((float) $value, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function filteredItemsQuery(): Builder
    {
        $query = WmsSundryInventoryCountItem::query()
            ->where('sundry_inventory_count_id', $this->record->id);

        if ($this->categoryFilter !== '') {
            $query->where('category2_code', $this->categoryFilter);
        }

        $code = trim(mb_convert_kana($this->itemCodeFilter, 'as'));
        if ($code !== '') {
            $query->where('item_code', 'like', '%'.addcslashes($code, '%_\\').'%');
        }

        $name = trim($this->itemNameFilter);
        if ($name !== '') {
            $query->where('item_name', 'like', '%'.addcslashes($name, '%_\\').'%');
        }

        if ($this->stateFilter === 'diff') {
            $query->whereNotNull('difference_quantity')->where('difference_quantity', '!=', 0);
        } elseif ($this->stateFilter === 'uncounted') {
            $query->whereNull('counted_quantity');
        }

        return $query;
    }

    // ========================================
    // 入力の反映
    // ========================================

    /**
     * 画面で入力した実棚数・実棚金額・前残金額を反映する。
     *
     * @param  array{items?: array<int|string, mixed>, amounts?: array<int|string, array<string, mixed>>}  $changes
     */
    public function saveChanges(array $changes): bool
    {
        $items = is_array($changes['items'] ?? null) ? $changes['items'] : [];
        $amounts = array_filter(
            is_array($changes['amounts'] ?? null) ? $changes['amounts'] : [],
            fn (mixed $change): bool => is_array($change),
        );

        if ($items === [] && $amounts === []) {
            return true;
        }

        try {
            $result = (new SundryInventoryCountService)->saveChanges($this->record, $items, $amounts, $this->actorName());
            $this->record->refresh();

            Notification::make()
                ->success()
                ->title('入力を反映しました')
                ->body("数量明細: {$result['items']}件 / 金額明細: {$result['amounts']}件")
                ->send();

            return true;
        } catch (ValidationException $e) {
            $this->record->refresh();
            $this->notifyFailure('入力を反映できません', $e);

            return false;
        }
    }

    private function actorName(): string
    {
        return auth()->user()?->name
            ? 'WEB: '.auth()->user()->name
            : 'WEB';
    }

    private function notifyFailure(string $title, \Throwable $e): void
    {
        $body = $e instanceof ValidationException
            ? collect($e->errors())->flatten()->implode("\n")
            : $e->getMessage();

        if (! $e instanceof ValidationException) {
            report($e);
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body($body)
            ->send();
    }

    // ========================================
    // Header Actions（ブレード内に配置）
    // ========================================

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addItem')
                ->label('商品追加')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->visible(fn () => $this->isEditable())
                ->schema([
                    TextInput::make('item_code')
                        ->label('商品CD')
                        ->required()
                        ->maxLength(20)
                        ->autocomplete(false),
                ])
                ->modalHeading('商品追加')
                ->modalDescription('対象中分類以外の商品も、商品CDで追加できます。在庫管理ありの商品は数量明細に、在庫管理なしの商品はその中分類を金額明細に追加します。')
                ->modalWidth('lg')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('追加する')->color('danger'))
                ->modalCancelActionLabel('追加せず閉じる')
                ->action(function (array $data): void {
                    try {
                        $result = (new SundryInventoryCountService)->addItemByCode($this->record, (string) ($data['item_code'] ?? ''));
                        $this->record->refresh();
                        $this->clearFilters();

                        if ($result['type'] === 'amount') {
                            $this->listTab = 'amounts';
                            $body = $result['inserted']
                                ? "在庫管理なしの商品のため、中分類 {$result['category_code']} を金額明細に追加しました。"
                                : "在庫管理なしの商品です。中分類 {$result['category_code']} は金額明細に登録済みです。";
                        } else {
                            $this->listTab = 'items';
                            $this->itemCodeFilter = $result['item_code'];
                            $body = $result['inserted']
                                ? "商品CD {$result['item_code']} を数量明細に追加しました。"
                                : "商品CD {$result['item_code']} は登録済みです。";
                        }

                        Notification::make()->success()->title('商品追加')->body($body)->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('商品を追加できません', $e);
                    }
                }),

            Action::make('refreshTheory')
                ->label('理論在庫更新')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->visible(fn () => $this->isEditable())
                ->modalHeading('理論在庫更新')
                ->modalDescription('選択した日の終了時点の受払で、理論数・原価・金額受払を再計算します。在庫金額報告書との突合も取り直します。入力済みの実棚は変更しません。')
                ->modalWidth('lg')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('更新する')->color('danger'))
                ->modalCancelActionLabel('更新せず閉じる')
                ->schema([
                    DatePicker::make('end_date')
                        ->label('受払終了日')
                        ->default(fn () => min(
                            $this->record->count_date?->toDateString() ?? now()->toDateString(),
                            now()->toDateString(),
                        ))
                        ->maxDate(now())
                        ->required(),
                    Checkbox::make('reset_manual_openings')
                        ->label('手入力した前残金額も自動の値（前回棚卸の実棚・旧システム残高）に戻す')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = (new SundryInventoryCountService)->refreshTheory(
                            $this->record,
                            (string) $data['end_date'],
                            (bool) ($data['reset_manual_openings'] ?? false),
                        );
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('理論在庫を更新しました')
                            ->body("受払終了日: {$result['end_date']} / 数量明細: 更新{$result['updated_items']}件・追加{$result['inserted_items']}件 / 金額明細: 更新{$result['updated_amounts']}件・追加{$result['inserted_amounts']}件")
                            ->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('理論在庫を更新できません', $e);
                    }
                }),

            Action::make('fillUncountedWithZero')
                ->label('未入力0')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn () => $this->isEditable())
                ->requiresConfirmation()
                ->modalHeading('未入力を0で埋める')
                ->modalDescription('実棚数が未入力の数量明細を、すべて実棚0として登録します。金額明細（在庫管理なし）は対象外です。')
                ->modalSubmitActionLabel('0で埋める')
                ->modalCancelActionLabel('埋めずに閉じる')
                ->action(function (): void {
                    try {
                        $filled = (new SundryInventoryCountService)->fillUncountedWithZero($this->record, $this->actorName());
                        $this->record->refresh();

                        Notification::make()->success()->title('未入力を0で埋めました')->body("{$filled}件")->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('未入力を0で埋められません', $e);
                    }
                }),

            Action::make('downloadInstructionSheet')
                ->label('指示書')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->visible(fn () => $this->record->status !== WmsSundryInventoryCount::STATUS_CANCELLED)
                ->schema([
                    Select::make('category_codes')
                        ->label('中分類')
                        ->options(fn () => $this->categoryOptions())
                        ->multiple()
                        ->searchable()
                        ->placeholder('全て（未選択で全部門出力）'),
                    Checkbox::make('show_system_quantity')
                        ->label('理論数を印字する')
                        ->default(true),
                    Checkbox::make('include_amount_page')
                        ->label('在庫管理なし（金額）の記入ページを付ける')
                        ->default(true),
                ])
                ->modalHeading('指示書ダウンロード')
                ->modalDescription('中分類ごとに改ページした指示書を出力します。実棚数を記入し、この画面で入力してください。')
                ->modalWidth('lg')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('ダウンロード')->color('danger'))
                ->modalCancelActionLabel('ダウンロードせず閉じる')
                ->action(function (array $data) {
                    $categoryCodes = ! empty($data['category_codes']) ? array_map('strval', $data['category_codes']) : null;
                    $pdfContent = (new SundryInventoryInstructionSheetPdfService)->generate(
                        $this->record,
                        $categoryCodes,
                        (bool) ($data['show_system_quantity'] ?? true),
                        (bool) ($data['include_amount_page'] ?? true),
                    );
                    $filename = '棚卸し指示書_雑貨_'.($this->record->count_no ?? 'unknown').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('downloadDifferenceWorkbook')
                ->label('差異表Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->action(function () {
                    try {
                        $xlsxContent = (new SundryInventoryDifferenceWorkbookService)->generate($this->record);
                    } catch (\Throwable $e) {
                        $this->notifyFailure('差異表を生成できません', $e);

                        return null;
                    }

                    $filename = '棚卸し差異表_雑貨_'.($this->record->warehouse_code ?? '').'_'.($this->record->count_no ?? 'unknown').'.xlsx';

                    return response()->streamDownload(
                        fn () => print ($xlsxContent),
                        $filename,
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
                    );
                }),

            Action::make('confirm')
                ->label('確定')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->visible(fn () => $this->isEditable())
                ->requiresConfirmation()
                ->modalHeading('棚卸し（雑貨）確定')
                ->modalDescription(function (): string {
                    $uncountedItems = $this->itemCounts()['uncounted'];
                    $uncountedAmounts = $this->amountRows()->whereNull('counted_amount')->count();

                    return "入力を締めて確定します。未入力: 数量明細 {$uncountedItems}件 / 金額明細 {$uncountedAmounts}件（未入力の明細は差異に含めません）。"
                        .'在庫や伝票は変更しません。調整伝票は差異表をもとに基幹で起票してください。';
                })
                ->modalSubmitActionLabel('確定する')
                ->modalCancelActionLabel('確定せず閉じる')
                ->action(function (): void {
                    try {
                        (new SundryInventoryCountService)->confirm($this->record, auth()->id());
                        $this->record->refresh();

                        Notification::make()->success()->title('棚卸し（雑貨）を確定しました')->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('確定できません', $e);
                    }
                }),

            Action::make('reopen')
                ->label('確定解除')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $this->record->status === WmsSundryInventoryCount::STATUS_CONFIRMED)
                ->requiresConfirmation()
                ->modalHeading('確定解除')
                ->modalDescription('確定を解除して入力中に戻します。')
                ->modalSubmitActionLabel('入力中に戻す')
                ->modalCancelActionLabel('戻さず閉じる')
                ->action(function (): void {
                    try {
                        (new SundryInventoryCountService)->reopen($this->record);
                        $this->record->refresh();

                        Notification::make()->success()->title('入力中に戻しました')->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('確定解除できません', $e);
                    }
                }),

            Action::make('cancel')
                ->label('取消')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->isEditable())
                ->requiresConfirmation()
                ->modalHeading('棚卸し（雑貨）取消')
                ->modalDescription('この棚卸しを取り消します。この操作は元に戻せません。')
                ->modalSubmitActionLabel('取り消す')
                ->modalCancelActionLabel('取り消さず閉じる')
                ->action(function () {
                    try {
                        (new SundryInventoryCountService)->cancel($this->record);
                    } catch (\Throwable $e) {
                        $this->notifyFailure('取消できません', $e);

                        return null;
                    }

                    Notification::make()->success()->title('棚卸し（雑貨）を取り消しました')->send();

                    return redirect(WmsSundryInventoryCountResource::getUrl());
                }),
        ];
    }
}
