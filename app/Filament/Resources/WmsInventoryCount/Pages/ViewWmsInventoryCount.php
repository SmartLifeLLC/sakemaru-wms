<?php

namespace App\Filament\Resources\WmsInventoryCount\Pages;

use App\Filament\Resources\WmsInventoryCountResource;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryCountService;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use App\Services\InventoryCount\InventoryInstructionPdfService;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

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

    public string $itemNameFilter = '';

    public string $listTab = 'all';

    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    public int $perPage = 100;

    public int $page = 1;

    public bool $editModalOpen = false;

    public ?int $editItemId = null;

    public string $editFirstCountQty = '';

    public string $editSecondCountQty = '';

    public string $editFinalCountQty = '';

    public function mount(WmsInventoryCount $record): void
    {
        $record->load(['createdByUser', 'confirmedByUser']);
        $this->record = $record;
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

    public function search(): void
    {
        $this->page = 1;
    }

    public function setListTab(string $tab): void
    {
        if (! in_array($tab, ['all', 'diff', 'uncounted'], true)) {
            return;
        }
        $this->listTab = $tab;
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->floorFilter = '';
        $this->areaFilter = '';
        $this->itemCodeFilter = '';
        $this->locationFilter = '';
        $this->itemNameFilter = '';
        $this->search();
    }

    public function loadMore(): void
    {
        $this->page++;
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
        $this->page = 1;
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

    public function rows(): Collection
    {
        $query = $this->filteredQuery();
        $this->applySort($query);

        return $query->limit($this->page * $this->perPage)->get();
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
        $this->applyTextFilter($query, $this->itemNameFilter, ['item_name']);
    }

    private function applyTabFilter(\Illuminate\Database\Eloquent\Builder $query, string $tab): void
    {
        match ($tab) {
            'diff' => $query->whereNotNull('difference_quantity')->where('difference_quantity', '!=', 0),
            'uncounted' => $query->whereNull('first_count_quantity'),
            default => null,
        };
    }

    private function applySort(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if ($this->sortColumn !== '') {
            $query->orderBy($this->sortColumn, $this->sortDirection);
        } else {
            $query->orderBy('floor_name')
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

        $first = $this->editFirstCountQty !== '' ? (int) $this->editFirstCountQty : null;
        $second = $this->editSecondCountQty !== '' ? (int) $this->editSecondCountQty : null;
        $final = $this->editFinalCountQty !== '' ? (int) $this->editFinalCountQty : null;

        $item->first_count_quantity = $first;
        $item->second_count_quantity = $second;
        $item->final_count_quantity = $final;
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

        Notification::make()->success()->title('カウント数を保存しました')->send();
        $this->closeEditModal();
    }

    // ========================================
    // Inline Save
    // ========================================

    public function saveInlineChanges(array $changes): void
    {
        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ])) {
            Notification::make()->danger()->title('このステータスでは編集できません')->send();

            return;
        }

        $count = 0;
        foreach ($changes as $itemId => $data) {
            $item = WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
                ->where('id', (int) $itemId)
                ->first();

            if (! $item) {
                continue;
            }

            $first = isset($data['first']) && $data['first'] !== null ? (int) $data['first'] : null;
            $second = isset($data['second']) && $data['second'] !== null ? (int) $data['second'] : null;
            $final = isset($data['final']) && $data['final'] !== null ? (int) $data['final'] : null;

            $item->first_count_quantity = $first;
            $item->second_count_quantity = $second;
            $item->final_count_quantity = $final;
            $item->last_counted_at = now();
            $item->input_count = ($item->input_count ?? 0) + 1;

            $countedQty = $final ?? $second ?? $first;
            if ($countedQty !== null) {
                $item->difference_quantity = $countedQty - (int) $item->system_quantity;
                $item->difference_amount = $item->difference_quantity * (float) $item->cost_price;
            } else {
                $item->difference_quantity = null;
                $item->difference_amount = null;
            }

            $item->save();
            $count++;
        }

        Notification::make()->success()->title("{$count}件のカウント数を保存しました")->send();
    }

    // ========================================
    // Header Actions
    // ========================================

    protected function getHeaderActions(): array
    {
        $record = $this->record;

        return [
            Action::make('downloadInstructionPdf')
                ->label('棚卸指示書PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $record->status !== WmsInventoryCount::STATUS_CANCELLED)
                ->action(function () use ($record) {
                    $pdfContent = (new InventoryInstructionPdfService)->generate($record);
                    $filename = '棚卸指示書_' . ($record->count_no ?? 'unknown') . '.pdf';

                    return response()->streamDownload(
                        fn () => print($pdfContent),
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
                ->requiresConfirmation()
                ->modalHeading('差異計算')
                ->modalDescription('実棚数量と理論在庫の差異を計算します。ステータスが「確認済」に変更されます。')
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
                    $filename = '棚卸差分確認_' . ($record->count_no ?? 'unknown') . '.pdf';

                    return response()->streamDownload(
                        fn () => print($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('confirm')
                ->label('確定')
                ->icon('heroicon-o-check-circle')
                ->color('danger')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_CHECKED)
                ->requiresConfirmation()
                ->modalHeading('棚卸し確定')
                ->modalDescription('棚卸しを確定します。差異分の在庫調整が実行されます。この操作は取り消せません。')
                ->action(function () use ($record) {
                    (new InventoryCountService)->confirm($record, auth()->id());
                    Notification::make()->success()->title('棚卸しを確定しました')->send();

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
