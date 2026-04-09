<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\SettlementStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\StatsItemWarehouseSalesSummary;
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

    public function searchItemsForModal(
        int $warehouseId,
        ?string $itemCode = null,
        ?string $janCode = null,
        ?string $itemName = null,
        ?int $contractorId = null,
        ?int $category1Id = null,
        ?int $category2Id = null,
        ?int $category3Id = null,
        ?string $lastShippedFrom = null,
        ?string $lastShippedTo = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $query = Item::query()
            ->select([
                'items.id',
                'items.code',
                'items.name',
                'items.packaging',
                'items.capacity_case',
            ])
            ->with('piece_jan_code_information')
            ->where('items.end_of_sale_type', 'NORMAL');

        if ($itemCode && strlen($itemCode) >= 1) {
            $itemCode = mb_convert_kana($itemCode, 'as');
            $query->where('items.code', 'like', "%{$itemCode}%");
        }

        if ($janCode && strlen($janCode) >= 1) {
            $janCode = mb_convert_kana($janCode, 'as');
            $query->whereHas('piece_jan_code_information', function ($sq) use ($janCode) {
                $sq->where('search_string', 'like', "%{$janCode}%");
            });
        }

        if ($itemName && strlen($itemName) >= 2) {
            $itemName = mb_convert_kana($itemName, 'as');
            $query->where('items.name', 'like', "%{$itemName}%");
        }

        if ($contractorId) {
            $query->whereHas('item_contractors', function ($q) use ($contractorId, $warehouseId) {
                $q->where('contractor_id', $contractorId)
                    ->where('warehouse_id', $warehouseId);
            });
        }

        if ($category1Id) {
            $query->where('items.item_category1_id', $category1Id);
        }
        if ($category2Id) {
            $query->where('items.item_category2_id', $category2Id);
        }
        if ($category3Id) {
            $query->where('items.item_category3_id', $category3Id);
        }

        if ($lastShippedFrom || $lastShippedTo) {
            $query->whereExists(function ($q) use ($warehouseId, $lastShippedFrom, $lastShippedTo) {
                $q->select(DB::raw(1))
                    ->from('stats_item_warehouse_sales_summaries')
                    ->whereColumn('stats_item_warehouse_sales_summaries.item_id', 'items.id')
                    ->where('stats_item_warehouse_sales_summaries.warehouse_id', $warehouseId);
                if ($lastShippedFrom) {
                    $q->where('stats_item_warehouse_sales_summaries.last_shipped_at', '>=', $lastShippedFrom);
                }
                if ($lastShippedTo) {
                    $q->where('stats_item_warehouse_sales_summaries.last_shipped_at', '<=', $lastShippedTo);
                }
            });
        }

        $query->orderBy('items.code');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $itemIds = collect($paginator->items())->pluck('id')->toArray();
        $summaries = StatsItemWarehouseSalesSummary::where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');

        $itemContractors = ItemContractor::where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->with('contractor')
            ->get()
            ->keyBy('item_id');

        // 既存PENDING候補の数量を取得
        $pendingCandidates = WmsStockTransferCandidate::where('satellite_warehouse_id', $warehouseId)
            ->where('status', CandidateStatus::PENDING)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');

        $data = collect($paginator->items())->map(function ($item) use ($summaries, $itemContractors, $pendingCandidates) {
            $summary = $summaries->get($item->id);
            $ic = $itemContractors->get($item->id);
            $pending = $pendingCandidates->get($item->id);

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'packaging' => $item->packaging,
                'capacity_case' => $item->capacity_case ?? 1,
                'search_code' => $item->piece_jan_code_information?->search_string ?? '',
                'contractor_name' => $ic?->contractor
                    ? "[{$ic->contractor->code}]{$ic->contractor->name}"
                    : null,
                'last_shipped_at' => $summary?->last_shipped_at?->format('m/d'),
                'last_3d_qty' => $summary?->last_3d_qty ?? 0,
                'last_7d_qty' => $summary?->last_7d_qty ?? 0,
                'last_30d_qty' => $summary?->last_30d_qty ?? 0,
                'pending_qty' => $pending?->transfer_quantity ?? null,
            ];
        })->values()->toArray();

        return [
            'data' => $data,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function getSubCategories(int $parentId): array
    {
        return ItemCategory::where('parent_id', $parentId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'name'])
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
                ->modalWidth('7xl')
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
                        $totalPieceQty = (int) ($itemData['quantity'] ?? 0);
                        $itemCode = $itemData['item_code'] ?? null;
                        $searchCode = $itemData['search_code'] ?? null;
                        $capacityCase = (int) ($itemData['capacity_case'] ?? 1);
                        if ($capacityCase < 1) {
                            $capacityCase = 1;
                        }

                        if ($totalPieceQty < 1) {
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

                        // ケース/バラ自動分割
                        $caseQty = intdiv($totalPieceQty, $capacityCase);
                        $pieceQty = $totalPieceQty % $capacityCase;

                        // 在庫・入荷予定数をリアルタイム取得
                        $currentStock = $this->getItemStockForCreate($data['satellite_warehouse_id'], $itemId);
                        $hubStock = $this->getItemStockForCreate($data['hub_warehouse_id'], $itemId);
                        $incomingQty = $this->getItemIncomingQuantityForCreate($data['satellite_warehouse_id'], $itemId);

                        // 発注点取得
                        $safetyStock = ItemContractor::where('warehouse_id', $data['satellite_warehouse_id'])
                            ->where('item_id', $itemId)
                            ->value('safety_stock');

                        $commonFields = [
                            'batch_code' => $batchCode,
                            'satellite_warehouse_id' => $data['satellite_warehouse_id'],
                            'hub_warehouse_id' => $data['hub_warehouse_id'],
                            'item_id' => $itemId,
                            'item_code' => $itemCode,
                            'search_code' => $searchCode,
                            'contractor_id' => null,
                            'delivery_course_id' => $data['delivery_course_id'] ?? null,
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'safety_stock' => $safetyStock,
                            'hub_effective_stock' => $hubStock,
                            'expected_arrival_date' => $data['expected_arrival_date'],
                            'original_arrival_date' => $data['expected_arrival_date'],
                            'status' => CandidateStatus::PENDING,
                            'lot_status' => LotStatus::RAW,
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ];

                        // ケース行を作成（ケース数 > 0 の場合）
                        if ($caseQty > 0) {
                            WmsStockTransferCandidate::create(array_merge($commonFields, [
                                'suggested_quantity' => $caseQty,
                                'transfer_quantity' => $caseQty,
                                'quantity_type' => QuantityType::CASE,
                            ]));
                            $created++;
                        }

                        // バラ行を作成（バラ数 > 0 の場合）
                        if ($pieceQty > 0) {
                            WmsStockTransferCandidate::create(array_merge($commonFields, [
                                'suggested_quantity' => $pieceQty,
                                'transfer_quantity' => $pieceQty,
                                'quantity_type' => QuantityType::PIECE,
                            ]));
                            $created++;
                        }

                        WmsOrderCalculationLog::create([
                            'batch_code' => $batchCode,
                            'warehouse_id' => $data['satellite_warehouse_id'],
                            'item_id' => $itemId,
                            'calculation_type' => CalculationType::INTERNAL,
                            'contractor_id' => null,
                            'source_warehouse_id' => $data['hub_warehouse_id'],
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'safety_stock_setting' => $safetyStock ?? 0,
                            'lead_time_days' => 1,
                            'calculated_shortage_qty' => $totalPieceQty,
                            'calculated_order_quantity' => $totalPieceQty,
                            'calculation_details' => [
                                'manual_entry' => true,
                                'created_by' => auth()->id(),
                                'created_at' => now()->toDateTimeString(),
                                'formula' => "手動追加（ケース:{$caseQty} バラ:{$pieceQty}）",
                            ],
                        ]);
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

            // 出荷実績サマリを一括プリロード（N+1対策）
            $warehouseItemPairs = $items->map(fn ($r) => ['warehouse_id' => $r->satellite_warehouse_id, 'item_id' => $r->item_id]);
            $warehouseIds = $warehouseItemPairs->pluck('warehouse_id')->unique()->toArray();
            $itemIds = $warehouseItemPairs->pluck('item_id')->unique()->toArray();
            $summaries = StatsItemWarehouseSalesSummary::whereIn('warehouse_id', $warehouseIds)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy(fn ($s) => "{$s->warehouse_id}_{$s->item_id}");
            $items->each(function ($record) use ($summaries) {
                $record->salesSummary = $summaries->get("{$record->satellite_warehouse_id}_{$record->item_id}");
            });
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
