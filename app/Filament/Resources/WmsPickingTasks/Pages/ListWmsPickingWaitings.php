<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingTasksTable;
use App\Filament\Resources\WmsPickingTasks\WmsPickingWaitingResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAdminOperationLog;
use App\Models\WmsPicker;
use App\Models\WmsPickingAssignmentStrategy;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Services\Picking\AssignPickersToTasksService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class ListWmsPickingWaitings extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingWaitingResource::class;

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $user->loadMissing('warehouse');
        $defaultWarehouseId = $user->warehouse?->id;

        return [
            Action::make('openVersion2')
                ->label('配送コース集約版')
                ->icon('heroicon-o-squares-2x2')
                ->color('gray')
                ->url('/admin/wms-picking-waitings-v2'),

            Action::make('bulkSoftShortageMaintenance')
                ->label('統合引当欠品処理')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->modalHeading('統合引当欠品処理')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('確定')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->schema([
                    ViewField::make('bulk_shortage_data')
                        ->hiddenLabel()
                        ->view('filament.forms.components.bulk-soft-shortage-table')
                        ->viewData(fn () => $this->getBulkShortageItems()),
                ])
                ->action(fn (array $data) => $this->saveBulkShortageChanges($data)),

            Action::make('assignPickers')
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
                                    ->map(fn ($w) => [
                                        'id' => $w->id,
                                        'code' => $w->code,
                                        'name' => $w->name,
                                        'label' => "[{$w->code}] {$w->name}",
                                    ])
                                    ->values()
                                    ->toArray(),
                            ])
                            ->default($defaultWarehouseId)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('picker_ids', []);
                                // 新倉庫のデフォルト戦略を自動選択
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
                                    'items' => $strategies->map(fn ($s) => [
                                        'id' => $s->id,
                                        'label' => $s->name.($s->is_default ? ' (デフォルト)' : ''),
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
                                    ->map(fn ($p) => [
                                        'id' => $p->id,
                                        'label' => "[{$p->code}] {$p->name}",
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
                                ->where('status', 'PENDING')
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
                ->action(function (array $data) {
                    $service = new AssignPickersToTasksService;

                    $result = $service->execute(
                        warehouseId: $data['warehouse_id'],
                        pickerIds: $data['picker_ids'],
                        strategyId: $data['strategy_id']
                    );

                    if ($result['success']) {
                        Notification::make()
                            ->title('割り当て完了')
                            ->body($result['message'])
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('割り当てエラー')
                            ->body($result['message'])
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('unassignPickers')
                ->label('割り当て解除')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->modalHeading('ピッカー割り当て解除')
                ->modalDescription('選択した倉庫の「ピッキング準備完了」タスクの割り当てを解除します。作業中のタスクは解除されません。')
                ->modalWidth('lg')
                ->extraModalWindowAttributes(['class' => 'picker-assign-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('解除')->color('danger'))
                ->modalCancelActionLabel('解除せず閉じる')
                ->schema([
                    ViewField::make('unassign_warehouse_id')
                        ->label('対象倉庫')
                        ->view('filament.forms.components.warehouse-select')
                        ->viewData([
                            'warehouses' => Warehouse::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->map(fn ($w) => [
                                    'id' => $w->id,
                                    'code' => $w->code,
                                    'name' => $w->name,
                                    'label' => "[{$w->code}] {$w->name}",
                                ])
                                ->values()
                                ->toArray(),
                        ])
                        ->default($defaultWarehouseId)
                        ->required()
                        ->live(),

                    Placeholder::make('unassign_preview')
                        ->label('解除対象')
                        ->content(function (Get $get): HtmlString {
                            $warehouseId = $get('unassign_warehouse_id');

                            if (! $warehouseId) {
                                return new HtmlString(
                                    '<div class="text-center py-4 text-slate-400 dark:text-gray-500 text-sm">倉庫を選択してください</div>'
                                );
                            }

                            $readyCount = WmsPickingTask::where('warehouse_id', $warehouseId)
                                ->whereNotNull('picker_id')
                                ->where('status', WmsPickingTask::STATUS_PICKING_READY)
                                ->count();

                            if ($readyCount === 0) {
                                return new HtmlString(
                                    '<div class="text-center py-4 text-slate-400 dark:text-gray-500 text-sm">解除対象のタスクはありません</div>'
                                );
                            }

                            return new HtmlString(
                                '<div class="p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg border border-amber-100 dark:border-amber-800">'
                                .'<span class="text-sm text-slate-600 dark:text-gray-300">'
                                .'解除対象: <span class="font-bold text-amber-600 dark:text-amber-400">'.$readyCount.'件</span>'
                                .'（ピッキング準備完了）'
                                .'</span>'
                                .'</div>'
                            );
                        })
                        ->visible(fn (Get $get) => $get('unassign_warehouse_id')),
                ])
                ->action(function (array $data) {
                    $service = new AssignPickersToTasksService;
                    $result = $service->unassign($data['unassign_warehouse_id']);

                    Notification::make()
                        ->title('割り当て解除完了')
                        ->body($result['message'])
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $base = 'ピッキング調整';
        $warehouseName = $this->getSelectedWarehouseName();

        return $warehouseName ? "{$base} ({$warehouseName})" : $base;
    }

    public function getPresetViews(): array
    {
        $userWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $defaultFilterData = $userWarehouseId
            ? ['warehouse_id' => ['value' => (string) $userWarehouseId]]
            : [];
        $systemDate = ClientSetting::systemDateYMD();

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('shipment_date', $systemDate))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('当日')
                ->default(),
            'with_shortage' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('shipment_date', $systemDate)
                    ->whereHas('pickingItemResults', fn ($q) => $q->where('has_soft_shortage', true)))
                ->defaultFilters($defaultFilterData)
                ->label('引当欠品あり')
                ->favorite(),
        ];
    }

    private function getSelectedWarehouseName(): ?string
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? Warehouse::find($warehouseId)?->name : null;
    }

    public function table(Table $table): Table
    {
        return WmsPickingTasksTable::configure($table, isWaitingView: true);
    }

    private function getBulkShortageItems(): array
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        $systemDate = ClientSetting::systemDateYMD();

        $items = WmsPickingItemResult::query()
            ->whereHas('pickingTask', function ($q) use ($warehouseId, $systemDate) {
                $q->whereIn('status', [
                    WmsPickingTask::STATUS_PENDING,
                    WmsPickingTask::STATUS_PICKING_READY,
                ])
                    ->where('shipment_date', $systemDate);

                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            })
            ->where('has_soft_shortage', true)
            ->with([
                'pickingTask.deliveryCourse',
                'item',
                'location',
                'trade',
                'earning.buyer.partner',
                'earning.buyer.current_detail.salesman',
                'stockTransfer.to_warehouse',
            ])
            ->orderBy('picking_task_id')
            ->orderBy('walking_order')
            ->get();

        $mapped = $items->map(fn ($item) => [
            'id' => $item->id,
            'picking_task_id' => $item->picking_task_id,
            'sales_man' => $item->earning?->buyer?->current_detail?->salesman?->name ?? '-',
            'partner_name' => $item->earning?->buyer?->partner?->name
                ?? $item->stockTransfer?->to_warehouse?->name
                ?? '-',
            'serial_id' => $item->trade?->serial_id ?? '-',
            'location_display' => $item->location
                ? trim(($item->location->code1 ?? '').($item->location->code2 ?? '').($item->location->code3 ?? ''))
                : '-',
            'item_code' => $item->item?->code ?? '-',
            'item_name' => $item->item?->name ?? '-',
            'capacity_case' => (int) ($item->item?->capacity_case ?? 1),
            'ordered_qty' => (int) $item->ordered_qty,
            'ordered_qty_type' => $item->ordered_qty_type,
            'ordered_qty_type_label' => QuantityType::tryFrom($item->ordered_qty_type)?->name() ?? $item->ordered_qty_type,
            'planned_qty' => (int) $item->planned_qty,
            'planned_qty_type' => $item->planned_qty_type,
            'planned_qty_type_label' => QuantityType::tryFrom($item->planned_qty_type)?->name() ?? $item->planned_qty_type,
        ])->sortBy('sales_man')->values()->toArray();

        return [
            'items' => $mapped,
            'task_count' => $items->pluck('picking_task_id')->unique()->count(),
        ];
    }

    private function saveBulkShortageChanges(array $data): void
    {
        $changesJson = $data['bulk_shortage_data'] ?? '{}';
        $changes = is_string($changesJson) ? json_decode($changesJson, true) : [];

        if (empty($changes) || ! is_array($changes)) {
            Notification::make()
                ->title('変更なし')
                ->body('変更された明細はありません')
                ->warning()
                ->send();

            return;
        }

        $updatedCount = 0;
        $errors = [];

        DB::connection('sakemaru')->transaction(function () use ($changes, &$updatedCount, &$errors) {
            foreach ($changes as $itemId => $change) {
                $record = WmsPickingItemResult::with(['item', 'pickingTask'])->find($itemId);
                if (! $record) {
                    continue;
                }

                if (! in_array($record->pickingTask?->status, [
                    WmsPickingTask::STATUS_PENDING,
                    WmsPickingTask::STATUS_PICKING_READY,
                ])) {
                    $errors[] = "ID {$itemId}: タスクのステータスが変更されています";

                    continue;
                }

                $newPlannedQty = (int) ($change['planned_qty'] ?? $record->planned_qty);

                $capacityCase = max(1, (int) ($record->item?->capacity_case ?? 1));
                $plannedPieces = $record->planned_qty_type === 'CASE'
                    ? $newPlannedQty * $capacityCase
                    : $newPlannedQty;
                $orderedPieces = $record->ordered_qty_type === 'CASE'
                    ? (int) $record->ordered_qty * $capacityCase
                    : (int) $record->ordered_qty;

                if ($plannedPieces > $orderedPieces) {
                    $errors[] = "ID {$itemId}: 引当数が受注数を超えています";

                    continue;
                }

                $oldPlannedQty = (int) $record->planned_qty;
                if ($oldPlannedQty === $newPlannedQty) {
                    continue;
                }

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
                        'operation_note' => '統合引当欠品処理による一括変更',
                    ]
                );

                $updatedCount++;
            }
        });

        if (! empty($errors)) {
            Notification::make()
                ->title('一部エラーが発生しました')
                ->body(implode("\n", $errors))
                ->danger()
                ->send();
        }

        if ($updatedCount > 0) {
            Notification::make()
                ->title('引当数を一括更新しました')
                ->body("{$updatedCount}件の引当数を変更しました")
                ->success()
                ->send();
        }
    }

}
