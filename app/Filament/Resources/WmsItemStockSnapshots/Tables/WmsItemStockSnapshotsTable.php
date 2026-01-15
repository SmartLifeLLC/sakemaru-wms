<?php

namespace App\Filament\Resources\WmsItemStockSnapshots\Tables;

use App\Enums\PaginationOptions;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\RealStockLot;
use Filament\Actions\Action;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class WmsItemStockSnapshotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('warehouse.code')
                    ->label('倉庫コード')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->sortable()
                    ->searchable()
                    ->copyable(),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable()
                    ->limit(40),

                TextColumn::make('total_effective_piece')
                    ->label('有効在庫(バラ)')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->summarize(Sum::make()->label('合計')),

                TextColumn::make('total_incoming_piece')
                    ->label('入荷予定(バラ)')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'success' : null)
                    ->summarize(Sum::make()->label('合計')),

                TextColumn::make('available_stock')
                    ->label('利用可能(バラ)')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color('primary')
                    ->weight('bold'),

                TextColumn::make('snapshot_at')
                    ->label('スナップショット日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->preload()
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('view_stock')
                    ->label('在庫詳細')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn ($record) => $record->item?->name ?? '在庫詳細')
                    ->modalWidth('screen')
                    ->modalContent(function ($record): ?View {
                        $realStock = RealStock::where('warehouse_id', $record->warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->first();

                        if (! $realStock) {
                            return null;
                        }

                        return view(
                            'filament.resources.real-stocks.modal.stock-detail',
                            self::getModalData($realStock)
                        );
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->visible(function ($record) {
                        return RealStock::where('warehouse_id', $record->warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->exists();
                    }),
            ])
            ->defaultSort('total_effective_piece', 'desc');
    }

    /**
     * モーダル表示用のデータを取得
     */
    private static function getModalData(RealStock $record): array
    {
        // リレーションをロード
        $record->load([
            'warehouse',
            'item.manufacturer',
            'item.item_category1',
            'item.item_category2',
            'item.item_category3',
            'activeLots.floor',
            'activeLots.location',
            'activeLots.purchase',
            'activeLots.buyerRestrictions.buyer',
        ]);

        // システム日付を取得
        $systemDate = DB::connection('sakemaru')
            ->table('client_settings')
            ->value('system_date') ?? now()->toDateString();

        $item = $record->item;
        $capacityCase = $item?->capacity_case ?? 1;
        $capacityCarton = $item?->capacity_carton ?? 1;

        // 本日入荷
        $incoming = self::getTodayIncoming($record, $systemDate, $capacityCase, $capacityCarton);

        // 本日出荷
        $outgoing = self::getTodayOutgoing($record, $systemDate, $capacityCase, $capacityCarton);

        // ロット一覧
        $activeLots = $record->activeLots
            ->sortBy([
                ['expiration_date', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();

        $depletedLots = $record->lots()
            ->where('status', RealStockLot::STATUS_DEPLETED)
            ->latest()
            ->limit(5)
            ->get();

        return [
            'record' => $record,
            'item' => $item,
            'warehouse' => $record->warehouse,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'summary' => [
                'current_quantity' => $record->current_quantity,
                'available_quantity' => $record->available_quantity,
                'reserved_quantity' => $record->reserved_quantity,
            ],
            'lots' => [
                'active' => $activeLots,
                'depleted' => $depletedLots,
            ],
        ];
    }

    private static function getTodayIncoming(RealStock $record, string $systemDate, int $capacityCase, int $capacityCarton): array
    {
        // 仕入入荷（直送除く）
        $purchaseIncoming = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'PURCHASE')
            ->where('trade_items.item_id', $record->item_id)
            ->where('trades.is_active', true)
            ->leftJoin('purchases', 'trades.id', '=', 'purchases.trade_id')
            ->where('purchases.is_direct_delivery', false)
            ->where('purchases.warehouse_id', $record->warehouse_id)
            ->whereDate('purchases.delivered_date', $systemDate)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 直送
        $directIncoming = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'PURCHASE')
            ->where('trade_items.item_id', $record->item_id)
            ->leftJoin('purchases', 'trades.id', '=', 'purchases.trade_id')
            ->where('purchases.is_direct_delivery', true)
            ->whereDate('purchases.delivered_date', $systemDate)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 移動入荷
        $transferIncoming = DB::connection('sakemaru')
            ->table('stock_transfers')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('to_warehouse_id', $record->warehouse_id)
            ->where('trade_items.item_id', $record->item_id)
            ->whereDate('delivered_date', $systemDate)
            ->leftJoin('trades', 'stock_transfers.trade_id', '=', 'trades.id')
            ->leftJoin('trade_items', 'trade_items.trade_id', '=', 'trades.id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        return [
            'purchase_incoming' => self::calculatePieceQuantity($purchaseIncoming, $capacityCase, $capacityCarton),
            'direct_incoming' => self::calculatePieceQuantity($directIncoming, $capacityCase, $capacityCarton),
            'transfer_incoming' => self::calculatePieceQuantity($transferIncoming, $capacityCase, $capacityCarton),
        ];
    }

    private static function getTodayOutgoing(RealStock $record, string $systemDate, int $capacityCase, int $capacityCarton): array
    {
        // 出荷予定（is_delivered = false）
        $earningsNotDelivered = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'EARNING')
            ->where('trade_items.item_id', $record->item_id)
            ->where('trades.is_active', true)
            ->whereDate('earnings.delivered_date', $systemDate)
            ->where('earnings.warehouse_id', $record->warehouse_id)
            ->where('earnings.is_delivered', false)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->leftJoin('earnings', 'trades.id', '=', 'earnings.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 出荷済（is_delivered = true）
        $earningsDelivered = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'EARNING')
            ->where('trade_items.item_id', $record->item_id)
            ->where('trades.is_active', true)
            ->whereDate('earnings.delivered_date', $systemDate)
            ->where('earnings.warehouse_id', $record->warehouse_id)
            ->where('earnings.is_delivered', true)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->leftJoin('earnings', 'trades.id', '=', 'earnings.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 移動出荷
        $transferOutgoing = DB::connection('sakemaru')
            ->table('stock_transfers')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('from_warehouse_id', $record->warehouse_id)
            ->where('trade_items.item_id', $record->item_id)
            ->whereDate('delivered_date', $systemDate)
            ->leftJoin('trades', 'stock_transfers.trade_id', '=', 'trades.id')
            ->leftJoin('trade_items', 'trade_items.trade_id', '=', 'trades.id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        $notDeliveredQty = self::calculatePieceQuantity($earningsNotDelivered, $capacityCase, $capacityCarton);
        $deliveredQty = self::calculatePieceQuantity($earningsDelivered, $capacityCase, $capacityCarton);

        return [
            'total_reserved' => $notDeliveredQty + $deliveredQty,
            'total_shipped' => $deliveredQty,
            'transfer_outgoing' => self::calculatePieceQuantity($transferOutgoing, $capacityCase, $capacityCarton),
        ];
    }

    private static function calculatePieceQuantity($items, int $capacityCase, int $capacityCarton): int
    {
        $sum = 0;
        foreach ($items as $item) {
            if ($item->quantity_type === 'PIECE') {
                $sum += $item->quantity;
            } elseif ($item->quantity_type === 'CASE') {
                $sum += $item->quantity * $capacityCase;
            } elseif ($item->quantity_type === 'CARTON') {
                $sum += $item->quantity * $capacityCarton;
            }
        }

        return $sum;
    }
}
