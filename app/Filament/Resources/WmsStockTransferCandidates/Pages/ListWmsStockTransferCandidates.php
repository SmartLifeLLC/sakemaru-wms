<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\StockSnapshotService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveAll')
                ->label('全て承認')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
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

            Action::make('create')
                ->label('移動追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('移動候補を追加')
                ->modalWidth('lg')
                ->schema([
                    Select::make('satellite_warehouse_id')
                        ->label('依頼倉庫')
                        ->options(fn () => Warehouse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('hub_warehouse_id')
                        ->label('移動元倉庫')
                        ->options(fn () => Warehouse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('item_id')
                        ->label('商品')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(function (string $search): array {
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
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(function ($item) {
                                    $jan = $item->piece_jan_code_information?->search_string;
                                    $label = "[{$item->code}] {$item->name}";
                                    if ($jan) {
                                        $label .= " ({$jan})";
                                    }

                                    return [$item->id => $label];
                                })
                                ->toArray();
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            $item = Item::with('piece_jan_code_information')->find($value);
                            if (! $item) {
                                return null;
                            }
                            $jan = $item->piece_jan_code_information?->search_string;
                            $label = "[{$item->code}] {$item->name}";
                            if ($jan) {
                                $label .= " ({$jan})";
                            }

                            return $label;
                        }),

                    TextInput::make('transfer_quantity')
                        ->label('移動数')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    DatePicker::make('expected_arrival_date')
                        ->label('移動出荷日')
                        ->default(now()->addDay())
                        ->required(),

                    Select::make('delivery_course_id')
                        ->label('配送コース')
                        ->options(fn () => DeliveryCourse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"]))
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    // 依頼倉庫と移動元倉庫が同じ場合はエラー
                    if ($data['satellite_warehouse_id'] === $data['hub_warehouse_id']) {
                        Notification::make()
                            ->title('エラー')
                            ->body('依頼倉庫と移動元倉庫を同じにすることはできません')
                            ->danger()
                            ->send();

                        return;
                    }

                    // 販売終了品チェック
                    $item = Item::find($data['item_id']);
                    if ($item && $item->end_of_sale_type !== 'NORMAL') {
                        Notification::make()
                            ->title('エラー')
                            ->body('販売終了品のため移動対象外です')
                            ->danger()
                            ->send();

                        return;
                    }

                    // 確定待ち（PENDING）のジョブを検索し、あればそのbatch_codeを使用
                    // なければ在庫スナップショットを新規生成
                    $pendingJob = WmsAutoOrderJobControl::findPendingSettlement();
                    if ($pendingJob) {
                        $batchCode = $pendingJob->batch_code;
                    } else {
                        // 新規スナップショットを生成（ジョブ管理も自動作成される）
                        $snapshotService = app(StockSnapshotService::class);
                        $snapshotJob = $snapshotService->generateAll();
                        $batchCode = $snapshotJob->batch_code;
                    }

                    // 同じ倉庫・商品の組み合わせが既に存在するかチェック
                    $exists = WmsStockTransferCandidate::where('satellite_warehouse_id', $data['satellite_warehouse_id'])
                        ->where('hub_warehouse_id', $data['hub_warehouse_id'])
                        ->where('item_id', $data['item_id'])
                        ->where('status', CandidateStatus::PENDING)
                        ->exists();

                    if ($exists) {
                        Notification::make()
                            ->title('エラー')
                            ->body('この倉庫・商品の組み合わせは既に移動候補に存在します')
                            ->danger()
                            ->send();

                        return;
                    }

                    // 移動候補を作成
                    WmsStockTransferCandidate::create([
                        'batch_code' => $batchCode,
                        'satellite_warehouse_id' => $data['satellite_warehouse_id'],
                        'hub_warehouse_id' => $data['hub_warehouse_id'],
                        'item_id' => $data['item_id'],
                        'contractor_id' => null,
                        'delivery_course_id' => $data['delivery_course_id'] ?? null,
                        'suggested_quantity' => $data['transfer_quantity'],
                        'transfer_quantity' => $data['transfer_quantity'],
                        'expected_arrival_date' => $data['expected_arrival_date'],
                        'original_arrival_date' => $data['expected_arrival_date'],
                        'status' => CandidateStatus::PENDING,
                        'lot_status' => LotStatus::RAW,
                        'is_manually_modified' => true,
                        'modified_by' => auth()->id(),
                        'modified_at' => now(),
                    ]);

                    // 計算ログを作成（手動追加として記録）
                    WmsOrderCalculationLog::create([
                        'batch_code' => $batchCode,
                        'warehouse_id' => $data['satellite_warehouse_id'],
                        'item_id' => $data['item_id'],
                        'calculation_type' => CalculationType::INTERNAL,
                        'contractor_id' => null,
                        'source_warehouse_id' => $data['hub_warehouse_id'],
                        'current_effective_stock' => 0,
                        'incoming_quantity' => 0,
                        'safety_stock_setting' => 0,
                        'lead_time_days' => 1,
                        'calculated_shortage_qty' => $data['transfer_quantity'],
                        'calculated_order_quantity' => $data['transfer_quantity'],
                        'calculation_details' => [
                            'manual_entry' => true,
                            'created_by' => auth()->id(),
                            'created_at' => now()->toDateTimeString(),
                            'formula' => '手動追加',
                        ],
                    ]);

                    Notification::make()
                        ->title('移動候補を追加しました')
                        ->success()
                        ->send();
                }),
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

            // 移動元倉庫（hub）の在庫を一括プリロード（N+1対策）
            $hubWarehouseIds = $items->pluck('hub_warehouse_id')->unique()->values()->toArray();
            $itemIds = $items->pluck('item_id')->unique()->values()->toArray();

            if (! empty($hubWarehouseIds) && ! empty($itemIds)) {
                $hubStocks = DB::connection('sakemaru')
                    ->table('wms_item_stock_snapshots')
                    ->whereIn('warehouse_id', $hubWarehouseIds)
                    ->whereIn('item_id', $itemIds)
                    ->select('warehouse_id', 'item_id', 'total_effective_piece')
                    ->get()
                    ->keyBy(fn ($row) => "{$row->warehouse_id}_{$row->item_id}");

                $items->each(function ($candidate) use ($hubStocks) {
                    $key = "{$candidate->hub_warehouse_id}_{$candidate->item_id}";
                    $candidate->hub_effective_stock = isset($hubStocks[$key])
                        ? (int) $hubStocks[$key]->total_effective_piece
                        : null;
                });
            }
        }

        return $paginator;
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // PENDING の移動候補に存在する依頼倉庫（satellite_warehouse）のみ取得
        $cacheKey = 'transfer_candidates_pending_warehouses_'.auth()->id();
        $warehouseIds = cache()->remember($cacheKey, 30, function () {
            return WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                ->distinct()
                ->pluck('satellite_warehouse_id')
                ->toArray();
        });
        $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('name')->get();

        // デフォルト倉庫が移動候補に存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? Warehouse::find($userDefaultWarehouseId) : null;

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
