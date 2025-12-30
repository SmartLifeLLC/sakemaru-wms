<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Filament\Resources\DeliveryCourseChangeResource\Pages;
use App\Models\Sakemaru\Trade;
use App\Services\DeliveryCourseChangeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\PaginationOptions;


class DeliveryCourseChangeResource extends Resource
{
    protected static ?string $model = Trade::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::DELIVERY_COURSE_CHANGE->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::DELIVERY_COURSE_CHANGE->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::DELIVERY_COURSE_CHANGE->sort();
    }

    public static function getPluralModelLabel(): string
    {
        return '配送伝票リスト';
    }

    public static function getTradeDetails(int $tradeId): array
    {
        $trade = DB::connection('sakemaru')
            ->table('trades')
            ->where('id', $tradeId)
            ->first();

        if (!$trade) {
            return [];
        }

        $earning = DB::connection('sakemaru')
            ->table('earnings')
            ->where('trade_id', $tradeId)
            ->first();

        $partner = DB::connection('sakemaru')
            ->table('partners')
            ->where('id', $trade->partner_id)
            ->first();

        $deliveryCourse = null;
        if ($earning && $earning->delivery_course_id) {
            $deliveryCourse = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->where('id', $earning->delivery_course_id)
                ->first();
        }

        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->where('trade_id', $tradeId)
            ->get();

        // Enrich trade items with related data
        foreach ($tradeItems as $item) {
            $itemData = DB::connection('sakemaru')
                ->table('items')
                ->where('id', $item->item_id)
                ->first();
            $item->item = $itemData;

            $pickingResult = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->where('trade_item_id', $item->id)
                ->first();
            $item->picking_result = $pickingResult;
        }

        $buyer = DB::connection('sakemaru')
            ->table('buyers')
            ->where('partner_id', $partner->id)
            ->first();

        $buyerDetail = null;
        if ($buyer) {
            $buyerDetail = DB::connection('sakemaru')
                ->table('buyer_details')
                ->where('buyer_id', $buyer->id)
                ->orderBy('start_date', 'desc')
                ->first();

            if ($buyerDetail && $buyerDetail->salesman_id) {
                $salesman = DB::connection('sakemaru')
                    ->table('users')
                    ->where('id', $buyerDetail->salesman_id)
                    ->first();
            }
        }

        $tradePrice = DB::connection('sakemaru')
            ->table('trade_prices')
            ->where('trade_id', $tradeId)
            ->first();

        $tradeBalances = DB::connection('sakemaru')
            ->table('trade_balances')
            ->where('trade_id', $tradeId)
            ->get();

        return [
            'trade' => $trade,
            'earning' => $earning,
            'partner' => $partner,
            'buyer' => $buyer,
            'buyer_detail' => $buyerDetail,
            'salesman' => $salesman,
            'delivery_course' => $deliveryCourse,
            'trade_items' => $tradeItems,
            'trade_price' => $tradePrice,
            'trade_balances' => $tradeBalances,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // PENDING状態のpicking_taskに紐づくtradesを取得
        return Trade::query()
            ->join('wms_picking_item_results as pir', 'trades.id', '=', 'pir.trade_id')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('partners as p', 'trades.partner_id', '=', 'p.id')
            ->join('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->join('earnings as e', 'e.trade_id', '=', 'trades.id')
            ->leftJoin('warehouses as w', 'pt.warehouse_id', '=', 'w.id')
            ->leftJoin('buyers as b', 'p.id', '=', 'b.partner_id')
            ->leftJoin('buyer_details as bd', function ($join) {
                $join->on('b.id', '=', 'bd.buyer_id')
                     ->whereRaw('bd.id = (SELECT id FROM buyer_details WHERE buyer_id = b.id ORDER BY start_date DESC LIMIT 1)');
            })
            ->leftJoin('users as u', 'bd.salesman_id', '=', 'u.id')
            ->where('pt.status', 'PENDING')
            ->select([
                'trades.id',
                'trades.serial_id',
                'trades.partner_id',
                'trades.total',
                'p.code as partner_code',
                'p.name as partner_name',
                'dc.id as current_course_id',
                'dc.code as current_course_code',
                'dc.name as current_course_name',
                'pt.warehouse_id',
                'pt.shipment_date',
                'e.picking_status',
                'w.name as warehouse_name',
                'u.name as salesman_name',
            ])
            ->groupBy([
                'trades.id',
                'trades.serial_id',
                'trades.partner_id',
                'trades.total',
                'p.code',
                'p.name',
                'dc.id',
                'dc.code',
                'dc.name',
                'pt.warehouse_id',
                'pt.shipment_date',
                'e.picking_status',
                'w.name',
                'u.name',
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->striped()
            ->columns([
                TextColumn::make('serial_id')
                    ->label('伝票番号')
                    ->searchable()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('picking_status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'BEFORE_PICKING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'PENDING' => '未着手',
                        'BEFORE_PICKING' => 'ピッキング準備中',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                        default => $state ?? '-',
                    })
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('shipment_date')
                    ->label('出荷日')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('warehouse_name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('partner_code')
                    ->label('得意先コード')
                    ->searchable()
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('partner_name')
                    ->label('得意先名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('salesman_name')
                    ->label('担当営業')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('current_course_code')
                    ->label('配送コースコード')
                    ->searchable()
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('current_course_name')
                    ->label('配送コース名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total')
                    ->label('合計金額')
                    ->money('JPY')
                    ->sortable()
                    ->alignRight(),
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('shipment_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('shipment_date')
                            ->label('出荷日')
                            ->default(\App\Models\Sakemaru\ClientSetting::systemDateYMD()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $date = $data['shipment_date'] ?? \App\Models\Sakemaru\ClientSetting::systemDateYMD();
                        return $query->whereDate('pt.shipment_date', $date);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $date = $data['shipment_date'] ?? \App\Models\Sakemaru\ClientSetting::systemDateYMD();
                        return '出荷日: ' . \Carbon\Carbon::parse($date)->format('Y-m-d');
                    })
                    ->default(['shipment_date' => \App\Models\Sakemaru\ClientSetting::systemDateYMD()]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('warehouses')
                            ->where('is_active', true)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->default(fn () => auth()->user()->default_warehouse_id)
                    ->query(function (Builder $query, array $data) {
                        $warehouseId = $data['value'] ?? auth()->user()->default_warehouse_id;
                        if ($warehouseId) {
                            return $query->where('pt.warehouse_id', $warehouseId);
                        }
                        return $query;
                    })
                    ->indicateUsing(function (array $data) {
                        $warehouseId = $data['value'] ?? auth()->user()->default_warehouse_id;
                        if (! $warehouseId) {
                            return null;
                        }
                        $name = DB::connection('sakemaru')->table('warehouses')->where('id', $warehouseId)->value('name');
                        return '倉庫: ' . $name;
                    }),

                SelectFilter::make('partner_id')
                    ->label('得意先')
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('partners')
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($partner) => [$partner->id => "[{$partner->code}] {$partner->name}"])
                            ->toArray();
                    })
                    ->searchable()
                    ->preload(),

                SelectFilter::make('delivery_course_id')
                    ->label('配送コース')
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('delivery_courses')
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($course) => [$course->id => "{$course->code} - {$course->name}"])
                            ->toArray();
                    })
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $v) => $q->where('pt.delivery_course_id', $v))),

                SelectFilter::make('salesman_id')
                    ->label('担当営業')
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('users')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $v) => $q->where('bd.salesman_id', $v))),

                SelectFilter::make('picking_status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '未着手',
                        'BEFORE_PICKING' => 'ピッキング準備中',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $v) => $q->where('e.picking_status', $v))),
            ])
            ->recordActions([
                Action::make('viewDetails')
                    ->label('伝票')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->modalWidth('7xl')
                    ->modalHeading(fn ($record) => "伝票詳細 - {$record->serial_id}")
                    ->modalContent(fn ($record) => new \Illuminate\Support\HtmlString(\Livewire\Livewire::mount('trade-detail-modal', [
                        'tradeId' => $record->id,
                    ])))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),

                Action::make('changeDeliveryCourse')
                    ->label('コース変更')
                    ->icon('heroicon-o-arrow-path')
                    ->disabled(fn ($record) => !in_array($record->picking_status, ['BEFORE', 'BEFORE_PICKING']))
                    ->extraAttributes(function ($record) {
                        if (!in_array($record->picking_status, ['BEFORE', 'BEFORE_PICKING'])) {
                            return ['class' => 'line-through opacity-60'];
                        }
                        return [];
                    })
                    ->form([
                        Select::make('new_course_id')
                            ->label('変更先配送コース')
                            ->options(function ($record) {
                                // 倉庫に紐づく配送コースを取得
                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $record->warehouse_id)
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(function ($course) {
                                        return [$course->id => "{$course->code} - {$course->name}"];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(DeliveryCourseChangeService::class);

                        try {
                            $result = $service->changeDeliveryCourse($record->id, $data['new_course_id']);

                            Notification::make()
                                ->title('配送コース変更完了')
                                ->success()
                                ->body("伝票番号 {$record->serial_id} の配送コースを変更しました。")
                                ->send();

                            return $result;
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('配送コース変更失敗')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();

                            throw $e;
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('配送コース変更確認')
                    ->modalDescription('この伝票の配送コースを変更します。よろしいですか？')
                    ->modalSubmitActionLabel('変更する'),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkAction::make('bulkChangeDeliveryCourse')
                    ->label('一括配送コース変更')
                    ->icon('heroicon-o-arrow-path')
                    ->fillForm(function (Collection $records) {
                        // Use the first record's warehouse_id to pre-load courses
                        $firstWarehouseId = $records->first()?->warehouse_id;
                        return [
                            'warehouse_id' => $firstWarehouseId,
                        ];
                    })
                    ->form([
                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(\App\Models\Sakemaru\Warehouse::where('is_active', true)->pluck('name', 'id'))
                            ->disabled()
                            ->dehydrated(true)
                            ->hidden(),
                        Select::make('new_course_id')
                            ->label('変更先配送コース')
                            ->options(function ($get) {
                                $warehouseId = $get('warehouse_id');
                                if (!$warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $warehouseId)
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(function ($course) {
                                        return [$course->id => "{$course->code} - {$course->name}"];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $service = app(DeliveryCourseChangeService::class);
                        
                        // Filter records that can be changed
                        $changeableRecords = $records->filter(function ($record) {
                            return in_array($record->picking_status, ['BEFORE', 'BEFORE_PICKING']);
                        });

                        if ($changeableRecords->isEmpty()) {
                            Notification::make()
                                ->title('変更対象なし')
                                ->warning()
                                ->body('選択された伝票の中に、配送コース変更可能な伝票（未着手またはピッキング準備中）がありません。')
                                ->send();
                            return;
                        }

                        $skippedCount = $records->count() - $changeableRecords->count();
                        $tradeIds = $changeableRecords->pluck('id')->toArray();

                        try {
                            $result = $service->bulkChangeDeliveryCourse($tradeIds, $data['new_course_id']);

                            Notification::make()
                                ->title('一括配送コース変更完了')
                                ->success()
                                ->body("{$result['success_count']}件の配送コースを変更しました。")
                                ->send();

                            if ($result['failure_count'] > 0) {
                                Notification::make()
                                    ->title('一部変更失敗')
                                    ->warning()
                                    ->body("{$result['failure_count']}件の変更に失敗しました。")
                                    ->send();
                            }

                            return $result;
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('一括配送コース変更失敗')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();

                            throw $e;
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('一括配送コース変更確認')
                    ->modalDescription('選択した伝票の配送コースを一括で変更します。よろしいですか？')
                    ->modalSubmitActionLabel('一括変更する')
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryCourseChanges::route('/'),
        ];
    }
}
