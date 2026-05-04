<?php

namespace App\Filament\Resources\WmsStockSnapshot\Tables;

use App\Enums\PaginationOptions;
use App\Models\WmsStockSnapshot;
use App\Models\WmsStockSnapshotLot;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WmsStockSnapshotTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->extraAttributes(['class' => 'sticky-actions'])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['warehouse', 'item']))
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->defaultKeySort(false)
            ->defaultSort('captured_at', 'desc')
            ->columns([
                TextColumn::make('snapshot_date')
                    ->label('日付')
                    ->date('Y/m/d')
                    ->sortable()
                    ->width('100px'),

                TextColumn::make('snapshot_time')
                    ->label('時間帯')
                    ->formatStateUsing(fn (string $state) => $state === 'morning' ? '朝' : '夕')
                    ->badge()
                    ->color(fn (string $state) => $state === 'morning' ? 'info' : 'warning')
                    ->width('80px'),

                TextColumn::make('verification_status')
                    ->label('検証')
                    ->state(fn (WmsStockSnapshot $record): string => self::verificationLabel($record))
                    ->badge()
                    ->color(fn (WmsStockSnapshot $record): string => self::verificationColor($record))
                    ->width('80px'),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->sortable()
                    ->width('150px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->width('120px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('current_quantity')
                    ->label('現在庫数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('reserved_quantity')
                    ->label('引当済み数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('available_quantity')
                    ->label('利用可能数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('incoming_quantity')
                    ->label('入荷予定数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('captured_at')
                    ->label('取得日時')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('snapshot')
                    ->label('取得時点')
                    ->schema([
                        DatePicker::make('snapshot_date')
                            ->label('日付')
                            ->default(fn () => self::latestSnapshotDate()),
                        Select::make('snapshot_time')
                            ->label('時間帯')
                            ->options([
                                'morning' => '朝',
                                'evening' => '夕',
                            ])
                            ->default(fn () => self::latestSnapshotTime()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['snapshot_date'] ?? null, fn (Builder $q, string $date) => $q->where('snapshot_date', $date))
                            ->when($data['snapshot_time'] ?? null, fn (Builder $q, string $time) => $q->where('snapshot_time', $time));
                    }),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('item_id')
                    ->label('商品')
                    ->relationship('item', 'name')
                    ->searchable(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('lots')
                    ->label('ロット')
                    ->icon('heroicon-o-list-bullet')
                    ->modalHeading(fn (?WmsStockSnapshot $record): string => ($record?->item?->name ?? '商品').' ロット明細')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalContent(fn (?WmsStockSnapshot $record): View => view(
                        'filament.resources.wms-stock-snapshots.modal.lots',
                        ['record' => $record, 'lots' => $record === null ? collect() : self::lotsFor($record)]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
            ])
            ->toolbarActions([])
            ->bulkActions([]);
    }

    private static function latestSnapshotDate(): ?string
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_stock_snapshots')) {
            return null;
        }

        return DB::connection('sakemaru')
            ->table('wms_stock_snapshots')
            ->max('snapshot_date');
    }

    private static function latestSnapshotTime(): ?string
    {
        $date = self::latestSnapshotDate();
        if ($date === null) {
            return null;
        }

        return DB::connection('sakemaru')
            ->table('wms_stock_snapshots')
            ->where('snapshot_date', $date)
            ->orderByRaw("FIELD(snapshot_time, 'evening', 'morning')")
            ->value('snapshot_time');
    }

    private static function verificationLabel(WmsStockSnapshot $record): string
    {
        $verification = self::verification($record);

        if ($verification === null) {
            return '未検証';
        }

        return $verification->is_healthy ? '正常' : '異常';
    }

    private static function verificationColor(WmsStockSnapshot $record): string
    {
        $verification = self::verification($record);

        if ($verification === null) {
            return 'gray';
        }

        return $verification->is_healthy ? 'success' : 'danger';
    }

    private static function verification(WmsStockSnapshot $record): ?object
    {
        return DB::connection('sakemaru')
            ->table('wms_stock_snapshot_verifications')
            ->where('snapshot_date', $record->snapshot_date?->toDateString())
            ->where('snapshot_time', $record->snapshot_time)
            ->first(['is_healthy']);
    }

    private static function lotsFor(WmsStockSnapshot $record): Collection
    {
        return WmsStockSnapshotLot::query()
            ->with(['location', 'floor'])
            ->where('snapshot_date', $record->snapshot_date?->toDateString())
            ->where('snapshot_time', $record->snapshot_time)
            ->where('warehouse_id', $record->warehouse_id)
            ->where('item_id', $record->item_id)
            ->orderByRaw('expiration_date IS NULL')
            ->orderBy('expiration_date')
            ->orderBy('lot_created_at')
            ->orderBy('lot_id')
            ->limit(500)
            ->get();
    }
}
