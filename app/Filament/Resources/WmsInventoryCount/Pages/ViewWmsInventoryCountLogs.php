<?php

namespace App\Filament\Resources\WmsInventoryCount\Pages;

use App\Filament\Resources\WmsInventoryCountResource;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItemLog;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ViewWmsInventoryCountLogs extends Page
{
    protected static string $resource = WmsInventoryCountResource::class;

    protected string $view = 'filament.resources.wms-inventory-count.pages.view-wms-inventory-count-logs';

    public WmsInventoryCount $record;

    public string $actorFilter = '';

    public string $roundFilter = '';

    public string $deviceFilter = '';

    public string $itemCodeFilter = '';

    public string $itemNameFilter = '';

    public int $logPage = 1;

    public int $logPerPage = 100;

    public function mount(WmsInventoryCount $record): void
    {
        $this->record = $record;
    }

    public function getTitle(): string|Htmlable
    {
        return "棚卸し作業ログ: {$this->record->count_no}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            WmsInventoryCountResource::getUrl() => '棚卸し',
            WmsInventoryCountResource::getUrl('view', ['record' => $this->record]) => $this->record->count_no,
            '#' => '作業ログ',
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

    public function updatedActorFilter(): void
    {
        $this->logPage = 1;
    }

    public function updatedRoundFilter(): void
    {
        $this->logPage = 1;
    }

    public function updatedDeviceFilter(): void
    {
        $this->logPage = 1;
    }

    public function updatedItemCodeFilter(): void
    {
        $this->logPage = 1;
    }

    public function updatedItemNameFilter(): void
    {
        $this->logPage = 1;
    }

    public function clearFilters(): void
    {
        $this->actorFilter = '';
        $this->roundFilter = '';
        $this->deviceFilter = '';
        $this->itemCodeFilter = '';
        $this->itemNameFilter = '';
        $this->logPage = 1;
    }

    public function logs(): LengthAwarePaginator
    {
        $query = $this->baseLogQuery();
        $this->applyFilters($query);

        return $query
            ->orderByDesc('wms_inventory_count_item_logs.created_at')
            ->orderByDesc('wms_inventory_count_item_logs.id')
            ->paginate($this->logPerPage, ['wms_inventory_count_item_logs.*'], 'inventory_count_logs_page', $this->logPage);
    }

    public function totalLogCount(): int
    {
        return $this->baseLogQuery()->count();
    }

    public function filteredLogCount(): int
    {
        $query = $this->baseLogQuery();
        $this->applyFilters($query);

        return $query->count();
    }

    public function actorOptions(): array
    {
        return $this->baseLogQuery()
            ->with(['picker', 'user'])
            ->orderByDesc('wms_inventory_count_item_logs.created_at')
            ->get(['wms_inventory_count_item_logs.id', 'wms_inventory_count_item_logs.device_id', 'wms_inventory_count_item_logs.user_id'])
            ->mapWithKeys(fn (WmsInventoryCountItemLog $log) => [$this->actorKey($log) => $log->actor_name])
            ->sort()
            ->toArray();
    }

    public function deviceOptions(): array
    {
        return $this->baseLogQuery()
            ->whereNotNull('wms_inventory_count_item_logs.device_id')
            ->distinct()
            ->orderBy('wms_inventory_count_item_logs.device_id')
            ->pluck('wms_inventory_count_item_logs.device_id')
            ->toArray();
    }

    public function goToLogPage(int $page): void
    {
        $lastPage = max(1, (int) ceil($this->filteredLogCount() / $this->logPerPage));
        $this->logPage = min(max(1, $page), $lastPage);
    }

    public function previousLogPage(): void
    {
        $this->goToLogPage($this->logPage - 1);
    }

    public function nextLogPage(): void
    {
        $this->goToLogPage($this->logPage + 1);
    }

    public function formatQuantity(mixed $quantity): string
    {
        if ($quantity === null || $quantity === '') {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ','), '0'), '.');
    }

    public function formatDifference(WmsInventoryCountItemLog $log): string
    {
        if ($log->new_quantity === null || $log->old_quantity === null) {
            return '-';
        }

        $difference = (float) $log->new_quantity - (float) $log->old_quantity;
        $formatted = $this->formatQuantity($difference);

        return $difference > 0 ? "+{$formatted}" : $formatted;
    }

    private function baseLogQuery(): Builder
    {
        return WmsInventoryCountItemLog::query()
            ->select('wms_inventory_count_item_logs.*')
            ->join('wms_inventory_count_items', 'wms_inventory_count_items.id', '=', 'wms_inventory_count_item_logs.inventory_count_item_id')
            ->where('wms_inventory_count_items.inventory_count_id', $this->record->id)
            ->with(['countItem', 'picker', 'user']);
    }

    private function applyFilters(Builder $query): void
    {
        if ($this->actorFilter !== '') {
            $this->applyActorFilter($query, $this->actorFilter);
        }

        if ($this->roundFilter !== '') {
            $query->where('wms_inventory_count_item_logs.count_round', (int) $this->roundFilter);
        }

        if ($this->deviceFilter !== '') {
            $query->where('wms_inventory_count_item_logs.device_id', $this->deviceFilter);
        }

        if ($this->itemCodeFilter !== '') {
            $keyword = $this->normalizeKeyword($this->itemCodeFilter);
            $query->where('wms_inventory_count_items.item_code', 'like', "%{$keyword}%");
        }

        if ($this->itemNameFilter !== '') {
            $keyword = $this->normalizeKeyword($this->itemNameFilter);
            $query->where('wms_inventory_count_items.item_name', 'like', "%{$keyword}%");
        }
    }

    private function applyActorFilter(Builder $query, string $actorKey): void
    {
        [$type, $value] = array_pad(explode(':', $actorKey, 2), 2, '');

        if ($type === 'WEB') {
            $query->where('wms_inventory_count_item_logs.device_id', 'WEB');
            $value === ''
                ? $query->whereNull('wms_inventory_count_item_logs.user_id')
                : $query->where('wms_inventory_count_item_logs.user_id', (int) $value);

            return;
        }

        if ($type === 'PICKER') {
            $query->where(function (Builder $query) {
                $query->whereNull('wms_inventory_count_item_logs.device_id')
                    ->orWhere('wms_inventory_count_item_logs.device_id', '!=', 'WEB');
            });
            $query->where('wms_inventory_count_item_logs.user_id', (int) $value);

            return;
        }

        if ($type === 'DEVICE') {
            $query->where('wms_inventory_count_item_logs.device_id', $value)
                ->whereNull('wms_inventory_count_item_logs.user_id');
        }
    }

    private function actorKey(WmsInventoryCountItemLog $log): string
    {
        if ($log->device_id === 'WEB') {
            return 'WEB:'.($log->user_id ?? '');
        }

        if ($log->user_id !== null) {
            return 'PICKER:'.$log->user_id;
        }

        return 'DEVICE:'.($log->device_id ?? '');
    }

    private function normalizeKeyword(string $keyword): string
    {
        return mb_convert_kana(trim($keyword), 'as');
    }
}
