<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use App\Enums\QuantityType;
use App\Filament\Support\AdminPage;
use App\Models\QuantityUpdateQueue;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WaveGroup;
use App\Models\WmsAdminOperationLog;
use App\Models\WmsPicker;
use App\Models\WmsPickingAssignmentStrategy;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use App\Services\Shortage\PickingShortageDetector;
use App\Services\Picking\AssignPickersToTasksService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class WmsPickingWait extends AdminPage
{
    protected static string $permissionResource = 'wms-picking-waiting';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'wms-picking-wait';

    protected static ?string $title = '';

    protected string $view = 'filament.pages.wms-picking-wait';

    public string $waveGeneratedDate = '';

    public string $warehouseId = '';

    public string $waveGroupId = '';

    public string $customerSearch = '';

    public string $destinationSearch = '';

    public string $shortage = '';

    public string $itemCode = '';

    public string $locationSearch = '';

    public string $serialNo = '';

    public string $deliveryCourseSearch = '';

    public string $itemSearch = '';

    public string $listTab = 'all';

    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    public bool $secondaryV2Ordering = false;

    public int $perPage = 100;

    public int $page = 1;

    public array $plannedQtyInputs = [];

    public array $pickedQtyInputs = [];

    public array $orderedQtyInputs = [];

    public array $orderedQtyTypeInputs = [];

    public bool $adjustModalOpen = false;

    public bool $quantityQueueWaiting = false;

    public array $pendingQuantityQueueIds = [];

    public string $quantityQueueMessage = '';

    public ?int $adjustItemResultId = null;

    public string $adjustPlannedQtyValue = '';

    public function mount(): void
    {
        $this->waveGeneratedDate = ClientSetting::systemDateYMD();
        $this->warehouseId = (string) (auth()->user()?->getSelectedWarehouseId() ?? '');
        $this->waveGroupId = (string) ($this->latestWaveGroupForFilters()?->id ?? '');
    }

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_PICKING_WAITINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return 'ピッキング調整';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_PICKING_WAITINGS->sort();
    }

    public function getTitle(): string
    {
        return '';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function search(): void
    {
        if ($this->waveGroupId === '') {
            $this->waveGroupId = (string) ($this->latestWaveGroupForFilters()?->id ?? '');
        }

        if ($this->waveGroupId === '') {
            Notification::make()
                ->title('WaveGroupを指定してください')
                ->warning()
                ->send();
        }

        $this->page = 1;
        $this->plannedQtyInputs = [];
        $this->pickedQtyInputs = [];
        $this->orderedQtyInputs = [];
        $this->orderedQtyTypeInputs = [];
    }

    public function updatedWaveGeneratedDate(): void
    {
        $this->waveGroupId = (string) ($this->latestWaveGroupForFilters()?->id ?? '');
        $this->search();
    }

    public function updatedWarehouseId(): void
    {
        $this->waveGroupId = (string) ($this->latestWaveGroupForFilters()?->id ?? '');
        $this->search();
    }

    public function updatedWaveGroupId(): void
    {
        $this->search();
    }

    public function setListTab(string $tab): void
    {
        if (! in_array($tab, ['all', 'shortage'], true)) {
            return;
        }

        $this->listTab = $tab;
        $this->search();
    }

    public function clearFilters(): void
    {
        $this->customerSearch = '';
        $this->destinationSearch = '';
        $this->shortage = '';
        $this->itemCode = '';
        $this->locationSearch = '';
        $this->serialNo = '';
        $this->deliveryCourseSearch = '';
        $this->itemSearch = '';
        $this->search();
    }

    public function loadMore(): void
    {
        $this->page++;
    }

    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, $this->sortableColumns())) {
            return;
        }

        $this->secondaryV2Ordering = false;

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->page = 1;
        $this->plannedQtyInputs = [];
        $this->pickedQtyInputs = [];
        $this->orderedQtyInputs = [];
        $this->orderedQtyTypeInputs = [];
    }

    public function sortBySecondaryV2(): void
    {
        $this->secondaryV2Ordering = true;
        $this->sortColumn = '';
        $this->sortDirection = 'asc';
        $this->page = 1;
        $this->plannedQtyInputs = [];
        $this->pickedQtyInputs = [];
        $this->orderedQtyInputs = [];
        $this->orderedQtyTypeInputs = [];
    }

    public function warehouseOptions(): Collection
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function waveGroupOptions(): Collection
    {
        $query = WaveGroup::query()
            ->whereDate('created_at', $this->waveGeneratedDate ?: ClientSetting::systemDateYMD())
            ->whereNull('cancelled_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($this->warehouseId !== '') {
            $query->where('warehouse_id', (int) $this->warehouseId);
        }

        return $query->limit(50)->get(['id', 'group_no', 'created_at']);
    }

    public function latestWaveGroupForFilters(): ?WaveGroup
    {
        $query = WaveGroup::query()
            ->whereDate('created_at', $this->waveGeneratedDate ?: ClientSetting::systemDateYMD())
            ->whereNull('cancelled_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($this->warehouseId !== '') {
            $query->where('warehouse_id', (int) $this->warehouseId);
        }

        return $query->first();
    }

    public function latestWaveGroup(): ?WaveGroup
    {
        if ($this->waveGroupId === '') {
            return null;
        }

        $query = WaveGroup::query()
            ->whereKey((int) $this->waveGroupId)
            ->whereNull('cancelled_at');

        if ($this->warehouseId !== '') {
            $query->where('warehouse_id', (int) $this->warehouseId);
        }

        return $query->first();
    }

    public function rows(): Collection
    {
        $waveGroup = $this->latestWaveGroup();

        if (! $waveGroup) {
            return collect();
        }

        $query = $this->filteredRowsQuery($waveGroup->id, $this->listTab);
        $this->applyRowsOrdering($query);

        $rows = $query
            ->limit($this->page * $this->perPage)
            ->get();

        $this->syncInputStateFromRows($rows);

        return $rows;
    }

    public function sortIndicator(string $column): string
    {
        if ($this->sortColumn !== $column) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function adjustmentRows(): Collection
    {
        $waveGroup = $this->latestWaveGroup();

        if (! $waveGroup || ! $this->adjustItemResultId) {
            return collect();
        }

        $rows = $this->filteredRowsQuery($waveGroup->id, $this->listTab)
            ->where('pir.id', $this->adjustItemResultId)
            ->orderBy('pt.delivery_course_code')
            ->orderBy('pir.walking_order')
            ->orderBy('pir.id')
            ->get();

        $this->syncInputStateFromRows($rows);

        return $rows;
    }

    private function syncInputStateFromRows(Collection $rows): void
    {
        foreach ($rows as $row) {
            $key = (string) $row->id;
            if (! array_key_exists($key, $this->plannedQtyInputs)) {
                $this->plannedQtyInputs[$key] = (int) $row->planned_qty;
            }
            if (! array_key_exists($key, $this->pickedQtyInputs)) {
                $this->pickedQtyInputs[$key] = (int) ($row->picked_qty ?? 0);
            }
            if (! array_key_exists($key, $this->orderedQtyInputs)) {
                $this->orderedQtyInputs[$key] = (int) $row->ordered_qty;
            }
            if (! array_key_exists($key, $this->orderedQtyTypeInputs)) {
                $this->orderedQtyTypeInputs[$key] = (string) $row->ordered_qty_type;
            }
        }
    }

    public function totalCount(): int
    {
        $waveGroup = $this->latestWaveGroup();

        if (! $waveGroup) {
            return 0;
        }

        return $this->filteredRowsQuery($waveGroup->id, $this->listTab)->count();
    }

    public function countForTab(string $tab): int
    {
        $waveGroup = $this->latestWaveGroup();

        if (! $waveGroup) {
            return 0;
        }

        return $this->filteredRowsQuery($waveGroup->id, $tab)->count();
    }

    public function isPickerAssignmentCompleted(): bool
    {
        $waveGroup = $this->latestWaveGroup();

        if (! $waveGroup) {
            return false;
        }

        $waveTaskExists = WmsPickingTask::query()
            ->whereHas('wave', fn ($waveQuery) => $waveQuery->where('wave_group_id', $waveGroup->id))
            ->exists();

        if (! $waveTaskExists) {
            return false;
        }

        $query = WmsPickingTask::query()
            ->whereHas('wave', fn ($waveQuery) => $waveQuery->where('wave_group_id', $waveGroup->id))
            ->whereIn('status', [
                WmsPickingTask::STATUS_PENDING,
                WmsPickingTask::STATUS_PICKING_READY,
            ]);

        return ! (clone $query)
            ->whereNull('picker_id')
            ->exists();
    }

    public function savePlannedQty(int $itemResultId): void
    {
        $record = WmsPickingItemResult::with(['item', 'pickingTask'])->find($itemResultId);

        if (! $record || ! $record->pickingTask) {
            Notification::make()->title('明細が見つかりません')->danger()->send();

            return;
        }

        if (! in_array($record->pickingTask->status, [
            WmsPickingTask::STATUS_PENDING,
            WmsPickingTask::STATUS_PICKING_READY,
            WmsPickingTask::STATUS_PICKING,
            WmsPickingTask::STATUS_COMPLETED,
        ], true)) {
            Notification::make()
                ->title('変更できません')
                ->body('未着手、準備完了、ピッキング中、完了の明細のみ調整できます。')
                ->danger()
                ->send();

            return;
        }

        $newPlannedQty = (int) ($this->plannedQtyInputs[(string) $itemResultId] ?? $record->planned_qty);
        if ($newPlannedQty < 0) {
            Notification::make()->title('引当数は0以上で入力してください')->danger()->send();

            return;
        }

        $capacityCase = max(1, (int) ($record->item?->capacity_case ?? 1));
        $plannedPieces = $record->planned_qty_type === QuantityType::CASE->value
            ? $newPlannedQty * $capacityCase
            : $newPlannedQty;
        $orderedPieces = $record->ordered_qty_type === QuantityType::CASE->value
            ? (int) $record->ordered_qty * $capacityCase
            : (int) $record->ordered_qty;

        if ($plannedPieces > $orderedPieces) {
            Notification::make()->title('引当数が受注数を超えています')->danger()->send();

            return;
        }

        $oldPlannedQty = (int) $record->planned_qty;
        if ($oldPlannedQty === $newPlannedQty) {
            Notification::make()->title('変更なし')->warning()->send();

            return;
        }

        DB::connection('sakemaru')->transaction(function () use ($record, $newPlannedQty, $plannedPieces, $orderedPieces, $oldPlannedQty): void {
            $record->planned_qty = $newPlannedQty;

            if ((int) $record->picked_qty > $newPlannedQty) {
                $record->picked_qty = $newPlannedQty;
            }

            $record->shortage_qty = max(0, $orderedPieces - $plannedPieces);
            $record->save();

            WmsAdminOperationLog::log(
                EWMSLogOperationType::ADJUST_PICKING_QTY,
                [
                    'target_type' => EWMSLogTargetType::PICKING_ITEM,
                    'target_id' => $record->id,
                    'picking_task_id' => $record->picking_task_id,
                    'picking_item_result_id' => $record->id,
                    'wave_id' => $record->pickingTask?->wave_id,
                    'earning_id' => $record->earning_id,
                    'qty_before' => $oldPlannedQty,
                    'qty_after' => $newPlannedQty,
                    'qty_type' => $record->planned_qty_type,
                    'operation_note' => 'ピッキング調整カスタムページによる変更',
                ]
            );
        });

        Notification::make()
            ->title('引当数を更新しました')
            ->success()
            ->send();
    }

    public function openPlannedQtyModal(?int $itemResultId = null): void
    {
        $this->adjustItemResultId = $itemResultId;
        $this->adjustModalOpen = true;
        $this->adjustmentRows();
    }

    public function closePlannedQtyModal(): void
    {
        if ($this->quantityQueueWaiting) {
            return;
        }

        $this->adjustModalOpen = false;
        $this->adjustItemResultId = null;
        $this->adjustPlannedQtyValue = '';
        $this->pickedQtyInputs = [];
        $this->pendingQuantityQueueIds = [];
        $this->quantityQueueMessage = '';
    }

    public function confirmPlannedQty(): void
    {
        $this->saveAdjustmentChanges();
    }

    public function saveAdjustmentChanges(): void
    {
        $rows = $this->adjustmentRows();

        if ($rows->isEmpty()) {
            Notification::make()->title('対象明細がありません')->warning()->send();

            return;
        }

        $updatedPickingCount = 0;
        $updatedPickedCount = 0;
        $queuedOrderCount = 0;
        $pendingQueueIds = [];
        $errors = [];

        try {
            DB::connection('sakemaru')->transaction(function () use ($rows, &$updatedPickingCount, &$updatedPickedCount, &$queuedOrderCount, &$pendingQueueIds, &$errors): void {
                foreach ($rows as $row) {
                    $record = WmsPickingItemResult::with(['item', 'pickingTask', 'trade'])
                        ->lockForUpdate()
                        ->find((int) $row->id);

                    if (! $record || ! $record->pickingTask) {
                        $errors[] = "ID {$row->id}: 明細が見つかりません";

                        continue;
                    }

                    if (! in_array($record->pickingTask->status, [
                        WmsPickingTask::STATUS_PENDING,
                        WmsPickingTask::STATUS_PICKING_READY,
                        WmsPickingTask::STATUS_PICKING,
                        WmsPickingTask::STATUS_COMPLETED,
                    ], true)) {
                        $errors[] = "ID {$record->id}: タスクのステータスが変更されています";

                        continue;
                    }

                    $key = (string) $record->id;
                    $newOrderedQty = $this->positiveIntegerInput($this->orderedQtyInputs[$key] ?? $record->ordered_qty, "ID {$record->id}: 受注数量", $errors);
                    $newPlannedQty = $this->positiveIntegerInput($this->plannedQtyInputs[$key] ?? $record->planned_qty, "ID {$record->id}: 引当数", $errors);
                    $newPickedQty = $this->positiveIntegerInput($this->pickedQtyInputs[$key] ?? $record->picked_qty ?? 0, "ID {$record->id}: ピック数", $errors);
                    $newOrderedType = (string) ($this->orderedQtyTypeInputs[$key] ?? $record->ordered_qty_type);

                    if ($newOrderedQty === null || $newPlannedQty === null || $newPickedQty === null) {
                        continue;
                    }

                    if (! in_array($newOrderedType, [QuantityType::CASE->value, QuantityType::PIECE->value], true)) {
                        $errors[] = "ID {$record->id}: 受注単位が不正です";

                        continue;
                    }

                    $newPlannedType = $newOrderedType;
                    $plannedPieces = $this->quantityAsPieces($newPlannedQty, $newPlannedType, $record);
                    $orderedPieces = $this->quantityAsPieces($newOrderedQty, $newOrderedType, $record);
                    $pickedPieces = $this->quantityAsPieces($newPickedQty, $newPlannedType, $record);

                    if ($plannedPieces > $orderedPieces) {
                        $errors[] = "ID {$record->id}: 引当数が受注数を超えています";

                        continue;
                    }

                    if ($newPickedQty > $newPlannedQty) {
                        $errors[] = "ID {$record->id}: ピック数が引当数を超えています";

                        continue;
                    }

                    if ((int) $record->ordered_qty !== $newOrderedQty || $newOrderedType !== $record->ordered_qty_type) {
                        $queue = $this->createOrUpdateQuantityQueue($record, $newOrderedQty, $newOrderedType);
                        if ($queue->status !== QuantityUpdateQueue::STATUS_FINISHED) {
                            $pendingQueueIds[] = (int) $queue->id;
                        }
                        $record->ordered_qty = $newOrderedQty;
                        $record->ordered_qty_type = $newOrderedType;
                        $queuedOrderCount++;
                    }

                    if ((int) $record->planned_qty !== $newPlannedQty || $record->planned_qty_type !== $newPlannedType) {
                        $record->planned_qty = $newPlannedQty;
                        $record->planned_qty_type = $newPlannedType;
                        $updatedPickingCount++;
                    }

                    if ((int) ($record->picked_qty ?? 0) !== $newPickedQty || $record->picked_qty_type !== $newPlannedType) {
                        $record->picked_qty = $newPickedQty;
                        $record->picked_qty_type = $newPlannedType;
                        $record->picked_at = $newPickedQty > 0 ? ($record->picked_at ?? now()) : null;
                        $updatedPickedCount++;
                    }

                    $record->shortage_qty = max(0, $orderedPieces - $pickedPieces);
                    $record->save();
                    $this->syncShortageFromPickResult($record);
                }
            });
        } catch (\Throwable $e) {
            Notification::make()
                ->title('保存できませんでした')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! empty($errors)) {
            Notification::make()
                ->title('一部保存できませんでした')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->danger()
                ->send();

            return;
        }

        if (! empty($pendingQueueIds)) {
            $this->pendingQuantityQueueIds = array_values(array_unique($pendingQueueIds));
            $this->quantityQueueWaiting = true;
            $this->quantityQueueMessage = "数量変更queue {$queuedOrderCount}件を処理中です。完了までお待ちください。";

            return;
        }

        $this->adjustModalOpen = false;
        $this->adjustItemResultId = null;
        $this->adjustPlannedQtyValue = '';
        $this->pickedQtyInputs = [];
        $this->search();

        Notification::make()
            ->title('調節を保存しました')
            ->body("引当変更 {$updatedPickingCount}件 / ピック変更 {$updatedPickedCount}件 / 数量変更queue {$queuedOrderCount}件")
            ->success()
            ->send();
    }

    public function pollQuantityUpdateQueue(): void
    {
        if (! $this->quantityQueueWaiting || empty($this->pendingQuantityQueueIds)) {
            return;
        }

        $queues = QuantityUpdateQueue::query()
            ->whereIn('id', $this->pendingQuantityQueueIds)
            ->get(['id', 'status', 'is_success', 'error_message']);

        if ($queues->count() < count($this->pendingQuantityQueueIds)) {
            $this->quantityQueueMessage = 'queueの処理状態を確認中です。';

            return;
        }

        $unfinished = $queues->reject(fn (QuantityUpdateQueue $queue): bool => $queue->status === QuantityUpdateQueue::STATUS_FINISHED);
        if ($unfinished->isNotEmpty()) {
            $statusSummary = $unfinished
                ->countBy('status')
                ->map(fn (int $count, string $status): string => "{$status} {$count}件")
                ->values()
                ->implode(' / ');
            $this->quantityQueueMessage = "queue処理中です。{$statusSummary}";

            return;
        }

        $this->quantityQueueWaiting = false;
        $this->pendingQuantityQueueIds = [];

        $failed = $queues->filter(fn (QuantityUpdateQueue $queue): bool => $queue->is_success === false);
        if ($failed->isNotEmpty()) {
            $this->quantityQueueMessage = '';
            Notification::make()
                ->title('queue処理が失敗しました')
                ->body($failed->pluck('error_message')->filter()->take(3)->implode("\n") ?: 'quantity_update_queue の処理結果を確認してください')
                ->danger()
                ->send();

            return;
        }

        $this->quantityQueueMessage = '';
        $this->adjustModalOpen = false;
        $this->adjustItemResultId = null;
        $this->adjustPlannedQtyValue = '';
        $this->pickedQtyInputs = [];
        $this->search();

        Notification::make()
            ->title('queue処理が完了しました')
            ->success()
            ->send();
    }

    private function createOrUpdateQuantityQueue(WmsPickingItemResult $record, int $orderedQty, string $orderedQtyType): QuantityUpdateQueue
    {
        $clientId = $record->trade?->client_id;
        if (! $clientId || ! $record->trade_id || ! $record->trade_item_id) {
            throw new \RuntimeException("ID {$record->id}: quantity_update_queue作成に必要な伝票情報が不足しています");
        }

        $tradeCategory = match ($record->source_type) {
            WmsPickingItemResult::SOURCE_TYPE_EARNING => QuantityUpdateQueue::TRADE_CATEGORY_EARNING,
            WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER => QuantityUpdateQueue::TRADE_CATEGORY_STOCK_TRANSFER,
            default => throw new \RuntimeException("ID {$record->id}: quantity_update_queue未対応の伝票種別です"),
        };

        $requestId = "picking-order-quantity-adjustment-{$tradeCategory}-{$record->id}-{$orderedQty}-{$orderedQtyType}";
        $payload = [
            'client_id' => $clientId,
            'trade_category' => $tradeCategory,
            'trade_id' => $record->trade_id,
            'trade_item_id' => $record->trade_item_id,
            'update_qty' => $orderedQty,
            'quantity_type' => $orderedQtyType,
            'shipment_date' => $record->pickingTask?->shipment_date,
            'status' => QuantityUpdateQueue::STATUS_BEFORE,
            'is_success' => null,
            'error_message' => null,
        ];

        $existing = QuantityUpdateQueue::where('request_id', $requestId)->lockForUpdate()->first();

        if ($existing) {
            if ($existing->status === QuantityUpdateQueue::STATUS_BEFORE) {
                $existing->update($payload);
            }

            return $existing;
        }

        return QuantityUpdateQueue::create($payload + ['request_id' => $requestId]);
    }

    private function positiveIntegerInput(mixed $value, string $label, array &$errors): ?int
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || ! ctype_digit($normalized)) {
            $errors[] = "{$label}は0以上の整数で入力してください";

            return null;
        }

        return (int) $normalized;
    }

    private function quantityAsPieces(int $qty, ?string $qtyType, WmsPickingItemResult $record): int
    {
        $capacityCase = max(1, (int) ($record->item?->capacity_case ?? 1));
        $capacityCarton = max(1, (int) ($record->item?->capacity_carton ?? 1));

        return match ($qtyType) {
            QuantityType::CASE->value => $qty * $capacityCase,
            QuantityType::CARTON->value => $qty * $capacityCarton,
            default => $qty,
        };
    }

    private function syncShortageFromPickResult(WmsPickingItemResult $record): void
    {
        $record->loadMissing(['item', 'pickingTask']);
        $task = $record->pickingTask;

        if (! $task) {
            return;
        }

        if ((int) $record->shortage_qty > 0) {
            app(PickingShortageDetector::class)->detectAndRecord($record);

            return;
        }

        $shortage = WmsShortage::query()
            ->where(function ($query) use ($record, $task): void {
                $query->where('source_pick_result_id', $record->id)
                    ->orWhere(function ($subQuery) use ($record, $task): void {
                        $subQuery
                            ->where('wave_id', $task->wave_id)
                            ->where('warehouse_id', $task->warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->where('trade_item_id', $record->trade_item_id);
                    });
            })
            ->lockForUpdate()
            ->first();

        if (! $shortage) {
            return;
        }

        if (! $shortage->is_confirmed && ! $shortage->allocations()->exists()) {
            $shortage->delete();

            return;
        }

        $shortage->order_qty = (int) $record->ordered_qty;
        $shortage->planned_qty = (int) $record->planned_qty;
        $shortage->picked_qty = (int) $record->picked_qty;
        $shortage->source_pick_result_id = $record->id;
        $shortage->calculateAndStoreShortageQty();
        $shortage->save();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function forcePickingAction(): Action
    {
        return Action::make('forcePicking')
            ->label('強制ピッキング')
            ->icon('heroicon-o-check-circle')
            ->color('danger')
            ->disabled(fn (): bool => ! $this->isPickerAssignmentCompleted())
            ->requiresConfirmation()
            ->modalHeading('強制ピッキング')
            ->modalDescription('選択中のWaveGroupに含まれる未完了明細のピック数を、引当数と同じ数量・単位で一括入力します。引当欠品分は引当数に含まれないためピックされません。')
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('強制ピッキングを実施')->color('danger'))
            ->modalCancelActionLabel('実施せず閉じる')
            ->action(function (): void {
                $waveGroup = $this->latestWaveGroup();

                if (! $waveGroup) {
                    Notification::make()
                        ->title('WaveGroupを指定してください')
                        ->warning()
                        ->send();

                    return;
                }

                if (! $this->isPickerAssignmentCompleted()) {
                    Notification::make()
                        ->title('先にピッカー割り当てを完了してください')
                        ->warning()
                        ->send();

                    return;
                }

                $updatedCount = 0;

                DB::connection('sakemaru')->transaction(function () use ($waveGroup, &$updatedCount): void {
                    $records = WmsPickingItemResult::query()
                        ->whereHas('pickingTask', function ($query) use ($waveGroup): void {
                            $query
                                ->whereIn('status', [
                                    WmsPickingTask::STATUS_PENDING,
                                    WmsPickingTask::STATUS_PICKING_READY,
                                    WmsPickingTask::STATUS_PICKING,
                                    WmsPickingTask::STATUS_COMPLETED,
                                ])
                                ->whereHas('wave', fn ($waveQuery) => $waveQuery->where('wave_group_id', $waveGroup->id));
                        })
                        ->lockForUpdate()
                        ->get();

                    foreach ($records as $record) {
                        $record->picked_qty = (int) $record->planned_qty;
                        $record->picked_qty_type = $record->planned_qty_type;
                        $record->picked_at = $record->picked_at ?? now();
                        $record->shortage_qty = max(
                            0,
                            $this->quantityAsPieces((int) $record->ordered_qty, $record->ordered_qty_type, $record)
                                - $this->quantityAsPieces((int) $record->picked_qty, $record->picked_qty_type, $record)
                        );
                        $record->save();

                        $updatedCount++;
                    }

                    if ($updatedCount > 0) {
                        WmsAdminOperationLog::log(
                            EWMSLogOperationType::ADJUST_PICKING_QTY,
                            [
                                'target_type' => EWMSLogTargetType::WAVE,
                                'target_id' => $waveGroup->id,
                                'wave_group_id' => $waveGroup->id,
                                'qty_after' => $updatedCount,
                                'operation_note' => 'ピッキング調整カスタムページによる強制ピッキング',
                            ]
                        );
                    }
                });

                $this->plannedQtyInputs = [];
                $this->pickedQtyInputs = [];
                $this->orderedQtyInputs = [];
                $this->orderedQtyTypeInputs = [];
                $this->search();

                Notification::make()
                    ->title('強制ピッキングを実施しました')
                    ->body(number_format($updatedCount).'件のピック数を引当数で入力しました')
                    ->success()
                    ->send();
            });
    }

    public function completePickingAction(): Action
    {
        return Action::make('completePicking')
            ->label('ピッキング完了')
            ->icon('heroicon-o-flag')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('ピッキング完了')
            ->modalDescription('選択中のWaveGroupに含まれる明細をピッキング完了状態にし、ピック数に基づいて欠品データを生成・更新します。')
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('ピッキング完了を実施')->color('danger'))
            ->modalCancelActionLabel('完了せず閉じる')
            ->action(function (): void {
                $waveGroup = $this->latestWaveGroup();

                if (! $waveGroup) {
                    Notification::make()
                        ->title('WaveGroupを指定してください')
                        ->warning()
                        ->send();

                    return;
                }

                $completedItemCount = 0;
                $completedTaskCount = 0;

                DB::connection('sakemaru')->transaction(function () use ($waveGroup, &$completedItemCount, &$completedTaskCount): void {
                    $records = WmsPickingItemResult::query()
                        ->whereHas('pickingTask.wave', fn ($waveQuery) => $waveQuery->where('wave_group_id', $waveGroup->id))
                        ->whereHas('pickingTask', fn ($taskQuery) => $taskQuery->whereIn('status', [
                            WmsPickingTask::STATUS_PENDING,
                            WmsPickingTask::STATUS_PICKING_READY,
                            WmsPickingTask::STATUS_PICKING,
                            WmsPickingTask::STATUS_COMPLETED,
                        ]))
                        ->with(['item', 'pickingTask'])
                        ->lockForUpdate()
                        ->get();

                    foreach ($records as $record) {
                        $pickedQty = (int) ($record->picked_qty ?? 0);
                        $pickedType = $record->picked_qty_type ?: $record->planned_qty_type;

                        $record->picked_qty = $pickedQty;
                        $record->picked_qty_type = $pickedType;
                        $record->shortage_qty = max(
                            0,
                            $this->quantityAsPieces((int) $record->ordered_qty, $record->ordered_qty_type, $record)
                                - $this->quantityAsPieces($pickedQty, $pickedType, $record)
                        );
                        $record->status = WmsPickingItemResult::STATUS_COMPLETED;
                        $record->is_ready_to_shipment = true;
                        $record->picked_at = $pickedQty > 0 ? ($record->picked_at ?? now()) : $record->picked_at;
                        $record->shipment_ready_at = $record->shipment_ready_at ?? now();
                        $record->save();

                        $this->syncShortageFromPickResult($record);
                        $completedItemCount++;
                    }

                    $tasks = WmsPickingTask::query()
                        ->whereHas('wave', fn ($waveQuery) => $waveQuery->where('wave_group_id', $waveGroup->id))
                        ->whereIn('status', [
                            WmsPickingTask::STATUS_PENDING,
                            WmsPickingTask::STATUS_PICKING_READY,
                            WmsPickingTask::STATUS_PICKING,
                            WmsPickingTask::STATUS_COMPLETED,
                        ])
                        ->lockForUpdate()
                        ->get();

                    foreach ($tasks as $task) {
                        $wasCompleted = $task->status === WmsPickingTask::STATUS_COMPLETED;
                        $task->status = WmsPickingTask::STATUS_COMPLETED;
                        $task->completed_at = $task->completed_at ?? now();
                        $task->save();

                        if (! $wasCompleted) {
                            $completedTaskCount++;
                        }
                    }

                    if ($completedItemCount > 0) {
                        WmsAdminOperationLog::log(
                            EWMSLogOperationType::ADJUST_PICKING_QTY,
                            [
                                'target_type' => EWMSLogTargetType::WAVE,
                                'target_id' => $waveGroup->id,
                                'wave_group_id' => $waveGroup->id,
                                'qty_after' => $completedItemCount,
                                'operation_note' => "ピッキング調整カスタムページによるピッキング完了 / 完了タスク {$completedTaskCount}件",
                            ]
                        );
                    }
                });

                $this->search();

                Notification::make()
                    ->title('ピッキング完了処理を実施しました')
                    ->body(number_format($completedItemCount).'件の明細を完了し、欠品データを生成・更新しました')
                    ->success()
                    ->send();
            });
    }

    public function assignPickersAction(): Action
    {
        $user = auth()->user();
        $user?->loadMissing('warehouse');
        $defaultWarehouseId = $user?->getSelectedWarehouseId() ?? $user?->warehouse?->id;

        return Action::make('assignPickers')
            ->label('ピッカー割り当て')
            ->icon('heroicon-o-user-group')
            ->color('primary')
            ->modalHeading('ピッカー一括割り当て')
            ->modalDescription('選択したピッカーに未割当タスクを自動的に割り当てます')
            ->modalWidth('4xl')
            ->extraModalWindowAttributes(['class' => 'picker-assign-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('割当')->color('danger'))
            ->modalCancelActionLabel('割当せず閉じる')
            ->schema([
                Grid::make(2)->schema([
                    ViewField::make('warehouse_id')
                        ->label('対象倉庫')
                        ->view('filament.forms.components.warehouse-select')
                        ->viewData([
                            'warehouses' => Warehouse::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->map(fn ($warehouse) => [
                                    'id' => $warehouse->id,
                                    'code' => $warehouse->code,
                                    'name' => $warehouse->name,
                                    'label' => "[{$warehouse->code}] {$warehouse->name}",
                                ])
                                ->values()
                                ->toArray(),
                        ])
                        ->default($defaultWarehouseId)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $set('picker_ids', []);

                            $defaultStrategy = $state
                                ? WmsPickingAssignmentStrategy::where('warehouse_id', $state)
                                    ->where('is_default', true)
                                    ->where('is_active', true)
                                    ->value('id')
                                : null;

                            $set('strategy_id', $defaultStrategy);
                        }),

                    ViewField::make('strategy_id')
                        ->label('割当戦略')
                        ->view('filament.forms.components.searchable-select')
                        ->viewData(function (Get $get): array {
                            $warehouseId = $get('warehouse_id');
                            if (! $warehouseId) {
                                return ['items' => []];
                            }

                            $strategies = WmsPickingAssignmentStrategy::where('warehouse_id', $warehouseId)
                                ->where('is_active', true)
                                ->orderBy('is_default', 'desc')
                                ->orderBy('name')
                                ->get();

                            return [
                                'items' => $strategies->map(fn ($strategy) => [
                                    'id' => $strategy->id,
                                    'label' => $strategy->name.($strategy->is_default ? ' (デフォルト)' : ''),
                                ])->values()->toArray(),
                                'placeholder' => '戦略を選択...',
                            ];
                        })
                        ->default(function () use ($defaultWarehouseId) {
                            if (! $defaultWarehouseId) {
                                return null;
                            }

                            return WmsPickingAssignmentStrategy::where('warehouse_id', $defaultWarehouseId)
                                ->where('is_default', true)
                                ->where('is_active', true)
                                ->value('id');
                        })
                        ->required(),
                ]),

                ViewField::make('picker_ids')
                    ->label('ピッカー選択')
                    ->view('filament.forms.components.checkbox-grid')
                    ->viewData(function (Get $get): array {
                        $warehouseId = $get('warehouse_id');
                        if (! $warehouseId) {
                            return ['options' => []];
                        }

                        return [
                            'options' => WmsPicker::where('current_warehouse_id', $warehouseId)
                                ->where('is_available_for_picking', true)
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->map(fn ($picker) => [
                                    'id' => $picker->id,
                                    'label' => "[{$picker->code}] {$picker->name}",
                                ])
                                ->toArray(),
                            'searchPlaceholder' => 'ピッカー検索...',
                        ];
                    })
                    ->required()
                    ->helperText('出勤中で稼働可能なピッカーのみ表示されます')
                    ->visible(fn (Get $get) => $get('warehouse_id')),

                Placeholder::make('assign_preview')
                    ->label('割当サマリー')
                    ->content(function (Get $get): HtmlString {
                        $warehouseId = $get('warehouse_id');
                        $pickerIds = $get('picker_ids') ?? [];

                        if (! $warehouseId) {
                            return new HtmlString(
                                '<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500">'
                                .'<i class="fa fa-warehouse text-2xl mb-2"></i>'
                                .'<p class="text-sm">対象倉庫を選択してください</p>'
                                .'</div>'
                            );
                        }

                        $unassignedTasks = WmsPickingTask::where('warehouse_id', $warehouseId)
                            ->whereNull('picker_id')
                            ->where('status', WmsPickingTask::STATUS_PENDING)
                            ->withCount('pickingItemResults as item_count')
                            ->get();

                        $unassignedCount = $unassignedTasks->count();
                        $totalItemCount = $unassignedTasks->sum('item_count');

                        if ($unassignedCount === 0) {
                            return new HtmlString(
                                '<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500">'
                                .'<i class="fa fa-check-circle text-2xl mb-2"></i>'
                                .'<p class="text-sm">未割当のタスクはありません</p>'
                                .'</div>'
                            );
                        }

                        $pickerCount = is_array($pickerIds) ? count($pickerIds) : 0;
                        $perPicker = $pickerCount > 0 ? ceil($totalItemCount / $pickerCount) : 0;

                        return new HtmlString(
                            '<div class="flex justify-between items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">'
                            .'<div class="flex items-center gap-4">'
                            .'<span class="text-xs text-slate-500 dark:text-gray-400">'
                            .'未割当タスク: <span class="font-bold text-slate-700 dark:text-gray-200">'.$unassignedCount.'件</span>'
                            .'</span>'
                            .'<span class="text-xs text-slate-500 dark:text-gray-400">'
                            .'商品数: <span class="font-bold text-slate-700 dark:text-gray-200">'.number_format($totalItemCount).'件</span>'
                            .'</span>'
                            .'<span class="text-xs text-slate-500 dark:text-gray-400">'
                            .'選択ピッカー: <span class="font-bold text-blue-600 dark:text-blue-400">'.$pickerCount.'名</span>'
                            .'</span>'
                            .'</div>'
                            .'<span class="text-xs text-slate-400 dark:text-gray-500">'
                            .'約 <span class="font-bold">'.number_format($perPicker).'商品</span>/人'
                            .'</span>'
                            .'</div>'
                        );
                    })
                    ->visible(fn (Get $get) => $get('warehouse_id')),
            ])
            ->action(function (array $data): void {
                $result = app(AssignPickersToTasksService::class)->execute(
                    warehouseId: $data['warehouse_id'],
                    pickerIds: $data['picker_ids'],
                    strategyId: $data['strategy_id']
                );

                $notification = Notification::make()
                    ->title($result['success'] ? '割り当て完了' : '割り当てエラー')
                    ->body($result['message']);

                $result['success']
                    ? $notification->success()->send()
                    : $notification->danger()->send();

                $this->search();
            });
    }

    private function applyRowsOrdering(Builder $query): void
    {
        $sortableColumns = $this->sortableColumns();
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        if ($this->sortColumn !== '' && array_key_exists($this->sortColumn, $sortableColumns)) {
            foreach ($sortableColumns[$this->sortColumn] as $column) {
                $query->orderBy($column, $direction);
            }

            $query
                ->orderByRaw('COALESCE(l.code1, default_l.code1, ?)', ['ZZZ'])
                ->orderByRaw('COALESCE(l.code2, default_l.code2, ?)', ['ZZZ'])
                ->orderByRaw('COALESCE(l.code3, default_l.code3, ?)', ['ZZZ'])
                ->orderBy('pir.id');

            return;
        }

        if ($this->secondaryV2Ordering) {
            $this->applySecondaryV2Ordering($query);

            return;
        }

        $query
            ->orderBy('pt.delivery_course_code')
            ->orderBy('pir.walking_order')
            ->orderBy('pir.id');
    }

    private function applySecondaryV2Ordering(Builder $query): void
    {
        $yxLocationExpr = "COALESCE(l.code1, default_l.code1, '')";

        $query
            ->orderByRaw("
                CASE
                    WHEN {$yxLocationExpr} LIKE 'YA%' OR {$yxLocationExpr} LIKE 'YB%' OR {$yxLocationExpr} LIKE 'YC%' OR {$yxLocationExpr} LIKE 'YX%' THEN 2147483647
                    WHEN COALESCE(l.floor_id, default_l.floor_id) IS NULL THEN 2147483646
                    ELSE COALESCE(l.floor_id, default_l.floor_id, 2147483646)
                END
            ")
            ->orderBy('dc.code')
            ->orderByRaw('COALESCE(l.code1, default_l.code1, ?)', ['ZZZ'])
            ->orderByRaw('COALESCE(l.code2, default_l.code2, ?)', ['ZZZ'])
            ->orderByRaw('COALESCE(l.code3, default_l.code3, ?)', ['ZZZ'])
            ->orderBy('i.code')
            ->orderByRaw('COALESCE(e.id, st.id)')
            ->orderBy('pir.id');
    }

    private function sortableColumns(): array
    {
        return [
            'serial_id' => ['tr.serial_id'],
            'delivery_course' => ['dc.code', 'dc.name'],
            'item_code' => ['i.code'],
            'item_name' => ['i.name', 'i.code'],
        ];
    }

    private function filteredRowsQuery(int $waveGroupId, ?string $tab = null): Builder
    {
        $query = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pt.id', '=', 'pir.picking_task_id')
            ->join('wms_waves as wv', 'wv.id', '=', 'pt.wave_id')
            ->leftJoin('warehouses as wh', 'wh.id', '=', 'pt.warehouse_id')
            ->leftJoin('delivery_courses as dc', 'dc.id', '=', 'pt.delivery_course_id')
            ->leftJoin('items as i', 'i.id', '=', 'pir.item_id')
            ->leftJoin('locations as l', 'l.id', '=', 'pir.location_id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join): void {
                $join->on('idl.item_id', '=', 'pir.item_id')
                    ->whereColumn('idl.warehouse_id', 'pt.warehouse_id');
            })
            ->leftJoin('locations as default_l', 'default_l.id', '=', 'idl.location_id')
            ->leftJoin('floors as f', DB::raw('COALESCE(l.floor_id, default_l.floor_id)'), '=', 'f.id')
            ->leftJoin('trades as tr', 'tr.id', '=', 'pir.trade_id')
            ->leftJoin('earnings as e', 'e.id', '=', 'pir.earning_id')
            ->leftJoin('buyers as b', 'b.id', '=', 'e.buyer_id')
            ->leftJoin('partners as bp', 'bp.id', '=', 'b.partner_id')
            ->leftJoin('stock_transfers as st', 'st.id', '=', 'pir.stock_transfer_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 'st.to_warehouse_id')
            ->where('wv.wave_group_id', $waveGroupId)
            ->select([
                'pir.id',
                'pir.picking_task_id',
                'pir.source_type',
                'pir.earning_id',
                'pir.stock_transfer_id',
                'pir.trade_id',
                'pir.trade_item_id',
                'pir.ordered_qty',
                'pir.ordered_qty_type',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.picked_qty',
                'pir.picked_qty_type',
                'pir.shortage_qty',
                'pir.has_soft_shortage',
                'pir.walking_order',
                'pt.status as task_status',
                'pt.shipment_date',
                'pt.created_at as task_created_at',
                'wv.wave_no',
                'wh.code as warehouse_code',
                'wh.name as warehouse_name',
                'dc.code as delivery_course_code',
                'dc.name as delivery_course_name',
                'tr.serial_id',
                'bp.code as customer_code',
                'bp.name as customer_name',
                'tw.code as destination_code',
                'tw.name as destination_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.packaging',
                'i.capacity_case',
                'i.capacity_carton',
                DB::raw("
                    CASE
                        WHEN COALESCE(l.code1, default_l.code1, '') LIKE 'YA%'
                            OR COALESCE(l.code1, default_l.code1, '') LIKE 'YB%'
                            OR COALESCE(l.code1, default_l.code1, '') LIKE 'YC%'
                            OR COALESCE(l.code1, default_l.code1, '') LIKE 'YX%'
                        THEN 'YX'
                        WHEN COALESCE(l.floor_id, default_l.floor_id) IS NULL THEN 'フロア未設定'
                        ELSE COALESCE(f.name, 'フロア未設定')
                    END as secondary_v2_group_label
                "),
                DB::raw("
                    CASE
                        WHEN COALESCE(l.code1, default_l.code1, '') LIKE 'YA%'
                            OR COALESCE(l.code1, default_l.code1, '') LIKE 'YB%'
                            OR COALESCE(l.code1, default_l.code1, '') LIKE 'YC%'
                            OR COALESCE(l.code1, default_l.code1, '') LIKE 'YX%'
                        THEN 2147483647
                        WHEN COALESCE(l.floor_id, default_l.floor_id) IS NULL THEN 2147483646
                        ELSE COALESCE(l.floor_id, default_l.floor_id, 2147483646)
                    END as secondary_v2_group_sort
                "),
                DB::raw("TRIM(CONCAT_WS('', COALESCE(l.code1, default_l.code1), COALESCE(l.code2, default_l.code2), COALESCE(l.code3, default_l.code3))) as location_code"),
            ]);

        $this->applyTextFilter($query, $this->customerSearch, ['bp.code', 'bp.name']);
        $this->applyWarehouseFilter($query, $this->destinationSearch, 'tw.code', 'tw.name');
        $this->applyTextFilter($query, $this->itemCode, ['i.code']);
        $this->applyTextFilter($query, $this->locationSearch, ['l.code1', 'l.code2', 'l.code3', 'l.name', 'default_l.code1', 'default_l.code2', 'default_l.code3', 'default_l.name']);
        $this->applyTextFilter($query, $this->serialNo, ['tr.serial_id']);
        $this->applyTextFilter($query, $this->deliveryCourseSearch, ['dc.code', 'dc.name']);
        $this->applyTextFilter($query, $this->itemSearch, ['i.code', 'i.name']);

        if ($tab === 'shortage') {
            $query->where('pir.has_soft_shortage', true);
        } elseif ($this->shortage === 'with') {
            $query->where('pir.has_soft_shortage', true);
        } elseif ($this->shortage === 'without') {
            $query->where(function (Builder $q): void {
                $q->where('pir.has_soft_shortage', false)
                    ->orWhereNull('pir.has_soft_shortage');
            });
        }

        return $query;
    }

    private function applyTextFilter(Builder $query, string $value, array $columns): void
    {
        $value = trim(mb_convert_kana($value, 'as'));
        if ($value === '') {
            return;
        }

        $query->where(function (Builder $q) use ($value, $columns): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', "%{$value}%");
            }
        });
    }

    private function applyWarehouseFilter(Builder $query, string $value, string $codeColumn, string $nameColumn): void
    {
        $value = trim(mb_convert_kana($value, 'as'));
        if ($value === '') {
            return;
        }

        $query->where(function (Builder $q) use ($value, $codeColumn, $nameColumn): void {
            if (ctype_digit($value)) {
                $q->orWhereRaw("CAST({$codeColumn} AS UNSIGNED) = ?", [(int) $value]);
            } else {
                $q->orWhere($codeColumn, $value);
            }

            $q->orWhere($nameColumn, 'like', "%{$value}%");
        });
    }

    public function formatQtyType(?string $type): string
    {
        return $type ? (QuantityType::tryFrom($type)?->name() ?? $type) : '-';
    }

    public function quantityBreakdown(mixed $qty, ?string $qtyType, mixed $capacityCase, bool $preserveInputUnit = true): array
    {
        if ($qty === null || $qty === '') {
            return [
                'case' => null,
                'piece' => null,
                'total' => null,
            ];
        }

        $quantity = max(0, (int) $qty);
        $capacity = max(1, (int) ($capacityCase ?: 1));
        $type = QuantityType::tryFrom((string) $qtyType) ?? QuantityType::PIECE;

        if ($preserveInputUnit) {
            $caseQty = $type === QuantityType::CASE ? $quantity : 0;
            $pieceQty = $type === QuantityType::CASE ? 0 : $quantity;
            $totalPieces = $type === QuantityType::CASE ? $quantity * $capacity : $quantity;
        } else {
            $totalPieces = $type === QuantityType::CASE ? $quantity * $capacity : $quantity;
            $caseQty = intdiv($totalPieces, $capacity);
            $pieceQty = $totalPieces % $capacity;
        }

        return [
            'case' => $caseQty,
            'piece' => $pieceQty,
            'total' => $totalPieces,
        ];
    }

    public function formatPickingStatus(?string $status): string
    {
        return match ($status) {
            WmsPickingTask::STATUS_PENDING => '未着手',
            WmsPickingTask::STATUS_PICKING_READY => '準備完了',
            WmsPickingTask::STATUS_PICKING => 'ピッキング中',
            WmsPickingTask::STATUS_COMPLETED => '完了',
            WmsPickingTask::STATUS_SHORTAGE => '欠品',
            WmsPickingTask::STATUS_SHIPPED => '出荷済み',
            null, '' => '-',
            default => $status,
        };
    }

    public function formatDate(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('Y/m/d') : '-';
    }
}
