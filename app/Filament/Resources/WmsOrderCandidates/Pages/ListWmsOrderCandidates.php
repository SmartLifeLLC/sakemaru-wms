<?php

namespace App\Filament\Resources\WmsOrderCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderCandidates\WmsOrderCandidateResource;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\StockSnapshotService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListWmsOrderCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderCandidateResource::class;

    public array $orderCandidateItems = [];

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
                $orderingCode = ($searchInfo && $searchInfo->is_used_for_ordering)
                    ? str_pad($searchCode, 13, '0', STR_PAD_LEFT)
                    : '';

                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'search_code' => $searchCode,
                    'ordering_code' => $orderingCode,
                    'capacity_case' => $item->capacity_case ?? 1,
                ];
            })
            ->toArray();
    }

    public function getItemStockForOrderCreate(int $warehouseId, int $itemId): ?int
    {
        return (int) DB::connection('sakemaru')
            ->table('wms_item_stock_snapshots')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->value('total_effective_piece');
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
                ->modalWidth('5xl')
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

                    // バッチコード取得
                    $pendingJob = WmsAutoOrderJobControl::findPendingSettlement();
                    if ($pendingJob) {
                        $batchCode = $pendingJob->batch_code;
                    } else {
                        $snapshotService = app(StockSnapshotService::class);
                        $snapshotJob = $snapshotService->generateAll();
                        $batchCode = $snapshotJob->batch_code;
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
                        $orderingCode = $itemData['ordering_code'] ?? null;

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

                        // 重複チェック
                        if (WmsOrderCandidate::where('warehouse_id', $data['warehouse_id'])
                            ->where('item_id', $itemId)
                            ->where('status', CandidateStatus::PENDING)
                            ->exists()) {
                            $errors[] = "[{$itemCode}] {$item->name}: 既に存在";

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
                        $purchaseUnitPrice = $item->current_price?->purchase_unit_price;

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
                            'self_shortage_qty' => 0,
                            'satellite_demand_qty' => 0,
                            'suggested_quantity' => $orderQuantity,
                            'order_quantity' => $orderQuantity,
                            'quantity_type' => QuantityType::PIECE,
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
                            'current_effective_stock' => 0,
                            'incoming_quantity' => 0,
                            'safety_stock_setting' => 0,
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
                        $count = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();

                        return "承認前（PENDING）の発注候補 {$count}件 を全て承認します。";
                    })
                    ->modalSubmitActionLabel('全て承認')
                    ->action(function () {
                        $updated = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                            ->update([
                                'status' => CandidateStatus::APPROVED,
                                'updated_at' => now(),
                            ]);

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
                        $count = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();

                        return "承認前（PENDING）の発注候補 {$count}件 を全て削除します。この操作は取り消せません。";
                    })
                    ->modalSubmitActionLabel('全削除')
                    ->action(function () {
                        $deleted = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->delete();

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
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        // デフォルト倉庫が発注候補に存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // プリセットビュー構築（データがなくても「全て」タブは常に表示）
        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
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

        $views['all'] = PresetView::make()
            ->favorite()
            ->label('全て');

        // 倉庫タブを追加
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
