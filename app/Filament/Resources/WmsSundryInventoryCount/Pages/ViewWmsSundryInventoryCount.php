<?php

namespace App\Filament\Resources\WmsSundryInventoryCount\Pages;

use App\Filament\Resources\WmsSundryInventoryCountResource;
use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use App\Models\WmsSundryValuationItem;
use App\Services\SundryInventoryCount\SundryAmountAdjustmentService;
use App\Services\SundryInventoryCount\SundryInventoryCountService;
use App\Services\SundryInventoryCount\SundryInventoryDifferenceWorkbookService;
use App\Services\SundryInventoryCount\SundryInventoryInstructionSheetPdfService;
use App\Services\SundryInventoryCount\SundryQuantityValuationService;
use App\Services\SundryInventoryCount\WeighedInventoryDifferenceWorkbookService;
use App\Services\SundryInventoryCount\SundryFinalizationService;
use App\Services\SundryInventoryCount\SundryFinalizationScope;
use App\Services\SundryInventoryCount\SundryFinalizationWorkbook;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * 棚卸し（雑貨）詳細。管理品・非管理品とも商品数量を入力し、評価額を確認する。
 * 種類が量り売りの棚卸しは、実棚をカメ・QT で入力し、期間売上とロスを合わせて確認する。
 */
class ViewWmsSundryInventoryCount extends Page implements HasForms
{
    use InteractsWithForms;

    private const LIST_TABS = ['items', 'amounts', 'valuation', 'summary'];

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

    #[\Livewire\Attributes\Locked]
    public array $finalizationPreview = [];

    public function finalizationRecord(): ?object
    {
        return SundryFinalizationService::record($this->record->id);
    }

    public function finalizeSundryConfirmationAction(): Action
    {
        return Action::make('finalizeSundryConfirmation')
            ->label('棚卸し最終確定')->color('danger')->modalHeading('実棚変更伝票の記録')
            ->modalWidth('7xl')->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalContent(fn () => view('filament.resources.wms-sundry-inventory-count.pages.partials.finalization-preview', ['preview' => $this->finalizationPreview]))
            ->schema([\Filament\Forms\Components\Checkbox::make('approve_unknown')
                ->label('部門の金額調整（提出差異・不明差異）を確認して確定する')->default(false)
                ->visible(fn () => collect($this->finalizationPreview['lines'] ?? [])->contains('kind', 'book'))])
            ->modalFooterActionsAlignment(Alignment::End)->modalCancelActionLabel('確定せず閉じる')
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit')->label('最終確定して伝票を記録')->color('danger'))
            ->action(function (array $data): void {
                try {
                    app(SundryFinalizationService::class)->finalize($this->record, $this->finalizationPreview['date'], $this->finalizationPreview['token'], auth()->id(),
                        (bool) ($data['approve_unknown'] ?? false), $this->finalizationPreview['application_scope'] ?? SundryFinalizationScope::ALL,
                        $this->finalizationPreview['zero_difference_row_ids'] ?? []);
                    $this->finalizationPreview = [];
                    Notification::make()->success()->title('最終確定しました。実棚変更伝票を作成待ちです。')->send();
                } catch (\Throwable $e) {
                    $this->notifyFailure('最終確定できません', $e);
                }
            });
    }

