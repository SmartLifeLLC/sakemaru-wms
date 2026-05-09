<?php

namespace App\Filament\Resources\WmsOrderCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\AutoOrder\SettlementStatus;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderCandidates\WmsOrderCandidateResource;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\StatsItemWarehouseSalesSummary;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class ListWmsOrderCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderCandidateResource::class;

    public array $orderCandidateItems = [];

    private function getOrderingCodeForItem(int $itemId): ?string
    {
        $code = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('search_string');

        return filled($code) ? str_pad((string) $code, 13, '0', STR_PAD_LEFT) : null;
    }

    public function searchItemsForOrderCreate(string $search): array
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
            ->map(function ($item) {
                $searchInfo = $item->piece_jan_code_information;
                $searchCode = $searchInfo?->search_string ?? '';

                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'search_code' => $searchCode,
                    'ordering_code' => $this->getOrderingCodeForItem($item->id),
                    'capacity_case' => $item->capacity_case ?? 1,
                ];
            })
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

        // 商品CD検索
        if ($itemCode && strlen($itemCode) >= 1) {
            $itemCode = mb_convert_kana($itemCode, 'as');
            $query->where('items.code', 'like', "%{$itemCode}%");
        }

        // JANコード検索
        if ($janCode && strlen($janCode) >= 1) {
            $janCode = mb_convert_kana($janCode, 'as');
            $query->whereHas('piece_jan_code_information', function ($sq) use ($janCode) {
                $sq->where('search_string', 'like', "%{$janCode}%");
            });
        }

        // 商品名検索
        if ($itemName && strlen($itemName) >= 2) {
            $itemName = mb_convert_kana($itemName, 'as');
            $query->where('items.name', 'like', "%{$itemName}%");
        }

        // 発注先フィルタ
        if ($contractorId) {
            $query->whereHas('item_contractors', function ($q) use ($contractorId, $warehouseId) {
                $q->where('contractor_id', $contractorId)
                    ->where('warehouse_id', $warehouseId);
            });
        }

        // カテゴリフィルタ
        if ($category1Id) {
            $query->where('items.item_category1_id', $category1Id);
        }
        if ($category2Id) {
            $query->where('items.item_category2_id', $category2Id);
        }
        if ($category3Id) {
            $query->where('items.item_category3_id', $category3Id);
        }

        // 最終出荷日フィルタ（summariesテーブルから）
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

        // ページネーション
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // 出荷実績サマリを一括取得
        $itemIds = collect($paginator->items())->pluck('id')->toArray();
        $summaries = StatsItemWarehouseSalesSummary::where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');

        // 発注先情報を一括取得
        // 入荷倉庫を特定（仮想倉庫対応）
        $orderWarehouse = Warehouse::find($warehouseId);
        $incomingWarehouseId = ($orderWarehouse?->is_virtual && $orderWarehouse->stock_warehouse_id)
            ? $orderWarehouse->stock_warehouse_id
            : $warehouseId;

        $itemContractors = ItemContractor::where('warehouse_id', $incomingWarehouseId)
            ->whereIn('item_id', $itemIds)
            ->with('contractor')
            ->get()
            ->keyBy('item_id');

        // 既存PENDING候補の数量を取得
        $pendingCandidates = WmsOrderCandidate::where('warehouse_id', $warehouseId)
            ->where('status', CandidateStatus::PENDING)
            ->forCreatedBy(auth()->id())
            ->whereIn('item_id', $itemIds)
            ->get()
            ->groupBy('item_id');

        // 結果を整形
        $data = collect($paginator->items())->map(function ($item) use ($summaries, $itemContractors, $pendingCandidates) {
            $summary = $summaries->get($item->id);
            $ic = $itemContractors->get($item->id);
            $pending = $pendingCandidates->get($item->id);

            $searchInfo = $item->piece_jan_code_information;

            $pendingCaseQty = 0;
            $pendingPieceQty = 0;
            if ($pending) {
                foreach ($pending as $candidate) {
                    if ($candidate->quantity_type === QuantityType::CASE) {
                        $pendingCaseQty += $candidate->order_quantity;
                    } else {
                        $pendingPieceQty += $candidate->order_quantity;
                    }
                }
            }

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'packaging' => $item->packaging,
                'capacity_case' => $item->capacity_case ?? 1,
                'search_code' => $searchInfo?->search_string ?? '',
                'ordering_code' => $this->getOrderingCodeForItem($item->id),
                'contractor_name' => $ic?->contractor
                    ? "[{$ic->contractor->code}]{$ic->contractor->name}"
                    : null,
                'last_shipped_at' => $summary?->last_shipped_at?->format('m/d'),
                'last_3d_qty' => $summary?->last_3d_qty ?? 0,
                'last_7d_qty' => $summary?->last_7d_qty ?? 0,
                'last_30d_qty' => $summary?->last_30d_qty ?? 0,
                'pending_case_qty' => $pendingCaseQty ?: null,
                'pending_piece_qty' => $pendingPieceQty ?: null,
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

    public function getItemStockForOrderCreate(int $warehouseId, int $itemId): ?int
    {
        return (int) DB::connection('sakemaru')
            ->table('wms_v_stock_available')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->sum('available_quantity');
    }

    public function getItemIncomingQuantityForOrderCreate(int $warehouseId, int $itemId): int
    {
        return (int) (DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('SUM(expected_quantity - received_quantity) as total_incoming')
            ->value('total_incoming') ?? 0);
    }

    public function mount(): void
    {
        parent::mount();

        // 発注候補生成からのリダイレクト時に通知を表示
        if ($result = session('order_generation_result')) {
            Notification::make()
                ->title('発注候補を生成しました')
                ->body("実行CD: {$result['batchCode']} / {$result['calculated']}件")
                ->success()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('発注追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('発注候補を追加')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('追加する')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('warehouse_id')
                            ->label('発注倉庫')
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                            ->default(fn () => auth()->user()?->getSelectedWarehouseId())
                            ->searchable()
                            ->required()
                            ->live(),

                        Placeholder::make('incoming_warehouse_display')
                            ->label('入荷倉庫')
                            ->content(function ($get) {
                                $warehouseId = $get('warehouse_id');
                                if (! $warehouseId) {
                                    return new HtmlString("<span class='text-gray-400'>-</span>");
                                }
                                $warehouse = Warehouse::find($warehouseId);
                                if (! $warehouse) {
                                    return new HtmlString("<span class='text-gray-400'>-</span>");
                                }
                                $incoming = $warehouse;
                                if ($warehouse->is_virtual && $warehouse->stock_warehouse_id) {
                                    $incoming = Warehouse::find($warehouse->stock_warehouse_id) ?? $warehouse;
                                }

                                return new HtmlString("<span class='font-bold text-blue-600'>[{$incoming->code}]{$incoming->name}</span>");
                            }),

                        DatePicker::make('expected_arrival_date')
                            ->label('入荷予定日')
                            ->default(now()->addDay())
                            ->required(),
                    ]),

                    ViewField::make('items_table')
                        ->view('filament.components.order-candidate-create-items')
                        ->hiddenLabel(),
                ])
                ->action(function (array $data) {
                    $items = $this->orderCandidateItems;

                    if (empty($items)) {
                        Notification::make()
                            ->title('エラー')
                            ->body('商品を追加してください')
                            ->danger()
                            ->send();

                        return;
                    }

                    // 同日・同倉庫のPENDINGジョブを再利用、なければ新規作成
                    $warehouseId = $data['warehouse_id'];
                    $existingJob = WmsAutoOrderJobControl::where('process_name', JobProcessName::ORDER_CALC)
                        ->where('settlement_status', SettlementStatus::PENDING)
                        ->where('created_by', auth()->id())
                        ->where('warehouse_id', $warehouseId)
                        ->whereDate('started_at', today())
                        ->orderByDesc('id')
                        ->first();

                    if ($existingJob) {
                        $batchCode = $existingJob->batch_code;
                    } else {
                        $newJob = WmsAutoOrderJobControl::startJob(
                            processName: JobProcessName::ORDER_CALC,
                            createdBy: auth()->id(),
                            warehouseId: $warehouseId,
                            batchCode: WmsAutoOrderJobControl::generateBatchCode($warehouseId),
                        );
                        $batchCode = $newJob->batch_code;
                        $newJob->markAsSuccess(0);
                    }

                    // 入荷倉庫を特定（仮想倉庫対応）
                    $orderWarehouse = Warehouse::find($data['warehouse_id']);
                    $incomingWarehouseId = ($orderWarehouse?->is_virtual && $orderWarehouse->stock_warehouse_id)
                        ? $orderWarehouse->stock_warehouse_id
                        : $data['warehouse_id'];

                    $created = 0;
                    $errors = [];

                    foreach ($items as $itemData) {
                        $itemId = $itemData['item_id'];
                        $orderQuantity = (int) ($itemData['order_quantity'] ?? 0);
                        $itemCode = $itemData['item_code'] ?? null;
                        $searchCode = $itemData['search_code'] ?? null;
                        $orderingCode = filled($itemData['ordering_code'] ?? null)
                            ? $itemData['ordering_code']
                            : $this->getOrderingCodeForItem($itemId);

                        if ($orderQuantity <= 0) {
                            $errors[] = "[{$itemCode}]: 数量が不正です";

                            continue;
                        }

                        // 販売終了品チェック
                        $item = Item::find($itemId);
                        if ($item && $item->end_of_sale_type !== 'NORMAL') {
                            $errors[] = "[{$itemCode}] {$item->name}: 販売終了品";

                            continue;
                        }

                        // quantity_type の判定
                        $quantityTypeValue = $itemData['quantity_type'] ?? 'PIECE';
                        $quantityType = QuantityType::from($quantityTypeValue);

                        // 重複チェック（同じ商品×同じquantity_type）
                        if (WmsOrderCandidate::where('warehouse_id', $data['warehouse_id'])
                            ->where('item_id', $itemId)
                            ->where('quantity_type', $quantityType)
                            ->where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->exists()) {
                            $errors[] = "[{$itemCode}] {$item->name} ({$quantityType->name()}): 既に存在";

                            continue;
                        }

                        // 発注先取得
                        $itemContractor = ItemContractor::where('warehouse_id', $incomingWarehouseId)
                            ->where('item_id', $itemId)
                            ->first();

                        if (! $itemContractor) {
                            $errors[] = "[{$itemCode}] {$item->name}: 発注先未設定";

                            continue;
                        }

                        $contractor = Contractor::find($itemContractor->contractor_id);
                        $supplierId = $itemContractor->supplier_id;

                        // quantity_type に応じた単価
                        $purchaseUnitPrice = $quantityType === QuantityType::CASE
                            ? $item->current_price?->purchase_case_price
                            : $item->current_price?->purchase_unit_price;

                        // 在庫・入荷予定数をリアルタイム取得
                        $currentStock = $this->getItemStockForOrderCreate($data['warehouse_id'], $itemId);
                        $incomingQty = $this->getItemIncomingQuantityForOrderCreate($incomingWarehouseId, $itemId);

                        WmsOrderCandidate::create([
                            'batch_code' => $batchCode,
                            'warehouse_id' => $data['warehouse_id'],
                            'item_id' => $itemId,
                            'item_code' => $itemCode,
                            'search_code' => $searchCode,
                            'ordering_code' => $orderingCode,
                            'contractor_id' => $itemContractor->contractor_id,
                            'supplier_id' => $supplierId,
                            'purchase_unit_price' => $purchaseUnitPrice,
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'safety_stock' => $itemContractor->safety_stock,
                            'self_shortage_qty' => 0,
                            'satellite_demand_qty' => 0,
                            'suggested_quantity' => $orderQuantity,
                            'order_quantity' => $orderQuantity,
                            'quantity_type' => $quantityType,
                            'expected_arrival_date' => $data['expected_arrival_date'],
                            'original_arrival_date' => $data['expected_arrival_date'],
                            'status' => CandidateStatus::PENDING,
                            'lot_status' => LotStatus::RAW,
                            'origin_type' => OriginType::USER,
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);

                        WmsOrderCalculationLog::create([
                            'batch_code' => $batchCode,
                            'warehouse_id' => $data['warehouse_id'],
                            'item_id' => $itemId,
                            'calculation_type' => CalculationType::EXTERNAL,
                            'contractor_id' => $itemContractor->contractor_id,
                            'source_warehouse_id' => null,
                            'current_effective_stock' => $currentStock,
                            'incoming_quantity' => $incomingQty,
                            'safety_stock_setting' => $itemContractor->safety_stock ?? 0,
                            'lead_time_days' => 0,
                            'calculated_shortage_qty' => $orderQuantity,
                            'calculated_order_quantity' => $orderQuantity,
                            'calculation_details' => [
                                'manual_entry' => true,
                                'created_by' => auth()->id(),
                                'created_at' => now()->toDateTimeString(),
                                'formula' => '手動追加',
                                'case_qty' => $itemData['case_qty'] ?? 0,
                                'piece_qty' => $itemData['piece_qty'] ?? 0,
                                'capacity_case' => $itemData['capacity_case'] ?? 1,
                            ],
                        ]);

                        $created++;
                    }

                    $this->orderCandidateItems = [];

                    if ($created > 0 && empty($errors)) {
                        Notification::make()
                            ->title("{$created}件の発注候補を追加しました")
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

                    $this->redirect(static::getResource()::getUrl('index'));
                }),

            ActionGroup::make([
                Action::make('approveAll')
                    ->label('全て承認')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('発注候補を全て承認')
                    ->modalDescription(function () {
                        $count = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->count();

                        return "承認前（PENDING）の発注候補 {$count}件 を全て承認します。";
                    })
                    ->modalSubmitActionLabel('全て承認')
                    ->action(function () {
                        $updated = 0;
                        WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->with('item.current_price')
                            ->orderBy('id')
                            ->chunkById(500, function ($candidates) use (&$updated) {
                                foreach ($candidates as $candidate) {
                                    WmsOrderConfirmationWaitingTable::applyOrderingUnitConversionForApproval($candidate);
                                    $candidate->update([
                                        'status' => CandidateStatus::APPROVED,
                                        'updated_at' => now(),
                                    ]);
                                    $updated++;
                                }
                            });

                        Notification::make()
                            ->title('発注候補を全て承認しました')
                            ->body("{$updated}件 を承認しました。")
                            ->success()
                            ->send();
                    }),

                Action::make('deleteAllPending')
                    ->label('承認前を全削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('承認前の発注候補を全削除')
                    ->modalDescription(function () {
                        $count = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->count();

                        return "承認前（PENDING）の発注候補 {$count}件 を全て削除します。この操作は取り消せません。";
                    })
                    ->modalSubmitActionLabel('全削除')
                    ->action(function () {
                        $deleted = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->delete();

                        Notification::make()
                            ->title('承認前の発注候補を削除しました')
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
                    'warehouse',
                    'item.current_price',
                    'item.piece_jan_code_information',
                    'contractor',
                    'supplier.partner',
                ])
                // デフォルトでPENDINGのみ表示（大幅な高速化）
                ->where('status', CandidateStatus::PENDING)
                ->forCreatedBy(auth()->id())
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    /**
     * テーブルレコード取得後に計算ログとItemContractorをプリロード（N+1対策）
     */
    protected function paginateTableQuery(Builder $query): \Illuminate\Contracts\Pagination\Paginator
    {
        $paginator = parent::paginateTableQuery($query);

        // 計算ログとItemContractorを一括プリロード
        $items = $paginator->getCollection();
        if ($items->isNotEmpty()) {
            WmsOrderCandidate::preloadCalculationLogs($items);
            WmsOrderCandidate::preloadItemContractors($items);

            // 出荷実績サマリを一括プリロード（N+1対策）
            $warehouseItemPairs = $items->map(fn ($r) => ['warehouse_id' => $r->warehouse_id, 'item_id' => $r->item_id]);
            $warehouseIds = $warehouseItemPairs->pluck('warehouse_id')->unique()->toArray();
            $itemIds = $warehouseItemPairs->pluck('item_id')->unique()->toArray();
            $summaries = StatsItemWarehouseSalesSummary::whereIn('warehouse_id', $warehouseIds)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy(fn ($s) => "{$s->warehouse_id}_{$s->item_id}");
            $items->each(function ($record) use ($summaries) {
                $record->salesSummary = $summaries->get("{$record->warehouse_id}_{$record->item_id}");
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

        $cacheKey = 'order_candidates_pending_warehouses_'.auth()->id();
        $this->presetViewWarehouseData = cache()->remember($cacheKey, 30, function () {
            $warehouseIds = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                ->forCreatedBy(auth()->id())
                ->distinct()
                ->pluck('warehouse_id')
                ->toArray();

            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('code')
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

        // デフォルト倉庫が発注候補に存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // 「全て」タブは常に表示（キー'default'でAdvancedTablesのDefaultビューを上書き）
        $views = [
            'default' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        // デフォルト倉庫タブ（設定されている場合は先頭に配置してデフォルト選択）
        if ($defaultWarehouse) {
            $views["default_{$defaultWarehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        }

        // 他の倉庫タブ（デフォルト倉庫は除外）
        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
