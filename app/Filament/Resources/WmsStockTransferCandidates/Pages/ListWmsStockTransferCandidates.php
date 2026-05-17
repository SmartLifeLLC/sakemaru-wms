<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\AutoOrder\SettlementStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsStockTransferCandidates\Tables\WmsStockTransferCandidatesTable;
use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\Sakemaru\WarehouseStockTransferDeliveryCourse;
use App\Models\StatsItemWarehouseSalesSummary;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
use App\Models\WmsWarehouseAutoOrderSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Table;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ListWmsStockTransferCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsStockTransferCandidateResource::class;

    private const SALES_BASED_TRANSFER_HUB_WAREHOUSE_ID = 91;

    public array $transferOrderItems = [];

    public array $transferQuantityInputPayload = [];

    public array $salesBasedTransferCategory2Data = [];

    public array $selectedSalesBasedTransferCategory2Ids = [];

    public array $salesBasedTransferPreviewRows = [];

    public array $salesBasedTransferPreviewConditions = [];

    public ?string $salesBasedTransferPreviewError = null;

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
                    ->orWhereHas('item_search_information', function ($q) use ($search) {
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
            ])
            ->with('piece_jan_code_information')
            ->where('items.end_of_sale_type', 'NORMAL');

        // 商品CD・JANコード検索（OR）
        $hasItemCode = $itemCode && strlen($itemCode) >= 1;
        $hasJanCode = $janCode && strlen($janCode) >= 1;
        if ($hasItemCode || $hasJanCode) {
            $itemCode = $hasItemCode ? mb_convert_kana($itemCode, 'as') : null;
            $janCode = $hasJanCode ? mb_convert_kana($janCode, 'as') : null;
            $query->where(function ($q) use ($itemCode, $janCode) {
                if ($itemCode) {
                    $q->where('items.code', 'like', "%{$itemCode}%");
                }
                if ($janCode) {
                    $q->orWhereHas('item_search_information', function ($sq) use ($janCode) {
                        $sq->where('search_string', 'like', "%{$janCode}%");
                    });
                }
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
            ->forCreatedBy(auth()->id())
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
                'search_code' => $item->piece_jan_code_information?->search_string ?? '',
                'contractor_code' => $ic?->contractor?->code,
                'contractor_name' => $ic?->contractor
                    ? "[{$ic->contractor->code}]{$ic->contractor->name}"
                    : null,
                'safety_stock' => $ic?->safety_stock ?? 0,
                'auto_order_quantity' => $ic?->auto_order_quantity ?? 0,
                'is_auto_order' => (bool) ($ic?->is_auto_order ?? false),
                'is_consumable' => $ic !== null && ! (bool) $ic->is_auto_order,
                'last_shipped_at' => $summary?->last_shipped_at?->format('m/d'),
                'sales_today_qty' => $summary?->sales_today_qty ?? 0,
                'sales_yesterday_qty' => $summary?->sales_yesterday_qty ?? 0,
                'sales_2days_ago_qty' => $summary?->sales_2days_ago_qty ?? 0,
                'last_3d_qty' => $summary?->last_3d_qty ?? 0,
                'last_5d_qty' => $summary?->last_5d_qty ?? 0,
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

    private function getCreateSatelliteWarehouseId(): ?int
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? (int) $warehouseId : null;
    }

    private function getCreateHubWarehouseId(): ?int
    {
        $warehouseId = WmsContractorSetting::where('transmission_type', TransmissionType::INTERNAL)
            ->whereNotNull('supply_warehouse_id')
            ->orderBy('supply_warehouse_id')
            ->value('supply_warehouse_id');

        return $warehouseId ? (int) $warehouseId : null;
    }

    private function getCreateDeliveryCourseId(): ?int
    {
        $satelliteWarehouseId = $this->getCreateSatelliteWarehouseId();
        $hubWarehouseId = $this->getCreateHubWarehouseId();

        if (! $satelliteWarehouseId || ! $hubWarehouseId) {
            return null;
        }

        $deliveryCourseId = WarehouseStockTransferDeliveryCourse::query()
            ->where('from_warehouse_id', $hubWarehouseId)
            ->where('to_warehouse_id', $satelliteWarehouseId)
            ->value('delivery_course_id');

        return $deliveryCourseId ? (int) $deliveryCourseId : null;
    }

    private function formatWarehouseForCreate(?int $warehouseId): string
    {
        if (! $warehouseId) {
            return '-';
        }

        $warehouse = Warehouse::query()->find($warehouseId);

        return $warehouse ? "[{$warehouse->code}]{$warehouse->name}" : '-';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('個別発注追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('個別発注を追加')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn (Action $action) => $action->makeModalSubmitAction('submit')->label('追加する')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema([
                    Grid::make(12)->schema([
                        Hidden::make('satellite_warehouse_id')
                            ->default(fn () => $this->getCreateSatelliteWarehouseId())
                            ->required(),

                        Hidden::make('hub_warehouse_id')
                            ->default(fn () => $this->getCreateHubWarehouseId())
                            ->required(),

                        Hidden::make('delivery_course_id')
                            ->default(fn () => $this->getCreateDeliveryCourseId()),

                        Placeholder::make('warehouse_summary')
                            ->hiddenLabel()
                            ->content(fn () => new \Illuminate\Support\HtmlString(
                                '<div class="grid h-full grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-slate-50 px-5 py-3 text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">'
                                .'<div class="flex items-baseline gap-3"><span class="whitespace-nowrap text-sm font-semibold text-slate-500 dark:text-slate-400">依頼倉庫</span><span class="truncate text-xl font-bold">'.$this->formatWarehouseForCreate($this->getCreateSatelliteWarehouseId()).'</span></div>'
                                .'<div class="flex items-baseline gap-3"><span class="whitespace-nowrap text-sm font-semibold text-slate-500 dark:text-slate-400">移動元倉庫</span><span class="truncate text-xl font-bold">'.$this->formatWarehouseForCreate($this->getCreateHubWarehouseId()).'</span></div>'
                                .'</div>'
                            ))
                            ->columnSpan(7),

                        Placeholder::make('expected_arrival_date_label')
                            ->hiddenLabel()
                            ->content('入荷予定日')
                            ->extraAttributes(['class' => 'flex h-full min-h-14 items-center justify-end text-sm font-bold text-slate-700 dark:text-slate-200'])
                            ->columnSpan(1),

                        ViewField::make('expected_arrival_date')
                            ->hiddenLabel()
                            ->view('filament.forms.components.smart-date-input')
                            ->default(now()->addDay()->toDateString())
                            ->required()
                            ->columnSpan(4),
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

                    $created = 0;
                    $errors = [];
                    $lockKey = implode(':', [
                        'wms-stock-transfer-create',
                        auth()->id() ?? 0,
                        $data['satellite_warehouse_id'],
                        $data['hub_warehouse_id'],
                    ]);

                    try {
                        Cache::lock($lockKey, 10)->block(5, function () use ($data, $items, &$created, &$errors): void {
                            $this->createTransferOrderCandidates($data, $items, $created, $errors);
                        });
                    } catch (LockTimeoutException) {
                        Notification::make()
                            ->title('追加処理中です')
                            ->body('直前の追加処理が完了してから再度実行してください。')
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->transferOrderItems = [];

                    if ($created > 0 && empty($errors)) {
                        Notification::make()
                            ->title("{$created}件の個別発注を追加しました")
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

            $this->getSalesBasedTransferGenerateAction(),

            Action::make('transferQuantityInput')
                ->label('発注数量編集')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->modalHeading('発注数量編集')
                ->modalWidth('full')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal sales-based-transfer-preview-modal'])
                ->modalSubmitActionLabel('一括保存')
                ->modalCancelActionLabel('保存せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema(function (): array {
                    $records = $this->getTransferQuantityInputRecords();

                    if ($records->isEmpty()) {
                        return [
                            \Filament\Forms\Components\Placeholder::make('empty')
                                ->label('')
                                ->content('現在の条件に該当する移動候補がありません。'),
                        ];
                    }

                    $rows = $this->buildTransferQuantityInputRows($records);
                    $this->transferQuantityInputPayload = collect($rows)
                        ->map(fn (array $row): array => [
                            'id' => $row['id'],
                            'transfer_quantity' => $row['transfer_quantity'],
                        ])
                        ->values()
                        ->toArray();

                    return [
                        ViewField::make('transfer_quantity_input')
                            ->view('filament.components.stock-transfer-candidate-quantity-input')
                            ->viewData(['rows' => $rows])
                            ->hiddenLabel(),
                    ];
                })
                ->action(function () {
                    $payload = collect($this->transferQuantityInputPayload)
                        ->filter(fn ($row) => isset($row['id']))
                        ->keyBy(fn ($row) => (int) $row['id']);

                    if ($payload->isEmpty()) {
                        Notification::make()
                            ->title('更新対象がありません')
                            ->warning()
                            ->send();

                        return;
                    }

                    $records = WmsStockTransferCandidate::query()
                        ->whereIn('id', $payload->keys()->all())
                        ->get();

                    $updated = 0;
                    $recalculated = 0;
                    $skipped = 0;
                    $errors = [];
                    $userId = auth()->id();

                    foreach ($records as $record) {
                        if ($record->status !== CandidateStatus::PENDING) {
                            $skipped++;

                            continue;
                        }

                        $row = $payload->get((int) $record->id, []);
                        $newQuantity = max(0, (int) ($row['transfer_quantity'] ?? 0));
                        $oldQuantity = (int) $record->transfer_quantity;

                        try {
                            $updatedOrder = WmsStockTransferCandidatesTable::applyTransferQuantityChange($record, $newQuantity, $userId);

                            if ($oldQuantity === $newQuantity) {
                                $skipped++;
                            } else {
                                $updated++;
                                if ($updatedOrder) {
                                    $recalculated++;
                                }
                            }
                        } catch (\Throwable $e) {
                            $errors[] = "[{$record->item_code}] 更新できませんでした: {$e->getMessage()}";
                        }
                    }

                    if ($updated > 0) {
                        Notification::make()
                            ->title("発注数量を保存しました: 更新 {$updated}件")
                            ->body($recalculated > 0 ? "関連発注候補の再計算 {$recalculated}件" : ($skipped > 0 ? "変更なし・対象外 {$skipped}件" : null))
                            ->success()
                            ->send();
                    } elseif (empty($errors)) {
                        Notification::make()
                            ->title('変更はありません')
                            ->warning()
                            ->send();
                    }

                    if (! empty($errors)) {
                        Notification::make()
                            ->title(count($errors).'件で更新できませんでした')
                            ->body(implode("\n", array_slice($errors, 0, 5)))
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
                        $count = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->count();

                        return "承認前（PENDING）の移動候補 {$count}件 を全て承認します。";
                    })
                    ->modalSubmitActionLabel('全て承認')
                    ->action(function () {
                        $updated = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
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
                        $count = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->count();

                        return "承認前（PENDING）の移動候補 {$count}件 を全て削除します。この操作は取り消せません。";
                    })
                    ->modalSubmitActionLabel('全削除')
                    ->action(function () {
                        $deleted = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                            ->forCreatedBy(auth()->id())
                            ->delete();

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

    private function getSalesBasedTransferGenerateAction(): Action
    {
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $selectedWarehouse = $selectedWarehouseId ? Warehouse::find($selectedWarehouseId) : null;
        $selectedWarehouseName = $selectedWarehouse?->name ?? '未選択';

        return Action::make('generateSalesBasedTransfer')
            ->label('物流発注候補生成')
            ->icon('heroicon-o-chart-bar')
            ->color('info')
            ->modalWidth('7xl')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal sales-based-transfer-generate-modal'])
            ->modalHeading("物流発注候補生成（{$selectedWarehouseName}）")
            ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
            ->modalSubmitAction(fn (Action $action) => $action->makeModalSubmitAction('submit')->label('候補表示')->color('danger'))
            ->modalCancelActionLabel('表示せず閉じる')
            ->disabled(! $selectedWarehouse)
            ->mountUsing(function ($schema): void {
                $this->resetSalesBasedTransferPreview();
                $this->initializeSalesBasedTransferCategory2();
                if (empty($this->selectedSalesBasedTransferCategory2Ids)) {
                    $this->selectedSalesBasedTransferCategory2Ids = collect($this->salesBasedTransferCategory2Data)
                        ->pluck('id')
                        ->values()
                        ->toArray();
                }
                $schema?->fill([
                    'sales_start_date' => now()->subDays(2)->toDateString(),
                    'sales_end_date' => now()->toDateString(),
                    'auto_order_flag_filter' => 'ignore',
                ]);
            })
            ->schema([
                Grid::make(2)->schema([
                    ViewField::make('sales_start_date')
                        ->label('販売実績 開始日')
                        ->view('filament.forms.components.smart-date-input')
                        ->viewData(['size' => 'large'])
                        ->extraAttributes(['class' => 'sales-based-transfer-date-field'])
                        ->default(now()->subDays(2)->toDateString())
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetSalesBasedTransferPreview())
                        ->required(),
                    ViewField::make('sales_end_date')
                        ->label('販売実績 終了日')
                        ->view('filament.forms.components.smart-date-input')
                        ->viewData(['size' => 'large'])
                        ->extraAttributes(['class' => 'sales-based-transfer-date-field'])
                        ->default(now()->toDateString())
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetSalesBasedTransferPreview())
                        ->required(),
                ]),
                ViewField::make('category2_selector')
                    ->view('filament.components.category-selection')
                    ->viewData([
                        'categoriesProperty' => 'salesBasedTransferCategory2Data',
                        'fallbackMethod' => 'getSalesBasedTransferCategory2ForGeneration',
                        'selectedProperty' => 'selectedSalesBasedTransferCategory2Ids',
                        'label' => '中分類',
                        'compactListHeight' => true,
                        'twoColumns' => true,
                    ])
                    ->hiddenLabel(),
            ])
            ->action(function (Action $action) {
                $this->calculateSalesBasedTransferPreview();

                if (empty($this->salesBasedTransferPreviewRows)) {
                    Notification::make()
                        ->title('表示できる候補がありません')
                        ->body($this->salesBasedTransferPreviewError ?? '条件を変更して再度候補表示してください。')
                        ->warning()
                        ->send();

                    $action->halt();
                }

                $this->replaceMountedAction('salesBasedTransferPreviewModal');
            });
    }

    protected function salesBasedTransferPreviewModalAction(): Action
    {
        return Action::make('salesBasedTransferPreviewModal')
            ->label('物流発注候補表示')
            ->modalWidth('7xl')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal sales-based-transfer-preview-modal'])
            ->modalHeading('物流発注候補リスト')
            ->modalSubmitAction(fn (Action $action) => $action->makeModalSubmitAction('submit')->label('候補生成')->color('danger'))
            ->modalCancelActionLabel('閉じる')
            ->schema([
                ViewField::make('sales_based_transfer_preview_edit')
                    ->view('filament.components.sales-based-transfer-preview-edit')
                    ->hiddenLabel(),
            ])
            ->action(function (): void {
                $this->createSalesBasedTransferPreviewCandidates();
            });
    }

    public function resetSalesBasedTransferPreview(): void
    {
        $this->salesBasedTransferPreviewRows = [];
        $this->salesBasedTransferPreviewConditions = [];
        $this->salesBasedTransferPreviewError = null;
    }

    public function calculateSalesBasedTransferPreview(): void
    {
        $this->resetSalesBasedTransferPreview();

        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        if (! $selectedWarehouseId) {
            $this->salesBasedTransferPreviewError = '倉庫が選択されていません。';

            return;
        }

        $selectedWarehouse = Warehouse::find($selectedWarehouseId);
        $targetHubWarehouse = Warehouse::find(self::SALES_BASED_TRANSFER_HUB_WAREHOUSE_ID);
        $category2Ids = array_values(array_unique(array_map('intval', $this->selectedSalesBasedTransferCategory2Ids)));
        $allCategory2Ids = collect($this->salesBasedTransferCategory2Data ?: $this->getSalesBasedTransferCategory2ForGeneration())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->toArray();

        if (empty($category2Ids)) {
            $this->salesBasedTransferPreviewError = '中分類を1件以上選択してください。';

            return;
        }

        $data = $this->getMountedSalesBasedTransferActionData();
        $startDate = $data['sales_start_date'] ?? now()->subDays(2)->toDateString();
        $endDate = $data['sales_end_date'] ?? now()->toDateString();

        try {
            $startDate = \Carbon\Carbon::parse($startDate)->toDateString();
            $endDate = \Carbon\Carbon::parse($endDate)->toDateString();
        } catch (\Throwable) {
            $this->salesBasedTransferPreviewError = '販売実績の期間を正しく指定してください。';

            return;
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $days = max(1, \Carbon\Carbon::parse($startDate)->diffInDays(\Carbon\Carbon::parse($endDate)) + 1);
        $this->salesBasedTransferPreviewConditions = [
            'sales_start_date' => $startDate,
            'sales_end_date' => $endDate,
            'selected_warehouse_name' => $selectedWarehouse?->name ?? '未選択',
            'target_warehouse_name' => $targetHubWarehouse?->name ?? '華むすびの蔵センター',
            'auto_order_flag_filter' => '考慮しない',
            'days' => $days,
            'category2_count' => count($category2Ids),
            'category2_total_count' => count($allCategory2Ids),
        ];

        $warehouseIds = $this->getSalesBasedTransferGenerationWarehouseIds($selectedWarehouseId);

        if (empty($warehouseIds)) {
            $this->salesBasedTransferPreviewError = '対象倉庫がありません。';

            return;
        }

        $pendingJob = WmsAutoOrderJobControl::findPendingSettlementForWarehouse(
            $selectedWarehouseId,
            auth()->id(),
            [JobProcessName::ORDER_CALC, JobProcessName::SALES_BASED_CALC]
        );
        $batchCode = $pendingJob?->batch_code;

        $salesSubquery = DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->whereBetween('business_date', [$startDate, $endDate])
            ->selectRaw('
                warehouse_id,
                item_id,
                SUM(shipped_piece_qty) as sales_qty,
                SUM(sales_piece_qty) as sales_piece_qty,
                SUM(return_piece_qty) as return_piece_qty,
                SUM(transfer_piece_qty) as transfer_piece_qty
            ')
            ->groupBy('warehouse_id', 'item_id')
            ->havingRaw('SUM(shipped_piece_qty) > 0');

        $stockSubquery = DB::connection('sakemaru')
            ->query()
            ->fromSub(
                DB::connection('sakemaru')
                    ->table('wms_v_stock_available')
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->selectRaw('DISTINCT warehouse_id, item_id, real_stock_id, available_for_wms as stock_qty'),
                'dedup_stocks'
            )
            ->selectRaw('warehouse_id, item_id, SUM(stock_qty) as effective_stock')
            ->groupBy('warehouse_id', 'item_id');

        $incomingSubquery = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('warehouse_id, item_id, SUM(expected_quantity - received_quantity) as incoming_qty')
            ->groupBy('warehouse_id', 'item_id');

        $query = DB::connection('sakemaru')
            ->table('item_contractors')
            ->join('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'item_contractors.contractor_id')
            ->join('items', 'item_contractors.item_id', '=', 'items.id')
            ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
            ->joinSub($salesSubquery, 'sales', function ($join) {
                $join->on('sales.warehouse_id', '=', 'item_contractors.warehouse_id')
                    ->on('sales.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($stockSubquery, 'stocks', function ($join) {
                $join->on('stocks.warehouse_id', '=', 'item_contractors.warehouse_id')
                    ->on('stocks.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($incomingSubquery, 'incoming', function ($join) {
                $join->on('incoming.warehouse_id', '=', 'item_contractors.warehouse_id')
                    ->on('incoming.item_id', '=', 'item_contractors.item_id');
            })
            ->where('wcs.transmission_type', TransmissionType::INTERNAL->value)
            ->where('wcs.supply_warehouse_id', self::SALES_BASED_TRANSFER_HUB_WAREHOUSE_ID)
            ->whereIn('item_contractors.warehouse_id', $warehouseIds)
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($query) => $query->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($query) => $query->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('item_search_information as isi')
                    ->whereColumn('isi.item_id', 'item_contractors.item_id')
                    ->where('isi.is_used_for_ordering', true)
                    ->where('isi.is_active', true)
                    ->whereRaw("isi.search_string REGEXP '[1-9]'");
            });

        if (count($category2Ids) < count($allCategory2Ids)) {
            $query->whereIn('items.item_category2_id', $category2Ids);
        }

        if ($batchCode) {
            $query->whereNotExists(function ($query) use ($batchCode) {
                $query->selectRaw('1')
                    ->from('wms_stock_transfer_candidates as existing_candidates')
                    ->where('existing_candidates.batch_code', $batchCode)
                    ->where('existing_candidates.status', CandidateStatus::APPROVED->value)
                    ->whereColumn('existing_candidates.satellite_warehouse_id', 'item_contractors.warehouse_id')
                    ->whereColumn('existing_candidates.hub_warehouse_id', 'wcs.supply_warehouse_id')
                    ->whereColumn('existing_candidates.item_id', 'item_contractors.item_id')
                    ->whereColumn('existing_candidates.contractor_id', 'item_contractors.contractor_id');
            });
        }

        $this->salesBasedTransferPreviewRows = $query
            ->selectRaw('
                item_contractors.warehouse_id as satellite_warehouse_id,
                wcs.supply_warehouse_id as hub_warehouse_id,
                item_contractors.item_id as item_id,
                item_contractors.contractor_id as contractor_id,
                items.code as item_code,
                items.name as item_name,
                items.packaging as item_packaging,
                contractors.name as contractor_name,
                sales.sales_qty as sales_qty,
                sales.sales_piece_qty as sales_piece_qty,
                sales.return_piece_qty as return_piece_qty,
                sales.transfer_piece_qty as transfer_piece_qty,
                COALESCE(stocks.effective_stock, 0) as effective_stock,
                COALESCE(incoming.incoming_qty, 0) as incoming_qty,
                (COALESCE(stocks.effective_stock, 0) + COALESCE(incoming.incoming_qty, 0)) as projected_stock,
                GREATEST(sales.sales_qty - (COALESCE(stocks.effective_stock, 0) + COALESCE(incoming.incoming_qty, 0)), 0) as order_piece_qty,
                COALESCE(item_contractors.purchase_unit, 1) as purchase_unit
            ')
            ->orderBy('items.code')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'satellite_warehouse_id' => (int) $row->satellite_warehouse_id,
                'hub_warehouse_id' => (int) $row->hub_warehouse_id,
                'item_id' => (int) $row->item_id,
                'contractor_id' => (int) $row->contractor_id,
                'item_code' => (string) $row->item_code,
                'item_name' => (string) $row->item_name,
                'item_packaging' => (string) ($row->item_packaging ?? ''),
                'contractor_name' => (string) $row->contractor_name,
                'sales_qty' => (int) $row->sales_qty,
                'sales_piece_qty' => (int) $row->sales_piece_qty,
                'return_piece_qty' => (int) $row->return_piece_qty,
                'transfer_piece_qty' => (int) $row->transfer_piece_qty,
                'daily_avg_qty' => round(((int) $row->sales_qty) / $days, 2),
                'effective_stock' => (int) $row->effective_stock,
                'incoming_qty' => (int) $row->incoming_qty,
                'projected_stock' => (int) $row->projected_stock,
                'purchase_unit' => max(1, (int) $row->purchase_unit),
                'order_piece_qty' => (int) $row->order_piece_qty,
                'input_order_piece_qty' => null,
            ])
            ->toArray();

        if (empty($this->salesBasedTransferPreviewRows)) {
            $this->salesBasedTransferPreviewError = '現在の条件に該当する候補がありません。';
        }
    }

    public function updateSalesBasedTransferPreviewRows(array $rows): void
    {
        $this->salesBasedTransferPreviewRows = collect($rows)
            ->map(function (array $row): array {
                $inputQuantity = $row['input_order_piece_qty'] ?? null;
                $row['input_order_piece_qty'] = ($inputQuantity === null || $inputQuantity === '')
                    ? null
                    : max(0, (int) $inputQuantity);

                return $row;
            })
            ->values()
            ->toArray();
    }

    public function createSalesBasedTransferPreviewCandidates(): void
    {
        $userId = auth()->id();

        if (! $userId) {
            Notification::make()
                ->title('ログインユーザーを取得できませんでした')
                ->danger()
                ->send();

            return;
        }

        if (empty($this->salesBasedTransferPreviewRows)) {
            Notification::make()
                ->title('生成対象の候補がありません')
                ->warning()
                ->send();

            return;
        }

        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        if (! $selectedWarehouseId) {
            Notification::make()
                ->title('倉庫が選択されていません')
                ->danger()
                ->send();

            return;
        }

        $job = WmsAutoOrderJobControl::findPendingSettlementForWarehouse(
            $selectedWarehouseId,
            $userId,
            [JobProcessName::ORDER_CALC, JobProcessName::SALES_BASED_CALC]
        );

        if (! $job) {
            $job = WmsAutoOrderJobControl::startJob(
                processName: JobProcessName::SALES_BASED_CALC,
                createdBy: $userId,
                warehouseId: $selectedWarehouseId,
                batchCode: WmsAutoOrderJobControl::generateBatchCode($selectedWarehouseId),
            );
            $job->markAsSuccess(0);
        }

        $batchCode = $job->batch_code;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $blankSkipped = 0;
        $now = now();
        $expectedArrivalDate = $now->copy()->addDay()->toDateString();

        DB::connection('sakemaru')->transaction(function () use ($batchCode, $now, $expectedArrivalDate, $userId, &$created, &$updated, &$skipped, &$blankSkipped): void {
            foreach ($this->salesBasedTransferPreviewRows as $row) {
                $itemId = (int) ($row['item_id'] ?? 0);
                $satelliteWarehouseId = (int) ($row['satellite_warehouse_id'] ?? 0);
                $hubWarehouseId = (int) ($row['hub_warehouse_id'] ?? self::SALES_BASED_TRANSFER_HUB_WAREHOUSE_ID);
                $contractorId = (int) ($row['contractor_id'] ?? 0);

                if ($itemId < 1 || $satelliteWarehouseId < 1 || $hubWarehouseId < 1 || $contractorId < 1) {
                    $skipped++;

                    continue;
                }

                $inputQuantity = $row['input_order_piece_qty'] ?? null;
                if ($inputQuantity === null || $inputQuantity === '') {
                    $blankSkipped++;

                    continue;
                }

                $quantity = max(0, (int) $inputQuantity);
                $existingCandidate = WmsStockTransferCandidate::query()
                    ->where('satellite_warehouse_id', $satelliteWarehouseId)
                    ->where('hub_warehouse_id', $hubWarehouseId)
                    ->where('item_id', $itemId)
                    ->where('contractor_id', $contractorId)
                    ->where('status', CandidateStatus::PENDING)
                    ->forCreatedBy($userId)
                    ->first();

                $searchCode = DB::connection('sakemaru')
                    ->table('item_search_information')
                    ->where('item_id', $itemId)
                    ->where('is_used_for_ordering', true)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('search_string');

                $candidateData = [
                    'batch_code' => $batchCode,
                    'satellite_warehouse_id' => $satelliteWarehouseId,
                    'hub_warehouse_id' => $hubWarehouseId,
                    'item_id' => $itemId,
                    'item_code' => $row['item_code'] ?? null,
                    'search_code' => $searchCode,
                    'ordering_code' => $searchCode ? str_pad((string) $searchCode, 13, '0', STR_PAD_LEFT) : null,
                    'contractor_id' => $contractorId,
                    'suggested_quantity' => $quantity,
                    'transfer_quantity' => $quantity,
                    'current_effective_stock' => (int) ($row['effective_stock'] ?? 0),
                    'incoming_quantity' => (int) ($row['incoming_qty'] ?? 0),
                    'calculated_available' => (int) ($row['projected_stock'] ?? 0),
                    'shortage_qty' => max(0, (int) ($row['order_piece_qty'] ?? 0)),
                    'purchase_unit' => max(1, (int) ($row['purchase_unit'] ?? 1)),
                    'quantity_type' => QuantityType::PIECE,
                    'expected_arrival_date' => $expectedArrivalDate,
                    'original_arrival_date' => $expectedArrivalDate,
                    'status' => CandidateStatus::PENDING,
                    'lot_status' => LotStatus::RAW,
                    'origin_type' => OriginType::MANUAL_SALES_BASED,
                    'is_manually_modified' => true,
                    'modified_by' => $userId,
                    'modified_at' => $now,
                ];

                if ($existingCandidate) {
                    $existingCandidate->update($candidateData);
                    $updated++;
                } else {
                    WmsStockTransferCandidate::create($candidateData);
                    $created++;
                }

                WmsOrderCalculationLog::create([
                    'batch_code' => $batchCode,
                    'warehouse_id' => $satelliteWarehouseId,
                    'item_id' => $itemId,
                    'calculation_type' => CalculationType::INTERNAL,
                    'contractor_id' => $contractorId,
                    'source_warehouse_id' => $hubWarehouseId,
                    'current_effective_stock' => (int) ($row['effective_stock'] ?? 0),
                    'incoming_quantity' => (int) ($row['incoming_qty'] ?? 0),
                    'safety_stock_setting' => 0,
                    'lead_time_days' => 1,
                    'calculated_shortage_qty' => max(0, (int) ($row['order_piece_qty'] ?? 0)),
                    'calculated_order_quantity' => $quantity,
                    'calculation_details' => [
                        'source' => 'sales_based_transfer_preview',
                        'sales_start_date' => $this->salesBasedTransferPreviewConditions['sales_start_date'] ?? null,
                        'sales_end_date' => $this->salesBasedTransferPreviewConditions['sales_end_date'] ?? null,
                        'sales_qty' => (int) ($row['sales_qty'] ?? 0),
                        'sales_piece_qty' => (int) ($row['sales_piece_qty'] ?? 0),
                        'return_piece_qty' => (int) ($row['return_piece_qty'] ?? 0),
                        'transfer_piece_qty' => (int) ($row['transfer_piece_qty'] ?? 0),
                        'input_blank_as_zero' => false,
                        'expected_arrival_date' => $expectedArrivalDate,
                        'created_by' => $userId,
                    ],
                ]);

            }
        });

        $title = $updated > 0
            ? "物流発注候補を {$created}件 生成、{$updated}件 更新しました"
            : "物流発注候補を {$created}件 生成しました";

        Notification::make()
            ->title($title)
            ->body(collect([
                $blankSkipped > 0 ? "未入力の候補 {$blankSkipped}件 は生成しませんでした。" : null,
                $skipped > 0 ? "不正な候補など {$skipped}件 はスキップしました。" : null,
            ])->filter()->implode("\n") ?: null)
            ->success()
            ->send();

        $this->resetSalesBasedTransferPreview();
        $this->dispatch('$refresh');
    }

    private function getSalesBasedTransferGenerationWarehouseIds(int $warehouseId): array
    {
        $enabledWarehouseIds = WmsWarehouseAutoOrderSetting::enabled()
            ->pluck('warehouse_id')
            ->toArray();

        $satelliteWarehouseIds = DB::connection('sakemaru')
            ->table('item_contractors')
            ->join('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'item_contractors.contractor_id')
            ->where('wcs.transmission_type', TransmissionType::INTERNAL->value)
            ->where('wcs.supply_warehouse_id', $warehouseId)
            ->pluck('item_contractors.warehouse_id')
            ->unique()
            ->toArray();

        return array_values(array_intersect(
            $enabledWarehouseIds,
            array_values(array_unique(array_merge([$warehouseId], $satelliteWarehouseIds))),
        ));
    }

    private function getMountedSalesBasedTransferActionData(): array
    {
        $mountedActionKey = array_key_last($this->mountedActions ?? []);

        if ($mountedActionKey === null) {
            return [];
        }

        return $this->mountedActions[$mountedActionKey]['data'] ?? [];
    }

    private function createTransferOrderCandidates(array $data, array $items, int &$created, array &$errors): void
    {
        $userId = auth()->id();

        if (! $userId) {
            $errors[] = 'ログインユーザーを取得できませんでした';

            return;
        }

        // 同日・同倉庫のPENDINGジョブを再利用、なければ新規作成
        $satelliteWarehouseId = $data['satellite_warehouse_id'];
        $existingJob = WmsAutoOrderJobControl::where('process_name', JobProcessName::ORDER_CALC)
            ->where('settlement_status', SettlementStatus::PENDING)
            ->where('created_by', $userId)
            ->where('warehouse_id', $satelliteWarehouseId)
            ->whereDate('started_at', today())
            ->orderByDesc('id')
            ->first();

        if ($existingJob) {
            $batchCode = $existingJob->batch_code;
        } else {
            $newJob = WmsAutoOrderJobControl::startJob(
                processName: JobProcessName::ORDER_CALC,
                createdBy: $userId,
                warehouseId: $satelliteWarehouseId,
                batchCode: WmsAutoOrderJobControl::generateBatchCode($satelliteWarehouseId),
            );
            $batchCode = $newJob->batch_code;
            $newJob->markAsSuccess(0);
        }

        foreach ($items as $itemData) {
            $itemId = $itemData['item_id'];
            $totalPieceQty = (int) ($itemData['quantity'] ?? 0);
            $itemCode = $itemData['item_code'] ?? null;
            $searchCode = $itemData['search_code'] ?? null;

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
                ->forCreatedBy($userId)
                ->exists();

            if ($exists) {
                $errors[] = "[{$itemCode}] {$item->name}: 既に存在";

                continue;
            }

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
                'modified_by' => $userId,
                'modified_at' => now(),
            ];

            WmsStockTransferCandidate::create(array_merge($commonFields, [
                'suggested_quantity' => $totalPieceQty,
                'transfer_quantity' => $totalPieceQty,
                'quantity_type' => QuantityType::PIECE,
            ]));
            $created++;

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
                    'created_by' => $userId,
                    'created_at' => now()->toDateTimeString(),
                    'formula' => "手動追加（バラ:{$totalPieceQty}）",
                ],
            ]);
        }

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
                ->orderBy('batch_code', 'desc')
                ->orderBy('satellite_warehouse_id')
                ->orderBy('item_id')
            );
    }

    private function getTransferQuantityInputRecords()
    {
        $records = $this->getFilteredSortedTableQuery()
            ->with(['item', 'contractor'])
            ->get();

        WmsStockTransferCandidate::preloadCalculationLogs($records);

        return $records;
    }

    private function buildTransferQuantityInputRows($records): array
    {
        return $records
            ->map(function (WmsStockTransferCandidate $record): array {
                $log = $record->calculationLog;
                $details = $log?->calculation_details ?? [];
                $salesQty = (int) ($details['sales_qty'] ?? $details['実績合計'] ?? $record->suggested_quantity ?? 0);
                $days = 0;

                if (! empty($details['sales_start_date']) && ! empty($details['sales_end_date'])) {
                    try {
                        $days = max(1, \Carbon\Carbon::parse($details['sales_start_date'])
                            ->diffInDays(\Carbon\Carbon::parse($details['sales_end_date'])) + 1);
                    } catch (\Throwable) {
                        $days = 0;
                    }
                }

                return [
                    'id' => $record->id,
                    'item_code' => $record->item_code ?? '-',
                    'item_name' => $record->item?->name ?? '-',
                    'item_packaging' => $record->item?->packaging ?? '-',
                    'contractor_name' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                    'sales_qty' => $salesQty,
                    'sales_piece_qty' => (int) ($details['sales_piece_qty'] ?? $details['販売'] ?? 0),
                    'return_piece_qty' => (int) ($details['return_piece_qty'] ?? $details['返品'] ?? 0),
                    'transfer_piece_qty' => (int) ($details['transfer_piece_qty'] ?? $details['移動'] ?? 0),
                    'daily_avg_qty' => (float) ($details['daily_avg_qty'] ?? $details['日平均'] ?? ($days > 0 ? round($salesQty / $days, 2) : 0)),
                    'effective_stock' => (int) ($record->current_effective_stock ?? $log?->current_effective_stock ?? 0),
                    'incoming_qty' => (int) ($record->incoming_quantity ?? $log?->incoming_quantity ?? 0),
                    'expected_arrival_date' => $record->expected_arrival_date?->format('m/d') ?? '-',
                    'projected_stock' => (int) ($record->calculated_available ?? ($details['利用可能在庫'] ?? 0)),
                    'transfer_quantity' => (int) ($record->transfer_quantity ?? 0),
                    'disabled' => $record->status !== CandidateStatus::PENDING,
                ];
            })
            ->values()
            ->toArray();
    }

    public function getSalesBasedTransferCategory2ForGeneration(): array
    {
        return ItemCategory::query()
            ->where('is_active', true)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('items')
                    ->whereColumn('items.item_category2_id', 'item_categories.id')
                    ->where('items.end_of_sale_type', 'NORMAL')
                    ->where('items.is_ended', false);
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (ItemCategory $category) => [
                'id' => (int) $category->id,
                'code' => (string) $category->code,
                'name' => (string) $category->name,
            ])
            ->values()
            ->toArray();
    }

    private function initializeSalesBasedTransferCategory2(): void
    {
        $this->salesBasedTransferCategory2Data = $this->getSalesBasedTransferCategory2ForGeneration();
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

        $cacheKey = 'transfer_candidates_pending_warehouses_all';
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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $userDefaultWarehouseId))
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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
