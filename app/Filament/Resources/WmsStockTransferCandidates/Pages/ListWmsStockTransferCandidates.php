<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\SettlementStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Enums\QueueProgressStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsStockTransferCandidates\Tables\WmsStockTransferCandidatesTable;
use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use App\Jobs\ProcessSalesBasedOrderCandidateJob;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\StatsItemWarehouseSalesSummary;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
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

    public array $transferOrderItems = [];

    public array $transferQuantityInputPayload = [];

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
                ->modalSubmitAction(fn (Action $action) => $action->label('追加する')->color('danger'))
                ->modalCancelActionLabel('変更せず閉じる')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->schema([
                    Grid::make(12)->schema([
                        Placeholder::make('satellite_warehouse_label')
                            ->hiddenLabel()
                            ->content('依頼倉庫 :')
                            ->extraAttributes(['class' => 'flex h-full items-center justify-end text-sm font-semibold text-gray-700 dark:text-gray-300'])
                            ->columnSpan(1),

                        Select::make('satellite_warehouse_id')
                            ->hiddenLabel()
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                            ->default(fn () => auth()->user()?->getSelectedWarehouseId())
                            ->searchable()
                            ->required()
                            ->columnSpan(5),

                        Placeholder::make('hub_warehouse_label')
                            ->hiddenLabel()
                            ->content('移動元倉庫 :')
                            ->extraAttributes(['class' => 'flex h-full items-center justify-end text-sm font-semibold text-gray-700 dark:text-gray-300'])
                            ->columnSpan(1),

                        Select::make('hub_warehouse_id')
                            ->hiddenLabel()
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                            ->default(fn () => WmsContractorSetting::where('transmission_type', TransmissionType::INTERNAL)
                                ->whereNotNull('supply_warehouse_id')
                                ->orderBy('supply_warehouse_id')
                                ->value('supply_warehouse_id'))
                            ->searchable()
                            ->required()
                            ->columnSpan(5),

                        Placeholder::make('expected_arrival_date_label')
                            ->hiddenLabel()
                            ->content('入荷予定日 :')
                            ->extraAttributes(['class' => 'flex h-full items-center justify-end text-sm font-semibold text-gray-700 dark:text-gray-300'])
                            ->columnSpan(1),

                        DatePicker::make('expected_arrival_date')
                            ->hiddenLabel()
                            ->default(now()->addDay())
                            ->required()
                            ->columnSpan(5),

                        Placeholder::make('delivery_course_label')
                            ->hiddenLabel()
                            ->content('配送コース :')
                            ->extraAttributes(['class' => 'flex h-full items-center justify-end text-sm font-semibold text-gray-700 dark:text-gray-300'])
                            ->columnSpan(1),

                        Select::make('delivery_course_id')
                            ->hiddenLabel()
                            ->options(fn () => DeliveryCourse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"]))
                            ->searchable()
                            ->columnSpan(5),
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

            $this->getSalesBasedTransferGenerateAction(),

            Action::make('transferQuantityInput')
                ->label('発注数量編集')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->modalHeading('発注数量編集')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn (Action $action) => $action->label('一括保存')->color('danger'))
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
        $isHubWarehouse = $selectedWarehouseId
            ? WmsContractorSetting::where('transmission_type', TransmissionType::INTERNAL)
                ->where('supply_warehouse_id', $selectedWarehouseId)
                ->exists()
            : false;

        $baseDescription = match (true) {
            ! $selectedWarehouse => '倉庫が選択されていません。トップバーからHUB倉庫を選択してください。',
            ! $isHubWarehouse => "倉庫「{$selectedWarehouseName}」はHUB倉庫ではありません。HUB倉庫のみ実行できます。",
            default => "HUB倉庫「{$selectedWarehouseName}」向けの実績ベース移動候補を生成します。対象はこのHUB倉庫から供給するサテライト倉庫です。発注候補は生成しません。",
        };

        return Action::make('generateSalesBasedTransfer')
            ->label('実績ベース転換移動候補生成')
            ->icon('heroicon-o-chart-bar')
            ->color('info')
            ->modalWidth('5xl')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalHeading("実績ベース転換移動候補生成（{$selectedWarehouseName}）")
            ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
            ->modalSubmitAction(fn (Action $action) => $action->label('生成開始')->color('danger'))
            ->modalCancelActionLabel('生成せず閉じる')
            ->disabled(! $selectedWarehouse || ! $isHubWarehouse)
            ->schema([
                \Filament\Forms\Components\Placeholder::make('description')
                    ->hiddenLabel()
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div class="rounded-lg border border-slate-300 bg-slate-100 px-4 py-3 text-sm font-medium leading-6 text-slate-800 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">'
                        .nl2br(e($baseDescription))
                        .'</div>'
                    )),
                Grid::make(3)->schema([
                    Select::make('sales_basis')
                        ->label('販売実績')
                        ->options([
                            'today' => '当日',
                            'yesterday' => '前日',
                            'last_3d' => '3日間',
                        ])
                        ->default('last_3d')
                        ->native(false)
                        ->required(),
                    Select::make('auto_order_flag_filter')
                        ->label('自動発注フラグ')
                        ->options([
                            'ignore' => '考慮しない',
                            'on' => 'ONのもの',
                            'off' => 'OFFのもの',
                        ])
                        ->default('ignore')
                        ->native(false)
                        ->required(),
                    \Filament\Forms\Components\Placeholder::make('target_notice')
                        ->label('対象')
                        ->content('HUB倉庫配下のINTERNAL移動候補のみ'),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($selectedWarehouseId, $selectedWarehouseName) {
                if ($activeJob = $this->findActiveSalesBasedTransferQueueProgress($selectedWarehouseId)) {
                    Notification::make()
                        ->title('実績ベース移動候補生成が実行中です')
                        ->body('重複生成を避けるため、新しいジョブは投入しません。完了後に再実行してください。')
                        ->warning()
                        ->send();

                    $action->halt();
                }

                $internalContractorIds = WmsContractorSetting::query()
                    ->where('transmission_type', TransmissionType::INTERNAL)
                    ->where('supply_warehouse_id', $selectedWarehouseId)
                    ->pluck('contractor_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->toArray();

                if (empty($internalContractorIds)) {
                    Notification::make()
                        ->title('対象のINTERNAL仕入先がありません')
                        ->danger()
                        ->send();

                    $action->halt();
                }

                $hasApprovedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->forCreatedBy(auth()->id())
                    ->where('hub_warehouse_id', $selectedWarehouseId)
                    ->whereIn('contractor_id', $internalContractorIds)
                    ->exists();

                if ($hasApprovedTransfers) {
                    Notification::make()
                        ->title('承認済みの移動候補があります')
                        ->body('先に確定処理を行ってください')
                        ->danger()
                        ->send();

                    $action->halt();
                }

                $deletedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->forCreatedBy(auth()->id())
                    ->where('hub_warehouse_id', $selectedWarehouseId)
                    ->whereIn('contractor_id', $internalContractorIds)
                    ->delete();

                $existingJob = WmsAutoOrderJobControl::findPendingSettlementForWarehouse($selectedWarehouseId, auth()->id());
                $batchCode = $existingJob?->batch_code;

                $queueProgress = WmsQueueProgress::createJob(
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    auth()->id(),
                    [
                        'warehouse_id' => $selectedWarehouseId,
                        'contractor_ids' => $internalContractorIds,
                        'source' => 'sales_based_transfer',
                        'transfer_only' => true,
                        'sales_basis' => $data['sales_basis'] ?? 'last_3d',
                        'auto_order_flag_filter' => $data['auto_order_flag_filter'] ?? 'ignore',
                    ]
                );

                ProcessSalesBasedOrderCandidateJob::dispatch(
                    jobId: $queueProgress->job_id,
                    warehouseId: $selectedWarehouseId,
                    createdBy: auth()->id(),
                    contractorIds: $internalContractorIds,
                    batchCode: $batchCode,
                    originType: \App\Enums\AutoOrder\OriginType::MANUAL_SALES_BASED->value,
                    salesBasis: $data['sales_basis'] ?? 'last_3d',
                    orderPointFilter: 'ignore',
                    autoOrderFlagFilter: $data['auto_order_flag_filter'] ?? 'ignore',
                    transferOnly: true,
                );

                $message = "HUB倉庫「{$selectedWarehouseName}」の実績ベース移動候補生成を開始しました";
                if ($deletedTransfers > 0) {
                    $message .= "（既存PENDING移動候補 {$deletedTransfers}件 を削除）";
                }
                if ($batchCode) {
                    $message .= "（既存バッチ{$batchCode}に追加）";
                }

                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            });
    }

    private function findActiveSalesBasedTransferQueueProgress(?int $warehouseId): ?WmsQueueProgress
    {
        return WmsQueueProgress::query()
            ->where('job_type', WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION)
            ->whereIn('status', [QueueProgressStatus::PENDING, QueueProgressStatus::PROCESSING])
            ->where('metadata->source', 'sales_based_transfer')
            ->where('metadata->warehouse_id', $warehouseId)
            ->latest()
            ->first();
    }

    private function createTransferOrderCandidates(array $data, array $items, int &$created, array &$errors): void
    {
        // 同日・同倉庫のPENDINGジョブを再利用、なければ新規作成
        $satelliteWarehouseId = $data['satellite_warehouse_id'];
        $existingJob = WmsAutoOrderJobControl::where('process_name', JobProcessName::ORDER_CALC)
            ->where('settlement_status', SettlementStatus::PENDING)
            ->where('created_by', auth()->id())
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
                ->forCreatedBy(auth()->id())
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
                'modified_by' => auth()->id(),
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
                    'created_by' => auth()->id(),
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

                return [
                    'id' => $record->id,
                    'item_code' => $record->item_code ?? '-',
                    'item_name' => $record->item?->name ?? '-',
                    'packaging' => $record->item?->packaging ?? '-',
                    'contractor_name' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                    'calculated_available' => (int) ($record->calculated_available ?? ($details['利用可能在庫'] ?? 0)),
                    'safety_stock' => (int) ($record->safety_stock ?? $log?->safety_stock_setting ?? 0),
                    'auto_order_quantity' => (int) ($details['旧自動発注数'] ?? 0),
                    'shortage_qty' => (int) ($details['不足数'] ?? $record->suggested_quantity ?? 0),
                    'transfer_quantity' => (int) ($record->transfer_quantity ?? 0),
                    'disabled' => $record->status !== CandidateStatus::PENDING,
                ];
            })
            ->values()
            ->toArray();
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
