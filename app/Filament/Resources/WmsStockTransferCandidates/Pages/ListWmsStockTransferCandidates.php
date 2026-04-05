<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\SettlementStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListWmsStockTransferCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsStockTransferCandidateResource::class;

    public array $transferOrderItems = [];

    public function searchItemsForCreate(string $search): array
    {
        if (strlen($search) < 2) {
            return [];
        }
        $search = mb_convert_kana($search, 'as');

        return Item::query()
            ->with('piece_jan_code_information')
            ->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('piece_jan_code_information', function ($q) use ($search) {
                        $q->where('search_string', 'like', "%{$search}%");
                    });
            })
            ->orderBy('code')
            ->limit(20)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'search_code' => $item->piece_jan_code_information?->search_string ?? '',
            ])
            ->toArray();
    }

    public function getItemStockForCreate(int $warehouseId, int $itemId): ?int
    {
        return (int) DB::connection('sakemaru')
            ->table('wms_v_stock_available')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->sum('available_quantity');
    }

    public function getItemIncomingQuantityForCreate(int $warehouseId, int $itemId): int
    {
        return (int) (DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('SUM(expected_quantity - received_quantity) as total_incoming')
            ->value('total_incoming') ?? 0);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('移動発注')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('移動発注を追加')
                ->modalWidth('4xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('追加する')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('satellite_warehouse_id')
                            ->label('依頼倉庫')
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                            ->default(fn () => auth()->user()?->getSelectedWarehouseId())
                            ->searchable()
                            ->required(),

                        Select::make('hub_warehouse_id')
                            ->label('移動元倉庫')
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                            ->default(fn () => WmsContractorSetting::where('transmission_type', TransmissionType::INTERNAL)
                                ->whereNotNull('supply_warehouse_id')
                                ->orderBy('supply_warehouse_id')
                                ->value('supply_warehouse_id'))
                            ->searchable()
                            ->required(),
                    ]),

                    Grid::make(2)->schema([
                        DatePicker::make('expected_arrival_date')
                            ->label('入荷予定日')
                            ->default(now()->addDay())
                            ->required(),

                        Select::make('delivery_course_id')
                            ->label('配送コース')
                            ->options(fn () => DeliveryCourse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"]))
                            ->searchable(),
                    ]),

                    ViewField::make('items_table')
                        ->view('filament.components.transfer-order-create-items')
                        ->hiddenLabel(),
                ])
                ->action(function (array $data) {
                    $items = $this->transferOrderItems;

                    if (empty($items)) {
                        Notification::make()
                            ->title('エラー')
                            ->body('商品を追加してください')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($data['satellite_warehouse_id'] === $data['hub_warehouse_id']) {
                        Notification::make()
                            ->title('エラー')
                            ->body('依頼倉庫と移動元倉庫を同じにすることはできません')
                            ->danger()
                            ->send();

                        return;
                    }

                    // 同日・同倉庫のPENDINGジョブを再利用、なければ新規作成
                    $satelliteWarehouseId = $data['satellite_warehouse_id'];
                    $existingJob = WmsAutoOrderJobControl::where('process_name', JobProcessName::ORDER_CALC)
                        ->where('settlement_status', SettlementStatus::PENDING)
                        ->where('warehouse_id', $satelliteWarehouseId)
                        ->whereDate('started_at', today())
                        ->orderByDesc('id')
                        ->first();

                    if ($existingJob) {
                        $batchCode = $existingJob->batch_code;
                    } else {
                        $newJob = WmsAutoOrderJobControl::startJob(
                            processName: JobProcessName::ORDER_CALC,
                            createdBy: auth()->id(),
                            warehouseId: $satelliteWarehouseId,
                            batchCode: WmsAutoOrderJobControl::generateBatchCode($satelliteWarehouseId),
                        );
                        $batchCode = $newJob->batch_code;
                        $newJob->markAsSuccess(0);
                    }

                    $created = 0;
                    $errors = [];

                    foreach ($items as $itemData) {
                        $itemId = $itemData['item_id'];
                        $quantity = (int) ($itemData['quantity'] ?? 0);
                        $itemCode = $itemData['item_code'] ?? null;
                        $searchCode = $itemData['search_code'] ?? null;

                        if ($quantity < 1) {
                            $errors[] = "[{$itemCode}]: 数量が不正です";

                            continue;
                        }

                        // 販売終了品チェック
                        $item = Item::find($itemId);
                        if ($item && $item->end_of_sale_type !== 'NORMAL') {
                            $errors[] = "[{$itemCode}] {$item->name}: 販売終了品";

                            continue;
                        }

                        // 重複チェック
                        $exists = WmsStockTransferCandidate::where('satellite_warehouse_id', $data['satellite_warehouse_id'])
                            ->where('hub_warehouse_id', $data['hub_warehouse_id'])
                            ->where('item_id', $itemId)
                            ->where('status', CandidateStatus::PENDING)
                            ->exists();

                        if ($exists) {
                            $errors[] = "[{$itemCode}] {$item->name}: 既に存在";

                            continue;
                        }

                        // 在庫・入荷予定数をリアルタイム取得
                        $currentStock = $this->getItemStockForCreate($data['satellite_warehouse_id'], $itemId);
                        $hubStock = $this->getItemStockForCreate($data['hub_warehouse_id'], $itemId);
                        $incomingQty = $this->getItemIncomingQuantityForCreate($data['satellite_warehouse_id'], $itemId);

                        WmsStockTransferCandidate::create([
                            'batch_code' => $batchCode,
                            'satellite_warehouse_id' => $data['satellite_warehouse_id'],
                            'hub_warehouse_id' => $data['hub_warehouse_id'],
                            'item_id' => $itemId,
                            'item_code' => $itemCode,
                            'search_code' => $searchCode,
                            'contractor_id' => null,
                            'delivery_course_id' => $data['delivery_course_id'] ?? null,
                            'suggested_quantity' => $quantity,
                            'transfer_quantity' => $quantity,
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'hub_effective_stock' => $hubStock,
                            'expected_arrival_date' => $data['expected_arrival_date'],
                            'original_arrival_date' => $data['expected_arrival_date'],
                            'status' => CandidateStatus::PENDING,
                            'lot_status' => LotStatus::RAW,
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);

                        WmsOrderCalculationLog::create([
                            'batch_code' => $batchCode,
                            'warehouse_id' => $data['satellite_warehouse_id'],
                            'item_id' => $itemId,
                            'calculation_type' => CalculationType::INTERNAL,
                            'contractor_id' => null,
                            'source_warehouse_id' => $data['hub_warehouse_id'],
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'safety_stock_setting' => 0,
                            'lead_time_days' => 1,
                            'calculated_shortage_qty' => $quantity,
                            'calculated_order_quantity' => $quantity,
                            'calculation_details' => [
                                'manual_entry' => true,
                                'created_by' => auth()->id(),
                                'created_at' => now()->toDateTimeString(),
                                'formula' => '手動追加',
                            ],
                        ]);

                        $created++;
                    }

                    $this->transferOrderItems = [];

                    if ($created > 0 && empty($errors)) {
                        Notification::make()
                            ->title("{$created}件の移動発注を追加しました")
                            ->success()
                            ->send();
                    } elseif ($created > 0) {
                        Notification::make()
                            ->title("{$created}件追加、".count($errors).'件スキップ')
                            ->body(implode("\n", $errors))
                            ->warning()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('追加できませんでした')
                            ->body(implode("\n", $errors))
                            ->danger()
                            ->send();
                    }
                }),

            ActionGroup::make([
                Action::make('approveAll')
                    ->label('全て承認')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('移動候補を全て承認')
                    ->modalDescription(function () {
                        $count = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();

                        return "承認前（PENDING）の移動候補 {$count}件 を全て承認します。";
                    })
                    ->modalSubmitActionLabel('全て承認')
                    ->action(function () {
                        $updated = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                            ->update([
                                'status' => CandidateStatus::APPROVED,
                                'updated_at' => now(),
                            ]);

                        Notification::make()
                            ->title('移動候補を全て承認しました')
                            ->body("{$updated}件 を承認しました。")
                            ->success()
                            ->send();
                    }),

                Action::make('deleteAllPending')
                    ->label('承認前を全削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('承認前の移動候補を全削除')
                    ->modalDescription(function () {
                        $count = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();

                        return "承認前（PENDING）の移動候補 {$count}件 を全て削除します。この操作は取り消せません。";
                    })
                    ->modalSubmitActionLabel('全削除')
                    ->action(function () {
                        $deleted = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->delete();

                        Notification::make()
                            ->title('承認前の移動候補を削除しました')
                            ->body("{$deleted}件 を削除しました。")
                            ->success()
                            ->send();
                    }),
            ])
                ->label('管理者メニュー')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->button(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'satelliteWarehouse',
                    'hubWarehouse',
                    'deliveryCourse',
                    'item',
                    'contractor',
                ])
                // デフォルトでPENDINGのみ表示
                ->where('status', CandidateStatus::PENDING)
                ->orderBy('batch_code', 'desc')
                ->orderBy('satellite_warehouse_id')
                ->orderBy('item_id')
            );
    }

    /**
     * テーブルレコード取得後に計算ログをプリロード（N+1対策）
     */
    protected function paginateTableQuery(Builder $query): \Illuminate\Contracts\Pagination\Paginator
    {
        $paginator = parent::paginateTableQuery($query);

        // 計算ログを一括プリロード
        $items = $paginator->getCollection();
        if ($items->isNotEmpty()) {
            WmsStockTransferCandidate::preloadCalculationLogs($items);

            // HUB倉庫在庫は候補レコードの hub_effective_stock カラムから表示（スナップショット不要）
        }

        return $paginator;
    }

    protected ?array $presetViewWarehouseData = null;

    protected function getWarehouseDataForPresetViews(): array
    {
        if ($this->presetViewWarehouseData !== null) {
            return $this->presetViewWarehouseData;
        }

        $cacheKey = 'transfer_candidates_pending_warehouses_'.auth()->id();
        $this->presetViewWarehouseData = cache()->remember($cacheKey, 30, function () {
            $warehouseIds = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                ->distinct()
                ->pluck('satellite_warehouse_id')
                ->toArray();

            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('name')
                ->get(['id', 'name']);

            return [
                'ids' => $warehouseIds,
                'warehouses' => $warehouses,
            ];
        });

        return $this->presetViewWarehouseData;
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // デフォルトタブ：デフォルト倉庫があればその倉庫名とフィルタ、なければ「全て」
        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        // 他の倉庫タブ（デフォルト倉庫は除外）
        foreach ($warehouses as $warehouse) {
            // デフォルト倉庫は既にdefaultタブで表示しているのでスキップ
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }

            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
