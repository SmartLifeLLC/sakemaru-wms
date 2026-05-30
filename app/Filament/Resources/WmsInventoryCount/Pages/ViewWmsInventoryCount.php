<?php

namespace App\Filament\Resources\WmsInventoryCount\Pages;

use App\Filament\Resources\WmsInventoryCountResource;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Services\InventoryCount\InventoryCountService;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use App\Services\InventoryCount\InventoryInstructionPdfService;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ViewWmsInventoryCount extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = WmsInventoryCountResource::class;

    protected string $view = 'filament.resources.wms-inventory-count.pages.view-wms-inventory-count';

    public WmsInventoryCount $record;

    public string $floorFilter = '';

    public string $areaFilter = '';

    public string $itemCodeFilter = '';

    public string $locationFilter = '';

    public array $selectedLocationFilters = [];

    public string $itemNameFilter = '';

    public string $listTab = 'all';

    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    public int $itemPage = 1;

    public int $itemPerPage = 200;

    public int $activeCountRound = 1;

    public bool $editModalOpen = false;

    public ?int $editItemId = null;

    public string $editFirstCountQty = '';

    public string $editSecondCountQty = '';

    public string $editFinalCountQty = '';

    public function mount(WmsInventoryCount $record): void
    {
        $record->load(['createdByUser', 'confirmedByUser']);
        $this->record = $record;
        $this->activeCountRound = $this->currentProgressRound();
    }

    public function getTitle(): string|Htmlable
    {
        return "棚卸し詳細: {$this->record->count_no}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            WmsInventoryCountResource::getUrl() => '棚卸し',
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
    // Filter / Tab / Sort
    // ========================================

    public function search(): void {}

    public function setListTab(string $tab): void
    {
        if (! in_array($tab, ['all', 'diff', 'matched', 'uncounted'], true)) {
            return;
        }
        $this->listTab = $tab;
        $this->itemPage = 1;
    }

    public function updatedListTab(string $tab): void
    {
        if (! in_array($tab, ['all', 'diff', 'matched', 'uncounted'], true)) {
            $this->listTab = 'all';
        }

        $this->itemPage = 1;
    }

    public function clearFilters(): void
    {
        $this->floorFilter = '';
        $this->areaFilter = '';
        $this->itemCodeFilter = '';
        $this->locationFilter = '';
        $this->selectedLocationFilters = [];
        $this->itemNameFilter = '';
        $this->search();
    }

    public function updatedFloorFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedAreaFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedItemCodeFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedLocationFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedSelectedLocationFilters(): void
    {
        $this->itemPage = 1;
    }

    public function updatedItemNameFilter(): void
    {
        $this->itemPage = 1;
    }

    public function sortBy(string $column): void
    {
        $allowed = ['item_code', 'item_name', 'system_quantity', 'difference_quantity'];
        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->itemPage = 1;
    }

    public function sortIndicator(string $column): string
    {
        if ($this->sortColumn !== $column) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    // ========================================
    // Data
    // ========================================

    public function floorOptions(): array
    {
        return WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
            ->whereNotNull('floor_name')
            ->distinct()
            ->orderBy('floor_name')
            ->pluck('floor_name')
            ->toArray();
    }

    public function locationOptions(): array
    {
        return WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
            ->whereNotNull('location_no')
            ->distinct()
            ->orderBy('location_no')
            ->pluck('location_no')
            ->toArray();
    }

    public function rows(): LengthAwarePaginator
    {
        $query = WmsInventoryCountItem::where('inventory_count_id', $this->record->id);
        $this->applyFilters($query);
        $this->applyTabFilter($query, $this->listTab);
        $this->applySort($query);

        return $query->paginate($this->itemPerPage, ['*'], 'inventory_items_page', $this->itemPage);
    }

    public function goToItemPage(int $page): void
    {
        $lastPage = max(1, (int) ceil($this->filteredQuery()->count() / $this->itemPerPage));
        $this->itemPage = min(max(1, $page), $lastPage);
    }

    public function previousItemPage(): void
    {
        $this->goToItemPage($this->itemPage - 1);
    }

    public function nextItemPage(): void
    {
        $this->goToItemPage($this->itemPage + 1);
    }

    public function setActiveCountRound(int $round): void
    {
        if ($round !== $this->currentProgressRound()) {
            return;
        }

        $this->activeCountRound = $round;
    }

    public function activeRoundLabel(): string
    {
        return "{$this->activeCountRound}回目";
    }

    public function roundLabel(int $round): string
    {
        return "{$round}回目";
    }

    public function isRoundConfirmed(int $round): bool
    {
        return $this->record->{$this->roundConfirmedAtColumn($round)} !== null;
    }

    public function totalCount(): int
    {
        return $this->filteredQuery()->count();
    }

    public function countForTab(string $tab): int
    {
        $query = WmsInventoryCountItem::where('inventory_count_id', $this->record->id);
        $this->applyFilters($query);
        $this->applyTabFilter($query, $tab);

        return $query->count();
    }

    private function filteredQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = WmsInventoryCountItem::where('inventory_count_id', $this->record->id);
        $this->applyFilters($query);
        $this->applyTabFilter($query, $this->listTab);

        return $query;
    }

    private function applyFilters(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if ($this->floorFilter !== '') {
            $query->where('floor_name', $this->floorFilter);
        }

        $this->applyTextFilter($query, $this->areaFilter, ['location_code1']);
        $this->applyTextFilter($query, $this->itemCodeFilter, ['item_code']);
        $this->applyTextFilter($query, $this->locationFilter, ['location_no', 'location_code1', 'location_code2', 'location_code3']);
        if ($this->selectedLocationFilters !== []) {
            $query->whereIn('location_no', $this->selectedLocationFilters);
        }
        $this->applyTextFilter($query, $this->itemNameFilter, ['item_name']);
    }

    private function applyTabFilter(\Illuminate\Database\Eloquent\Builder $query, string $tab): void
    {
        match ($tab) {
            'diff' => $query->whereNotNull('difference_quantity')->where('difference_quantity', '!=', 0),
            'matched' => $query
                ->whereNotNull($this->roundColumn($this->activeCountRound))
                ->whereColumn($this->roundColumn($this->activeCountRound), 'system_quantity'),
            'uncounted' => $query->whereNull($this->roundColumn($this->activeCountRound)),
            default => null,
        };
    }

    private function applySort(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if ($this->sortColumn !== '') {
            $query->orderBy($this->sortColumn, $this->sortDirection);
        } else {
            $query->orderByRaw("
                CASE
                    WHEN floor_name = '1F' THEN 1
                    WHEN floor_name = '2F' THEN 2
                    WHEN floor_name LIKE 'YX%' THEN 3
                    ELSE 4
                END
            ")
                ->orderBy('floor_name')
                ->orderBy('location_code1')
                ->orderBy('location_code2')
                ->orderBy('location_code3');
        }
        $query->orderBy('id');
    }

    private function applyTextFilter(\Illuminate\Database\Eloquent\Builder $query, string $value, array $columns): void
    {
        $value = trim(mb_convert_kana($value, 'as'));
        if ($value === '') {
            return;
        }

        $query->where(function ($q) use ($value, $columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', "%{$value}%");
            }
        });
    }

    // ========================================
    // Edit Modal
    // ========================================

    public function openEditModal(int $itemId): void
    {
        $item = WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
            ->where('id', $itemId)
            ->first();

        if (! $item) {
            return;
        }

        $this->editItemId = $item->id;
        $this->editFirstCountQty = $item->first_count_quantity !== null ? (string) (int) $item->first_count_quantity : '';
        $this->editSecondCountQty = $item->second_count_quantity !== null ? (string) (int) $item->second_count_quantity : '';
        $this->editFinalCountQty = $item->final_count_quantity !== null ? (string) (int) $item->final_count_quantity : '';
        $this->editModalOpen = true;
    }

    public function closeEditModal(): void
    {
        $this->editModalOpen = false;
        $this->editItemId = null;
        $this->editFirstCountQty = '';
        $this->editSecondCountQty = '';
        $this->editFinalCountQty = '';
    }

    public function editModalItem(): ?WmsInventoryCountItem
    {
        if (! $this->editItemId) {
            return null;
        }

        return WmsInventoryCountItem::find($this->editItemId);
    }

    public function saveEditModal(): void
    {
        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ])) {
            Notification::make()->danger()->title('このステータスでは編集できません')->send();

            return;
        }

        $item = WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
            ->where('id', $this->editItemId)
            ->first();

        if (! $item) {
            Notification::make()->danger()->title('明細が見つかりません')->send();

            return;
        }

        $first = $this->activeCountRound === 1
            ? ($this->editFirstCountQty !== '' ? (int) $this->editFirstCountQty : null)
            : $item->first_count_quantity;
        $second = $this->activeCountRound === 2
            ? ($this->editSecondCountQty !== '' ? (int) $this->editSecondCountQty : null)
            : $item->second_count_quantity;
        $final = $this->activeCountRound === 3
            ? ($this->editFinalCountQty !== '' ? (int) $this->editFinalCountQty : null)
            : $item->final_count_quantity;

        $oldFirst = $item->first_count_quantity;
        $oldSecond = $item->second_count_quantity;
        $oldFinal = $item->final_count_quantity;

        if ($this->record->status === WmsInventoryCount::STATUS_DRAFT) {
            (new InventoryCountService)->startCounting($this->record);
            $this->record->refresh();
        }

        $item->first_count_quantity = $first;
        $item->second_count_quantity = $second;
        $item->final_count_quantity = $final;
        $this->setChangedActorNames($item, [
            1 => [$oldFirst, $first],
            2 => [$oldSecond, $second],
            3 => [$oldFinal, $final],
        ]);
        $item->last_counted_at = now();
        $item->input_count = ($item->input_count ?? 0) + 1;

        if ($this->record->status === WmsInventoryCount::STATUS_CHECKED) {
            $finalQty = $final ?? $second ?? $first;
            if ($finalQty !== null) {
                $item->final_count_quantity = $finalQty;
                $item->difference_quantity = $finalQty - (int) $item->system_quantity;
                $item->difference_amount = $item->difference_quantity * (float) $item->cost_price;
            } else {
                $item->final_count_quantity = null;
                $item->difference_quantity = null;
                $item->difference_amount = null;
            }
        }

        $item->save();
        $this->writeWebCountLogs($item, [
            1 => [$oldFirst, $first],
            2 => [$oldSecond, $second],
            3 => [$oldFinal, $final],
        ]);

        Notification::make()->success()->title('カウント数を保存しました')->send();
        $this->closeEditModal();
    }

    // ========================================
    // Inline Save
    // ========================================

    public function saveInlineChanges(array $changes): void
    {
        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ])) {
            Notification::make()->danger()->title('このステータスでは編集できません')->send();

            return;
        }

        $count = 0;
        if ($this->record->status === WmsInventoryCount::STATUS_DRAFT) {
            (new InventoryCountService)->startCounting($this->record);
            $this->record->refresh();
        }

        foreach ($changes as $itemId => $data) {
            $item = WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
                ->where('id', (int) $itemId)
                ->first();

            if (! $item) {
                continue;
            }

            $first = $this->activeCountRound === 1
                ? (isset($data['first']) && $data['first'] !== null ? (int) $data['first'] : null)
                : $item->first_count_quantity;
            $second = $this->activeCountRound === 2
                ? (isset($data['second']) && $data['second'] !== null ? (int) $data['second'] : null)
                : $item->second_count_quantity;
            $final = $this->activeCountRound === 3
                ? (isset($data['final']) && $data['final'] !== null ? (int) $data['final'] : null)
                : $item->final_count_quantity;

            $oldFirst = $item->first_count_quantity;
            $oldSecond = $item->second_count_quantity;
            $oldFinal = $item->final_count_quantity;

            $item->first_count_quantity = $first;
            $item->second_count_quantity = $second;
            $item->final_count_quantity = $final;
            $this->setChangedActorNames($item, [
                1 => [$oldFirst, $first],
                2 => [$oldSecond, $second],
                3 => [$oldFinal, $final],
            ]);
            $item->last_counted_at = now();
            $item->input_count = ($item->input_count ?? 0) + 1;

            $countedQty = match ($this->activeCountRound) {
                1 => $first,
                2 => $second,
                3 => $final,
            };
            if ($countedQty !== null) {
                $item->difference_quantity = $countedQty - (int) $item->system_quantity;
                $item->difference_amount = $item->difference_quantity * (float) $item->cost_price;
            } else {
                $item->difference_quantity = null;
                $item->difference_amount = null;
            }

            $item->save();
            $this->writeWebCountLogs($item, [
                1 => [$oldFirst, $first],
                2 => [$oldSecond, $second],
                3 => [$oldFinal, $final],
            ]);
            $count++;
        }

        Notification::make()->success()->title("{$count}件のカウント数を保存しました")->send();
    }

    public function calculateActiveRoundDifferences(): void
    {
        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ], true)) {
            Notification::make()->danger()->title('カウント開始後に差異計算できます')->send();

            return;
        }

        $this->calculateRoundDifferences($this->activeCountRound);

        $this->listTab = 'diff';
        $this->itemPage = 1;

        Notification::make()->success()->title($this->activeRoundLabel().'の差異計算が完了しました')->send();
    }

    public function confirmRound(int $round): void
    {
        if (! in_array($round, [1, 2, 3], true)) {
            return;
        }

        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ], true)) {
            Notification::make()->danger()->title('カウント開始後に確定できます')->send();

            return;
        }

        if ($round > $this->currentProgressRound()) {
            Notification::make()->danger()->title('現在進行中より先の回数は確定できません')->send();

            return;
        }

        $this->calculateRoundDifferences($round);

        $updates = [
            $this->roundConfirmedAtColumn($round) => now(),
            $this->roundConfirmedByColumn($round) => auth()->id(),
        ];

        if ($round < 3) {
            $this->seedNextRoundQuantity($round);

            $updates['current_count_round'] = max($this->currentProgressRound(), $round + 1);
            $updates['status'] = WmsInventoryCount::STATUS_COUNTING;
            $this->record->update($updates);
            $this->record->refresh();
            $this->activeCountRound = $this->currentProgressRound();
            $this->listTab = 'all';
            $this->itemPage = 1;
            Notification::make()
                ->success()
                ->title($this->roundLabel($round).'を確定しました')
                ->body($this->activeRoundLabel().'の入力に進みます')
                ->send();

            return;
        }

        $updates['current_count_round'] = 3;
        $updates['status'] = WmsInventoryCount::STATUS_CHECKED;
        $this->record->update($updates);
        $this->record->refresh();
        Notification::make()->success()->title('3回目を確定しました')->body('差異確認済に変更しました')->send();
    }

    public function confirmActiveRound(): void
    {
        $this->confirmRound($this->activeCountRound);
    }

    public function reopenFinalRound(): void
    {
        if ($this->record->status !== WmsInventoryCount::STATUS_CHECKED) {
            Notification::make()->danger()->title('3回目に戻せる状態ではありません')->send();

            return;
        }

        $this->record->update([
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 3,
            'final_count_confirmed_at' => null,
            'final_count_confirmed_by' => null,
        ]);

        $this->record->refresh();
        $this->activeCountRound = 3;
        $this->listTab = 'all';
        $this->itemPage = 1;

        Notification::make()->success()->title('3回目の入力に戻しました')->send();
    }

    private function writeWebCountLogs(WmsInventoryCountItem $item, array $rounds): void
    {
        foreach ($rounds as $round => [$old, $new]) {
            if ((string) $old === (string) $new) {
                continue;
            }

            WmsInventoryCountItemLog::create([
                'inventory_count_item_id' => $item->id,
                'device_id' => 'WEB',
                'user_id' => auth()->id(),
                'count_round' => $round,
                'old_quantity' => $old,
                'new_quantity' => $new ?? 0,
                'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'created_at' => now(),
            ]);
        }
    }

    private function setChangedActorNames(WmsInventoryCountItem $item, array $rounds): void
    {
        $actorName = $this->currentWebActorName();

        foreach ($rounds as $round => [$old, $new]) {
            if ((string) $old === (string) $new) {
                continue;
            }

            $item->{$this->roundActorNameColumn($round)} = $actorName;
        }
    }

    private function currentWebActorName(): string
    {
        return auth()->user()?->name
            ? 'WEB: '.auth()->user()->name
            : 'WEB';
    }

    private function currentProgressRound(): int
    {
        $round = (int) ($this->record->current_count_round ?: 1);

        return min(max($round, 1), 3);
    }

    private function calculateRoundDifferences(int $round): void
    {
        $roundColumn = $this->roundColumn($round);

        WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
            ->chunkById(500, function ($items) use ($roundColumn) {
                foreach ($items as $item) {
                    $countedQty = $item->{$roundColumn};

                    if ($countedQty === null) {
                        $item->difference_quantity = null;
                        $item->difference_amount = null;
                    } else {
                        $item->difference_quantity = (int) $countedQty - (int) $item->system_quantity;
                        $item->difference_amount = (float) $item->difference_quantity * (float) $item->cost_price;
                    }

                    $item->save();
                }
            });
    }

    private function seedNextRoundQuantity(int $round): void
    {
        $currentColumn = $this->roundColumn($round);
        $nextColumn = $this->roundColumn($round + 1);

        WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
            ->whereNotNull($currentColumn)
            ->whereNull($nextColumn)
            ->update([
                $nextColumn => DB::raw($currentColumn),
                'updated_at' => now(),
            ]);
    }

    private function roundColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_quantity',
            2 => 'second_count_quantity',
            3 => 'final_count_quantity',
            default => 'first_count_quantity',
        };
    }

    private function roundActorNameColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_actor_name',
            2 => 'second_count_actor_name',
            3 => 'final_count_actor_name',
            default => 'first_count_actor_name',
        };
    }

    private function roundConfirmedAtColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_at',
            2 => 'second_count_confirmed_at',
            3 => 'final_count_confirmed_at',
            default => 'first_count_confirmed_at',
        };
    }

    private function roundConfirmedByColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_by',
            2 => 'second_count_confirmed_by',
            3 => 'final_count_confirmed_by',
            default => 'first_count_confirmed_by',
        };
    }

    // ========================================
    // Header Actions
    // ========================================

    protected function getHeaderActions(): array
    {
        $record = $this->record;

        return [
            Action::make('refreshSystemQuantities')
                ->label('現在庫に更新')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => ! in_array($record->status, [
                    WmsInventoryCount::STATUS_CONFIRMED,
                    WmsInventoryCount::STATUS_CANCELLED,
                ], true))
                ->requiresConfirmation()
                ->modalHeading('現在庫に更新')
                ->modalDescription('在庫を現時点の在庫に更新しますか？')
                ->modalSubmitActionLabel('確認')
                ->modalCancelActionLabel('更新せず閉じる')
                ->action(function () use ($record) {
                    try {
                        $result = (new InventoryCountService)->refreshSystemQuantities($record);
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('現在庫に更新しました')
                            ->body("理論在庫: {$result['updated_items']}件 / 差分再計算: {$result['updated_differences']}件")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('現在庫に更新できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('downloadInstructionPdf')
                ->label('JANブック')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $record->status !== WmsInventoryCount::STATUS_CANCELLED)
                ->action(function () use ($record) {
                    $pdfContent = (new InventoryInstructionPdfService)->generate($record);
                    $filename = 'JANブック_'.($record->count_no ?? 'unknown').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('startCounting')
                ->label('カウント開始')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalHeading('カウント開始')
                ->modalDescription('棚卸しカウントを開始します。')
                ->action(function () use ($record) {
                    (new InventoryCountService)->startCounting($record);
                    Notification::make()->success()->title('カウントを開始しました')->send();

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.view', $record);
                }),

            Action::make('calculateDifferences')
                ->label('差異計算')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_COUNTING)
                ->action(function () use ($record) {
                    (new InventoryCountService)->calculateDifferences($record);
                    Notification::make()->success()->title('差異計算が完了しました')->send();

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.view', $record);
                }),

            Action::make('downloadDiffListPdf')
                ->label('差分確認PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $record->status !== WmsInventoryCount::STATUS_DRAFT)
                ->action(function () use ($record) {
                    $pdfContent = (new InventoryDiffListPdfService)->generate($record);
                    $filename = '棚卸差分確認_'.($record->count_no ?? 'unknown').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('reopenFinalRound')
                ->label('3回目に戻す')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_CHECKED)
                ->requiresConfirmation()
                ->modalHeading('3回目に戻す')
                ->modalDescription('最終確定前の状態に戻し、3回目の入力を再開します。入力済みの3回目数量は削除しません。')
                ->action(fn () => $this->reopenFinalRound()),

            Action::make('confirm')
                ->label('確定')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_CHECKED)
                ->requiresConfirmation()
                ->modalHeading('棚卸し確定')
                ->modalDescription('棚卸しを確定し、差異分の実棚変更伝票作成キューを登録します。この操作は取り消せません。')
                ->action(function () use ($record) {
                    try {
                        (new InventoryCountService)->confirm($record, auth()->id());
                        Notification::make()->success()->title('棚卸しを確定しました')->body('差異がある場合は実棚変更伝票作成キューを登録しています。')->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('棚卸しを確定できません')
                            ->body($e->getMessage())
                            ->send();

                        return null;
                    }

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.view', $record);
                }),

            Action::make('cancel')
                ->label('取消')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => ! in_array($record->status, [
                    WmsInventoryCount::STATUS_CONFIRMED,
                    WmsInventoryCount::STATUS_CANCELLED,
                ]))
                ->requiresConfirmation()
                ->modalHeading('棚卸し取消')
                ->modalDescription('この棚卸しを取り消します。この操作は元に戻せません。')
                ->action(function () use ($record) {
                    (new InventoryCountService)->cancel($record);
                    Notification::make()->success()->title('棚卸しを取り消しました')->send();

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.index');
                }),
        ];
    }
}