    public function zeroDifferenceOptions(): array
    {
        return DB::connection('sakemaru')->table('wms_sundry_inventory_count_items as r')
            ->join('items as i', 'i.id', '=', 'r.item_id')
            ->where('r.sundry_inventory_count_id', $this->record->id)->where('i.client_id', $this->record->client_id)
            ->whereNotNull('r.counted_quantity')->where('i.is_managed_stock', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('item_sets as s')
                ->whereColumn('s.id', 'i.item_set_id')->where('s.is_active', true)->where('s.set_type', 'OWNED'))
            ->orderBy('r.item_code')->orderBy('r.id')
            ->get(['r.id', 'r.item_code', 'r.item_name', 'r.system_quantity', 'r.counted_quantity'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function mount(WmsSundryInventoryCount $record): void
    {
        $record->load(['createdByUser', 'confirmedByUser']);
        $this->record = $record;
        if ($this->valuationAvailable() && $record->amounts()->exists()) {
            $this->listTab = 'valuation';
        }
    }

    public function getTitle(): string|Htmlable
    {
        return "{$this->kindTitle()}詳細: {$this->record->count_no}";
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
        if ($this->listTab === 'valuation' && $this->stateFilter === 'diff') {
            $this->stateFilter = 'all';
        }
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

    public function isWeighed(): bool
    {
        return $this->record->isWeighed();
    }

    /**
     * 画面・帳票に出す種類つきの名称。
     */
    public function kindTitle(): string
    {
        return $this->isWeighed() ? '棚卸し（量り売り）' : '棚卸し（雑貨）';
    }

    /**
     * 量り売りの集計（差異・期間売上・ロス）。
     *
     * @return array<string, float|int>
     */
    public function weighedSummary(): array
    {
        return (new SundryInventoryCountService)->weighedSummary($this->record);
    }

    public function weighedLossQuantity(mixed $periodSalesQuantity): float
    {
        return (new SundryInventoryCountService)->weighedLossQuantity($periodSalesQuantity);
    }

    /**
     * 数量明細（在庫管理あり）。
     */
    public function rows(): LengthAwarePaginator
    {
        $query = $this->filteredItemsQuery()->with('item:id,is_managed_stock');

        // 量り売りは旧Accessと同じ出力順（追加した商品は末尾）。
        if ($this->isWeighed()) {
            $query->orderByRaw('display_order IS NULL')->orderBy('display_order');
        } else {
            $query->orderBy('category2_code');
        }

        $query->orderBy('item_code')->orderBy('id');

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

    public function monthlyOpenings(Collection $amountRows): array
    {
        return app(\App\Services\SundryInventoryCount\SundryMonthlyOpeningDisplay::class)->build($this->record, $amountRows);
    }

    /**
     * @return array{managed: array<int, array<string, mixed>>, unmanaged: array<int, array<string, mixed>>, totals: array<string, array<string, mixed>>}
     */
    public function summary(): array
    {
        return (new SundryInventoryCountService)->summary($this->record);
    }

    public function valuationAvailable(): bool
    {
        return ! $this->isWeighed() && SundryQuantityValuationService::available();
    }

    public function valuationRows(bool $filtered = false): Collection
    {
        if (! $this->valuationAvailable()) {
            return collect();
        }

        $query = WmsSundryValuationItem::where('sundry_inventory_count_id', $this->record->id);
        if ($filtered) {
            $query->with('item:id,is_managed_stock');
            if ($this->stateFilter === 'uncounted') {
                $query->whereNull('quantity');
            }
            if ($this->categoryFilter !== '') {
                $query->whereIn('amount_id', $this->record->amounts()->where('category2_code', $this->categoryFilter)->select('id'));
            }
            foreach (['item_code' => trim(mb_convert_kana($this->itemCodeFilter, 'as')), 'item_name' => trim($this->itemNameFilter)] as $column => $value) {
                if ($value !== '') {
                    $query->where($column, 'like', '%'.addcslashes($value, '%_\\').'%');
                }
            }
        }

        return $query->orderBy('amount_id')->orderBy('item_code')->orderBy('id')->get();
    }

    public function amountApplied(): bool
    {
        return SundryAmountAdjustmentService::isApplied((int) $this->record->id);
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
        if ($this->listTab === 'valuation') {
            return $this->amountRows()->mapWithKeys(fn ($row) => [$row->category2_code => "[{$row->category2_code}] {$row->category2_name}"])->all();
        }

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

    public function formatAmount(mixed $amount, int $decimals = 0): string
    {
        return $amount === null ? '-' : '¥'.number_format((float) $amount, $decimals);
    }

    /**
     * 入力欄の初期値（末尾の0を落とした数値文字列。未入力は空文字）。
     */
    public function inputValue(mixed $value, int $decimals = 3): string
    {
        if ($value === null) {
            return '';
        }

        $formatted = number_format((float) $value, $decimals, '.', '');

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
        $valuation = is_array($changes['valuation'] ?? null) ? $changes['valuation'] : [];
        $amounts = array_filter(
            is_array($changes['amounts'] ?? null) ? $changes['amounts'] : [],
            fn (mixed $change): bool => is_array($change),
        );

        if ($items === [] && $amounts === [] && $valuation === []) {
            return true;
        }

        try {
            $result = DB::connection('sakemaru')->transaction(function () use ($items, $amounts, $valuation): array {
                $result = (new SundryInventoryCountService)->saveChanges($this->record, $items, $amounts, $this->actorName());
                $result['valuation'] = $valuation === [] ? 0 : (new SundryQuantityValuationService)->save($this->record, $valuation, $this->actorName());

                return $result;
            });
            $this->record->refresh();

            Notification::make()
                ->success()
                ->title('入力を反映しました')
                ->body($this->isWeighed()
                    ? "量り売り明細: {$result['items']}件"
                    : "数量明細: {$result['items']}件 / 金額明細: {$result['amounts']}件 / 非管理品数量: {$result['valuation']}件")
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
            : ($e instanceof \Illuminate\Database\QueryException
                ? 'データベースへの保存・照会に失敗しました。入力値を変更せず、処理履歴を管理者へ確認してください。'
                : $e->getMessage());

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
            Action::make('finalizeSundry')
                ->label('棚卸し最終確定')->icon('heroicon-o-check-circle')->color('danger')
                ->disabled(fn () => ! Schema::connection('sakemaru')->hasTable(SundryFinalizationService::TABLE)
                    || $this->record->status !== WmsSundryInventoryCount::STATUS_CONFIRMED || $this->finalizationRecord() !== null)
                ->modalWidth('5xl')->modalHeading('棚卸し最終確定の適用対象・日付')->schema([
                    Select::make('application_scope')->label('適用対象')->options(SundryFinalizationScope::options())
                        ->required()->default(fn () => \App\Services\SundryInventoryCount\SundrySubmittedDifference::eligible($this->record)
                            ? SundryFinalizationScope::SUBMITTED_SEPTEMBER : SundryFinalizationScope::ALL),
                    DatePicker::make('date')->label('適用日')->required()->default(fn () => \App\Services\SundryInventoryCount\SundrySubmittedDifference::eligible($this->record)
                        ? config('sundry_submitted_difference.process_date') : $this->record->count_date),
                    ViewField::make('zero_difference_row_ids')->label('差異0指定（数量・金額を調整しない商品）')
                        ->view('filament.resources.wms-sundry-inventory-count.pages.partials.zero-difference-selection')
                        ->viewData(fn () => ['options' => $this->zeroDifferenceOptions()])->default([])->rules(['array'])->live(),
                ])
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalFooterActionsAlignment(Alignment::End)
                ->modalCancelActionLabel('確認せず閉じる')->modalSubmitActionLabel('差異を確認')
                ->action(function (array $data): void {
                    try {
                        $preview = app(SundryFinalizationService::class)->preview($this->record, $data['date'], $data['application_scope'], $data['zero_difference_row_ids'] ?? []);
                        $this->finalizationPreview = ['date' => $data['date'], 'token' => $preview['token'],
                            'application_scope' => $preview['snapshot']['application_scope'],
                            'zero_difference_row_ids' => $preview['snapshot']['zero_difference_row_ids'],
                            'excluded_lines' => collect($preview['snapshot']['excluded_lines'])->map(fn ($row) => \Illuminate\Support\Arr::except($row, ['original_count_row']))->all(),
                            'total' => $preview['snapshot']['total_amount'], 'count_date' => $preview['snapshot']['count_date'],
                            'lines' => collect($preview['snapshot']['lines'])->map(fn ($row) => \Illuminate\Support\Arr::only($row,
                                ['kind', 'item_code', 'item_name', 'category1_code', 'category1_name', 'category2_code', 'category2_name', 'count_theory', 'count_actual', 'quantity_before', 'quantity_after', 'book_before', 'book_after', 'difference_quantity', 'unit_price', 'amount', 'rounding_adjustment', 'book_reconciliation', 'department_adjustment', 'reason']))->all()];
                        $this->replaceMountedAction('finalizeSundryConfirmation');
                    } catch (\Throwable $e) {
                        $this->notifyFailure('差異を確認できません', $e);
                    }
                }),
            Action::make('applySundry')
                ->label('在庫へ適用')->icon('heroicon-o-arrow-path')->color('danger')
                ->disabled(fn () => ! in_array($this->finalizationRecord()?->status, ['recorded', 'apply_failed'], true))
                ->requiresConfirmation()->modalHeading('保存済み実棚変更伝票を適用')
                ->modalDescription('確定後の受払・原価変更を再検証し、在庫へ差分を適用します。実棚数量への上書きは行いません。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalFooterActionsAlignment(Alignment::End)
                ->modalCancelActionLabel('適用せず閉じる')->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit')->label('適用を依頼')->color('danger'))
                ->action(function (): void {
                    try {
                        app(SundryFinalizationService::class)->requestApplication($this->record, auth()->id());
                        Notification::make()->success()->title('在庫への適用を依頼しました')->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('在庫へ適用できません', $e);
                    }
                }),
            Action::make('downloadFinalization')
                ->label('最終差異Excel')->icon('heroicon-o-table-cells')->color('gray')
                ->disabled(fn () => $this->finalizationRecord() === null)
                ->action(function () {
                    $record = $this->finalizationRecord();
                    abort_unless($record, 404);
                    $content = (new SundryFinalizationWorkbook)->generate($record);
                    return response()->streamDownload(fn () => print($content), '雑貨棚卸し最終差異_'.$this->record->count_no.'.xlsx',
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                }),
            Action::make('cancelSundryFinalization')
                ->label('未適用の最終確定取消')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->disabled(fn () => ! in_array($this->finalizationRecord()?->status, ['queued', 'record_failed', 'recorded', 'apply_failed'], true))
                ->requiresConfirmation()->modalHeading('未適用の最終確定を取り消す')
                ->modalDescription('確定差異・実棚変更伝票の履歴を残して取消します。入力値は変更しません。再計算後、改めて最終確定してください。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalFooterActionsAlignment(Alignment::End)
                ->modalCancelActionLabel('取り消さず閉じる')->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit')->label('最終確定を取り消す')->color('danger'))
                ->action(function (): void {
                    try {
                        app(SundryFinalizationService::class)->cancelUnapplied($this->record, auth()->id());
                        Notification::make()->success()->title('未適用の最終確定を取り消しました')->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('最終確定を取り消せません', $e);
                    }
                }),
            Action::make('prepareValuation')
                ->label('数量入力を準備')
                ->icon('heroicon-o-calculator')
                ->visible(fn () => $this->valuationAvailable() && $this->isEditable())
                ->schema([
                    Select::make('amount_id')->label('中分類')->required()->default('all')
                        ->options(fn () => ['all' => 'すべての中分類（入力済み金額は保持）'] + $this->amountRows()->mapWithKeys(fn ($row) => [$row->id => "[{$row->category2_code}] {$row->category2_name}"])->all()),
                    Checkbox::make('refresh_prices')->label('未入力商品の単価・原価率を再取得する（入力済みは保持）')->default(false),
                ])
                ->modalHeading('非管理品の商品数量入力を開始')
                ->modalDescription('棚卸し日までの販売単価と分類原価率を保存します。既存の実棚金額が入力済みの分類は変更しません。未入力は0とは扱いません。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('数量入力を開始')->color('danger'))
                ->modalCancelActionLabel('開始せず閉じる')
                ->action(function (array $data): void {
                    try {
                        $service = new SundryQuantityValuationService;
                        if ($data['amount_id'] === 'all') {
                            $result = $service->prepareUnenteredCategories($this->record, (bool) ($data['refresh_prices'] ?? false));
                        } else {
                            $result = ['prepared_items' => $service->prepare($this->record, (int) $data['amount_id'], (bool) ($data['refresh_prices'] ?? false)), 'preserved_categories' => 0];
                        }
                        $this->clearFilters();
                        $this->listTab = 'valuation';
                        Notification::make()->success()->title("非管理品数量明細 {$result['prepared_items']}件を準備しました")
                            ->body("入力済み金額の保持: {$result['preserved_categories']}分類")->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('数量入力を開始できません', $e);
                    }
                }),
            Action::make('applyAmountAdjustment')
                ->label(fn () => $this->amountApplied() ? '非管理品金額適用済み' : '非管理品金額適用')
                ->icon('heroicon-o-check-circle')->color('danger')
                ->visible(false)
                ->disabled(fn () => ! config('wms_sundry_inventory.amount_posting_enabled')
                    || $this->record->status !== WmsSundryInventoryCount::STATUS_CONFIRMED || $this->amountApplied())
                ->schema([
                    DatePicker::make('date')->label('適用日')->default(fn () => $this->record->count_date)->required(),
                    TextInput::make('book_reference')->label('照合した帳簿・基準日')->required()->minLength(5)->maxLength(500),
                    Checkbox::make('reviewed')->label('分類別の理論金額・実棚金額と帳簿の一致を確認した')->accepted(),
                ])
                ->modalHeading('非管理品の金額差異を適用')
                ->modalDescription('商品数量入力した分類だけ金額調整を登録します。実在庫・LOT・管理品は変更しません。適用月の在庫金額報告書生成時に反映されます。適用後は確定解除できません。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('金額差異を適用')->color('danger'))
                ->modalCancelActionLabel('適用せず閉じる')
                ->action(function (array $data): void {
                    try {
                        $saved = (new SundryAmountAdjustmentService)->apply($this->record, $data['date'], $data['book_reference'], auth()->id());
                        Notification::make()->success()->title("非管理品の金額調整 {$saved}件を登録しました")->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('金額差異を適用できません', $e);
                    }
                }),
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
                ->modalDescription(fn (): string => $this->isWeighed()
                    ? '対象商品以外の量り売り商品を、商品CDで追加できます（在庫管理ありの商品のみ）。'
                    : '対象中分類以外の商品も、商品CDで追加できます。在庫管理ありの商品は数量明細に、在庫管理なしの商品はその中分類を金額明細に追加します。')
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
                ->modalDescription(fn (): string => $this->isWeighed()
                    ? '選択した日の終了時点の受払で、理論数・原価と期間売上を再計算します。入力済みの実棚は変更しません。'
                    : '選択した日の終了時点の受払で、理論数・原価・金額受払を再計算します。在庫金額報告書との突合も取り直します。入力済みの実棚は変更しません。')
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
                    DatePicker::make('sales_from_date')
                        ->label('期間売上の開始日（前回棚卸日の翌日）')
                        ->default(fn () => $this->record->sales_from_date?->toDateString())
                        ->visible(fn (): bool => $this->isWeighed())
                        ->required(fn (): bool => $this->isWeighed()),
                    Checkbox::make('reset_manual_openings')
                        ->label('手入力した前残金額も自動の値（前回棚卸の実棚・旧システム残高）に戻す')
                        ->visible(fn (): bool => ! $this->isWeighed())
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = (new SundryInventoryCountService)->refreshTheory(
                            $this->record,
                            (string) $data['end_date'],
                            (bool) ($data['reset_manual_openings'] ?? false),
                            $this->isWeighed() ? (string) ($data['sales_from_date'] ?? '') : null,
                        );
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('理論在庫を更新しました')
                            ->body($this->isWeighed()
                                ? "受払終了日: {$result['end_date']} / 量り売り明細: 更新{$result['updated_items']}件・追加{$result['inserted_items']}件"
                                : "受払終了日: {$result['end_date']} / 数量明細: 更新{$result['updated_items']}件・追加{$result['inserted_items']}件 / 金額明細: 更新{$result['updated_amounts']}件・追加{$result['inserted_amounts']}件")
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
                ->modalDescription(fn (): string => $this->isWeighed()
                    ? '実棚数が未入力の明細を、すべて実棚0（カメ0）として登録します。'
                    : '実棚数が未入力の数量明細を、すべて実棚0として登録します。金額明細（在庫管理なし）は対象外です。')
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
                        ->visible(fn (): bool => ! $this->isWeighed())
                        ->placeholder('全て（未選択で全部門出力）'),
                    Checkbox::make('show_system_quantity')
                        ->label('理論数を印字する')
                        ->default(true),
                    Checkbox::make('include_amount_page')
                        ->label('在庫管理なし（金額）の記入ページを付ける')
                        ->visible(fn (): bool => ! $this->isWeighed())
                        ->default(true),
                ])
                ->modalHeading('指示書ダウンロード')
                ->modalDescription(fn (): string => $this->isWeighed()
                    ? '量り売り商品の指示書を出力します。実棚数をカメとQTに分けて記入し、この画面で入力してください。'
                    : '中分類ごとに改ページした指示書を出力します。実棚数を記入し、この画面で入力してください。')
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
                    $filename = '棚卸し指示書_'.($this->isWeighed() ? '量り売り' : '雑貨').'_'.($this->record->count_no ?? 'unknown').'.pdf';

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
                        $xlsxContent = $this->isWeighed()
                            ? (new WeighedInventoryDifferenceWorkbookService)->generate($this->record)
                            : (new SundryInventoryDifferenceWorkbookService)->generate($this->record);
                    } catch (\Throwable $e) {
                        $this->notifyFailure('差異表を生成できません', $e);

                        return null;
                    }

                    $filename = '棚卸し差異表_'.($this->isWeighed() ? '量り売り' : '雑貨').'_'.($this->record->warehouse_code ?? '').'_'.($this->record->count_no ?? 'unknown').'.xlsx';

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
                ->modalHeading(fn (): string => $this->kindTitle().'確定')
                ->modalDescription(function (): string {
                    $uncountedItems = $this->itemCounts()['uncounted'];

                    if ($this->isWeighed()) {
                        return "入力を締めて確定します。未入力: {$uncountedItems}件（未入力の明細は差異に含めません）。在庫や伝票は変更しません。";
                    }
                    $uncountedAmounts = $this->amountRows()->whereNull('counted_amount')->count();

                    return "入力を締めて確定します。未入力: 数量明細 {$uncountedItems}件 / 金額明細 {$uncountedAmounts}件（未入力の明細は差異に含めません）。"
                        .'在庫や伝票は変更しません。入力確定後に棚卸し最終確定を実行できます。';
                })
                ->modalSubmitActionLabel('確定する')
                ->modalCancelActionLabel('確定せず閉じる')
                ->action(function (): void {
                    try {
                        (new SundryInventoryCountService)->confirm($this->record, auth()->id());
                        $this->record->refresh();

                        Notification::make()->success()->title($this->kindTitle().'を確定しました')->send();
                    } catch (\Throwable $e) {
                        $this->notifyFailure('確定できません', $e);
                    }
                }),

            Action::make('reopen')
                ->label('確定解除')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $this->record->status === WmsSundryInventoryCount::STATUS_CONFIRMED)
                ->disabled(fn () => $this->amountApplied() || $this->finalizationRecord() !== null)
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
                ->modalHeading(fn (): string => $this->kindTitle().'取消')
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

                    Notification::make()->success()->title($this->kindTitle().'を取り消しました')->send();

                    return redirect(WmsSundryInventoryCountResource::getUrl());
                }),
        ];
    }
}
