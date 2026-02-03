<?php

namespace App\Filament\Resources\WmsOrderCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\EVolumeUnit;
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
use App\Services\AutoOrder\ContractorLeadTimeService;
use App\Services\AutoOrder\StockSnapshotService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListWmsOrderCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderCandidateResource::class;

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
                ->modalWidth('lg')
                ->form([
                    Select::make('warehouse_id')
                        ->label('発注倉庫')
                        ->options(fn () => Warehouse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($set, $get, $state) {
                            // 数量をリセット
                            $set('case_qty', null);
                            $set('piece_qty', null);
                            $set('total_pieces', 0);
                            $set('piece_qty_error', null);
                            $set('contractor_error', null);

                            if (! $state) {
                                $set('incoming_warehouse_html', null);
                                $set('incoming_warehouse_id', null);

                                return;
                            }
                            $warehouse = Warehouse::find($state);
                            if (! $warehouse) {
                                $set('incoming_warehouse_html', null);
                                $set('incoming_warehouse_id', null);

                                return;
                            }
                            // 仮想倉庫の場合、実倉庫（stock_warehouse_id）を取得
                            $incomingWarehouse = $warehouse;
                            if ($warehouse->is_virtual && $warehouse->stock_warehouse_id) {
                                $incomingWarehouse = Warehouse::find($warehouse->stock_warehouse_id) ?? $warehouse;
                            }
                            $set('incoming_warehouse_id', $incomingWarehouse->id);
                            $set('incoming_warehouse_html', "[{$incomingWarehouse->code}]{$incomingWarehouse->name}");

                            // 商品が既に選択されている場合、発注先設定をチェック＆更新
                            $itemId = $get('item_id');
                            if ($itemId) {
                                $item = Item::with('piece_jan_code_information')->find($itemId);
                                $itemContractor = ItemContractor::where('warehouse_id', $incomingWarehouse->id)
                                    ->where('item_id', $itemId)
                                    ->first();

                                $contractorCode = null;
                                $contractorName = null;
                                if ($itemContractor) {
                                    $contractor = Contractor::find($itemContractor->contractor_id);
                                    if ($contractor) {
                                        $contractorCode = $contractor->code;
                                        $contractorName = $contractor->name;
                                    }
                                } else {
                                    $set('contractor_error', "入庫倉庫「[{$incomingWarehouse->code}]{$incomingWarehouse->name}」に対する発注先が設定されていません");
                                }

                                // 商品詳細を更新（発注先情報含む）
                                if ($item) {
                                    $capacityCase = $item->capacity_case ?? 1;
                                    $volumeUnit = EVolumeUnit::tryFrom($item->volume_unit);
                                    $volume = $item->volume && $volumeUnit
                                        ? "{$item->volume}{$volumeUnit->name()}"
                                        : '-';
                                    $jan = $item->piece_jan_code_information?->search_string ?? '-';
                                    $set('item_details_html', json_encode([
                                        'code' => $item->code,
                                        'jan' => $jan,
                                        'capacity' => $capacityCase,
                                        'volume' => $volume,
                                        'contractor_code' => $contractorCode,
                                        'contractor_name' => $contractorName,
                                    ]));
                                }
                            }
                        }),

                    Placeholder::make('incoming_warehouse_info')
                        ->label('入庫倉庫')
                        ->content(function ($get) {
                            $html = $get('incoming_warehouse_html');
                            if (! $html) {
                                return new HtmlString("<span class='text-gray-400'>発注倉庫を選択してください</span>");
                            }

                            return new HtmlString("<span class='font-bold text-blue-600'>{$html}</span>");
                        }),

                    TextInput::make('incoming_warehouse_html')->hidden()->dehydrated(false),
                    TextInput::make('incoming_warehouse_id')->hidden()->numeric()->dehydrated(true),

                    Select::make('item_id')
                        ->label('商品')
                        ->searchable()
                        ->required()
                        ->live()
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
                        })
                        ->afterStateUpdated(function ($set, $get, $state) {
                            // 数量をリセット
                            $set('case_qty', null);
                            $set('piece_qty', null);
                            $set('total_pieces', 0);
                            $set('piece_qty_error', null);
                            $set('contractor_error', null);

                            if (! $state) {
                                $set('item_details_html', null);
                                $set('capacity_case', 1);

                                return;
                            }
                            $item = Item::with('piece_jan_code_information')->find($state);
                            if (! $item) {
                                $set('item_details_html', null);
                                $set('capacity_case', 1);

                                return;
                            }
                            $capacityCase = $item->capacity_case ?? 1;
                            $volumeUnit = EVolumeUnit::tryFrom($item->volume_unit);
                            $volume = $item->volume && $volumeUnit
                                ? "{$item->volume}{$volumeUnit->name()}"
                                : '-';
                            $jan = $item->piece_jan_code_information?->search_string ?? '-';
                            $set('capacity_case', $capacityCase);

                            // 発注先設定チェック
                            $incomingWarehouseId = $get('incoming_warehouse_id');
                            $contractorCode = null;
                            $contractorName = null;
                            if ($incomingWarehouseId) {
                                $itemContractor = ItemContractor::where('warehouse_id', $incomingWarehouseId)
                                    ->where('item_id', $state)
                                    ->first();
                                if ($itemContractor) {
                                    $contractor = Contractor::find($itemContractor->contractor_id);
                                    if ($contractor) {
                                        $contractorCode = $contractor->code;
                                        $contractorName = $contractor->name;
                                    }
                                } else {
                                    $incomingWarehouse = Warehouse::find($incomingWarehouseId);
                                    $warehouseName = $incomingWarehouse ? "[{$incomingWarehouse->code}]{$incomingWarehouse->name}" : "ID:{$incomingWarehouseId}";
                                    $set('contractor_error', "入庫倉庫「{$warehouseName}」に対する発注先が設定されていません");
                                }
                            }

                            $set('item_details_html', json_encode([
                                'code' => $item->code,
                                'jan' => $jan,
                                'capacity' => $capacityCase,
                                'volume' => $volume,
                                'contractor_code' => $contractorCode,
                                'contractor_name' => $contractorName,
                            ]));
                        }),

                    Placeholder::make('item_details_display')
                        ->label('商品詳細')
                        ->content(function ($get) {
                            $json = $get('item_details_html');
                            if (! $json) {
                                return new HtmlString("<span class='text-gray-400'>商品を選択してください</span>");
                            }
                            $data = json_decode($json, true);
                            if (! $data) {
                                return new HtmlString("<span class='text-gray-400'>商品を選択してください</span>");
                            }
                            $contractorDisplay = '-';
                            if (! empty($data['contractor_code']) && ! empty($data['contractor_name'])) {
                                $contractorDisplay = "[{$data['contractor_code']}] {$data['contractor_name']}";
                            }

                            return new HtmlString("
                                <div class='grid grid-cols-2 gap-x-4 gap-y-1 text-sm'>
                                    <div><span class='text-gray-500'>商品コード:</span> <span class='font-medium'>{$data['code']}</span></div>
                                    <div><span class='text-gray-500'>JANコード:</span> <span class='font-medium'>{$data['jan']}</span></div>
                                    <div><span class='text-gray-500'>入数:</span> <span class='font-bold text-blue-600'>{$data['capacity']}</span></div>
                                    <div><span class='text-gray-500'>容量:</span> <span class='font-medium'>{$data['volume']}</span></div>
                                    <div class='col-span-2 mt-1 pt-1 border-t border-gray-200 dark:border-gray-700'>
                                        <span class='text-gray-500'>発注先:</span> <span class='font-medium text-green-600'>{$contractorDisplay}</span>
                                    </div>
                                </div>
                            ");
                        }),

                    TextInput::make('item_details_html')->hidden()->dehydrated(false),
                    TextInput::make('capacity_case')->hidden()->default(1),
                    TextInput::make('contractor_error')->hidden()->dehydrated(false),

                    Placeholder::make('contractor_error_display')
                        ->label('')
                        ->content(function ($get) {
                            $error = $get('contractor_error');
                            if (! $error) {
                                return '';
                            }

                            return new HtmlString("
                                <div class='p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800'>
                                    <div class='flex items-center gap-2'>
                                        <svg class='w-5 h-5 text-red-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'>
                                            <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'></path>
                                        </svg>
                                        <span class='text-sm font-medium text-red-700 dark:text-red-300'>{$error}</span>
                                    </div>
                                </div>
                            ");
                        })
                        ->hidden(fn ($get) => ! $get('contractor_error')),

                    Grid::make(3)
                        ->schema([
                            TextInput::make('case_qty')
                                ->label('ケース数')
                                ->numeric()
                                ->minValue(1)
                                ->live()
                                ->afterStateUpdated(function ($set, $get, $state) {
                                    if ($state !== null && $state !== '') {
                                        $set('piece_qty', null);
                                        $set('piece_qty_error', null);
                                        $capacityCase = (int) ($get('capacity_case') ?? 1);
                                        $set('total_pieces', (int) $state * $capacityCase);
                                    } else {
                                        $set('total_pieces', 0);
                                    }
                                }),

                            TextInput::make('piece_qty')
                                ->label('バラ数')
                                ->numeric()
                                ->minValue(1)
                                ->live()
                                ->rules([
                                    fn ($get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                        if ($value === null || $value === '') {
                                            return;
                                        }
                                        $capacityCase = (int) ($get('capacity_case') ?? 1);
                                        if ((int) $value % $capacityCase !== 0) {
                                            $fail("入数({$capacityCase})の倍数で入力してください");
                                        }
                                    },
                                ])
                                ->afterStateUpdated(function ($set, $get, $state) {
                                    if ($state !== null && $state !== '') {
                                        $set('case_qty', null);
                                        $capacityCase = (int) ($get('capacity_case') ?? 1);
                                        $pieceQty = (int) $state;
                                        $set('total_pieces', $pieceQty);
                                        // バリデーション: capacity_caseの倍数かチェック
                                        if ($pieceQty % $capacityCase !== 0) {
                                            $set('piece_qty_error', "入数({$capacityCase})の倍数で入力してください");
                                        } else {
                                            $set('piece_qty_error', null);
                                        }
                                    } else {
                                        $capacityCase = (int) ($get('capacity_case') ?? 1);
                                        $caseQty = (int) ($get('case_qty') ?? 0);
                                        $set('total_pieces', $caseQty * $capacityCase);
                                        $set('piece_qty_error', null);
                                    }
                                }),

                            Placeholder::make('total_pieces_display')
                                ->label('総バラ数')
                                ->content(function ($get) {
                                    $total = $get('total_pieces') ?? 0;
                                    $error = $get('piece_qty_error');
                                    if ($error) {
                                        return new HtmlString("<div><span class='text-lg font-bold text-red-600'>{$total}</span><div class='text-xs text-red-500 mt-1'>{$error}</div></div>");
                                    }

                                    return new HtmlString("<span class='text-lg font-bold text-green-600'>{$total}</span>");
                                }),
                        ]),

                    TextInput::make('total_pieces')->hidden()->default(0),
                    TextInput::make('piece_qty_error')->hidden()->dehydrated(false),
                ])
                ->action(function (array $data) {
                    // 商品情報を取得して発注数量を計算
                    $item = Item::find($data['item_id']);
                    if (! $item) {
                        Notification::make()
                            ->title('エラー')
                            ->body('商品が見つかりません')
                            ->danger()
                            ->send();

                        return;
                    }

                    $capacityCase = $item->capacity_case ?? 1;
                    $caseQty = (int) ($data['case_qty'] ?? 0);
                    $pieceQty = (int) ($data['piece_qty'] ?? 0);

                    if ($caseQty > 0) {
                        $orderQuantity = $caseQty * $capacityCase;
                    } elseif ($pieceQty > 0) {
                        // バラ数はcapacity_caseの倍数である必要がある
                        if ($pieceQty % $capacityCase !== 0) {
                            Notification::make()
                                ->title('エラー')
                                ->body("バラ数は入数({$capacityCase})の倍数である必要があります")
                                ->danger()
                                ->send();

                            return;
                        }
                        $orderQuantity = $pieceQty;
                    } else {
                        $orderQuantity = 0;
                    }

                    if ($orderQuantity <= 0) {
                        Notification::make()
                            ->title('エラー')
                            ->body('発注数量を入力してください')
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

                    // 同じ倉庫・商品の組み合わせが発注候補に既に存在するかチェック
                    $existsCandidate = WmsOrderCandidate::where('warehouse_id', $data['warehouse_id'])
                        ->where('item_id', $data['item_id'])
                        ->where('status', CandidateStatus::PENDING)
                        ->exists();

                    if ($existsCandidate) {
                        Notification::make()
                            ->title('エラー')
                            ->body('この倉庫・商品の組み合わせは既に発注候補に存在します')
                            ->danger()
                            ->send();

                        return;
                    }

                    // item_contractorsから発注先を取得
                    // 仮想倉庫の場合は入庫倉庫（実倉庫）で検索
                    $orderWarehouse = Warehouse::find($data['warehouse_id']);
                    $itemContractorWarehouseId = $data['incoming_warehouse_id']
                        ?? ($orderWarehouse?->is_virtual ? $orderWarehouse->stock_warehouse_id : null)
                        ?? $data['warehouse_id'];
                    $itemContractor = ItemContractor::where('warehouse_id', $itemContractorWarehouseId)
                        ->where('item_id', $data['item_id'])
                        ->first();

                    if (! $itemContractor) {
                        $itemContractorWarehouse = Warehouse::find($itemContractorWarehouseId);
                        $warehouseName = $itemContractorWarehouse
                            ? "[{$itemContractorWarehouse->code}]{$itemContractorWarehouse->name}"
                            : "ID:{$itemContractorWarehouseId}";
                        Notification::make()
                            ->title('エラー')
                            ->body("入庫倉庫「{$warehouseName}」に対する発注先が設定されていません")
                            ->danger()
                            ->send();

                        return;
                    }

                    // 発注先を取得してリードタイムから入荷予定日を計算
                    $contractor = Contractor::find($itemContractor->contractor_id);
                    $leadTimeService = app(ContractorLeadTimeService::class);
                    $arrivalInfo = $leadTimeService->calculateArrivalDate($contractor, now());
                    $expectedArrivalDate = $arrivalInfo['arrival_date'];
                    $leadTimeDays = $arrivalInfo['lead_time_days'] ?? 0;

                    // 仕入先と仕入単価を取得
                    $supplierId = $itemContractor->supplier_id;
                    $purchaseUnitPrice = $item->current_price?->purchase_unit_price;

                    // 発注候補を作成
                    WmsOrderCandidate::create([
                        'batch_code' => $batchCode,
                        'warehouse_id' => $data['warehouse_id'],
                        'item_id' => $data['item_id'],
                        'contractor_id' => $itemContractor->contractor_id,
                        'supplier_id' => $supplierId,
                        'purchase_unit_price' => $purchaseUnitPrice,
                        'self_shortage_qty' => 0,
                        'satellite_demand_qty' => 0,
                        'suggested_quantity' => $orderQuantity,
                        'order_quantity' => $orderQuantity,
                        'quantity_type' => QuantityType::PIECE,
                        'expected_arrival_date' => $expectedArrivalDate,
                        'original_arrival_date' => $expectedArrivalDate,
                        'status' => CandidateStatus::PENDING,
                        'lot_status' => LotStatus::RAW,
                        'is_manually_modified' => true,
                        'modified_by' => auth()->id(),
                        'modified_at' => now(),
                    ]);

                    // 計算ログを作成（手動追加として記録）
                    WmsOrderCalculationLog::create([
                        'batch_code' => $batchCode,
                        'warehouse_id' => $data['warehouse_id'],
                        'item_id' => $data['item_id'],
                        'calculation_type' => CalculationType::EXTERNAL,
                        'contractor_id' => $itemContractor->contractor_id,
                        'source_warehouse_id' => null,
                        'current_effective_stock' => 0,
                        'incoming_quantity' => 0,
                        'safety_stock_setting' => 0,
                        'lead_time_days' => $leadTimeDays,
                        'calculated_shortage_qty' => $orderQuantity,
                        'calculated_order_quantity' => $orderQuantity,
                        'calculation_details' => [
                            'manual_entry' => true,
                            'created_by' => auth()->id(),
                            'created_at' => now()->toDateTimeString(),
                            'formula' => '手動追加',
                            'order_case_qty' => $caseQty,
                            'order_piece_qty' => $pieceQty,
                            'capacity_case' => $capacityCase,
                            'total_pieces' => $orderQuantity,
                        ],
                    ]);

                    Notification::make()
                        ->title('発注候補を追加しました')
                        ->success()
                        ->send();

                    // ページをリフレッシュして新しい倉庫タブを表示
                    $this->redirect(static::getResource()::getUrl('index'));
                }),

            Action::make('approveAll')
                ->label('全て承認')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
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

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // PENDING の発注候補に存在する倉庫のみ取得（キャッシュして重複クエリを防止）
        $cacheKey = 'order_candidates_pending_warehouses_'.auth()->id();
        $warehouseIds = cache()->remember($cacheKey, 30, function () {
            return WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                ->distinct()
                ->pluck('warehouse_id')
                ->toArray();
        });
        $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('code')->get();

        // デフォルト倉庫が発注候補に存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);

        // プリセットビュー構築（データがなくても「全て」タブは常に表示）
        $views = [
            'default' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse || empty($warehouses)),
        ];

        // 倉庫タブを追加
        foreach ($warehouses as $warehouse) {
            $isDefault = $hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId;
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name)
                ->default($isDefault);
        }

        return $views;
    }
}
