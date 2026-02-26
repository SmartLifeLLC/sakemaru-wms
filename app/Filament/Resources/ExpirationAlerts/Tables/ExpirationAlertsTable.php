<?php

namespace App\Filament\Resources\ExpirationAlerts\Tables;

use App\Enums\EVolumeUnit;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Warehouse;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpirationAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultSort('expiration_date', 'asc')
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('real_stock_lots.status', 'ACTIVE')
                ->where('real_stock_lots.current_quantity', '>', 0)
                ->whereNotNull('real_stock_lots.expiration_date')
                ->where(function ($q) {
                    $q->whereRaw('real_stock_lots.expiration_date < CURDATE()')
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('real_stock_lots.alert_date')
                                ->whereRaw('real_stock_lots.alert_date <= CURDATE()')
                                ->whereRaw('real_stock_lots.expiration_date >= CURDATE()');
                        });
                })
                ->join('real_stocks', 'real_stocks.id', '=', 'real_stock_lots.real_stock_id')
                ->join('items', 'items.id', '=', 'real_stocks.item_id')
                ->join('warehouses', 'warehouses.id', '=', 'real_stocks.warehouse_id')
                ->leftJoin('locations', 'locations.id', '=', 'real_stock_lots.location_id')
                ->select([
                    'real_stock_lots.*',
                    'warehouses.code as warehouse_code',
                    'warehouses.name as warehouse_name',
                    'warehouses.id as warehouse_id_joined',
                    'items.code as item_code',
                    'items.name as item_name',
                    'items.volume as item_volume',
                    'items.volume_unit as item_volume_unit',
                    'locations.code1 as location_code1',
                    'locations.code2 as location_code2',
                    'locations.code3 as location_code3',
                ])
            )
            ->columns([
                TextColumn::make('expiration_status')
                    ->label('ステータス')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        if ($record->expiration_date && $record->expiration_date->isPast()) {
                            return '期限切れ';
                        }

                        return 'アラート';
                    })
                    ->color(fn (string $state) => match ($state) {
                        '期限切れ' => 'danger',
                        'アラート' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('warehouse_code')
                    ->label('倉庫CD')
                    ->sortable(),

                TextColumn::make('warehouse_name')
                    ->label('倉庫名')
                    ->sortable(),

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('items.code', 'like', "%{$search}%")),

                TextColumn::make('item_name')
                    ->label('商品名')
                    ->grow()
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('items.name', 'like', "%{$search}%")),

                TextColumn::make('location_display')
                    ->label('ロケーション')
                    ->getStateUsing(function ($record) {
                        $parts = array_filter([
                            $record->location_code1,
                            $record->location_code2,
                            $record->location_code3,
                        ]);

                        return implode('-', $parts) ?: '-';
                    }),

                TextColumn::make('volume_display')
                    ->label('規格')
                    ->getStateUsing(function ($record) {
                        if (! $record->item_volume) {
                            return '-';
                        }
                        $unitName = EVolumeUnit::tryFrom($record->item_volume_unit)?->name() ?? $record->item_volume_unit;

                        return $record->item_volume.$unitName;
                    }),

                TextColumn::make('created_at')
                    ->label('入荷日')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y-m-d')
                    ->sortable()
                    ->color(function ($record) {
                        if ($record->expiration_date && $record->expiration_date->isPast()) {
                            return 'danger';
                        }

                        return 'warning';
                    })
                    ->weight('bold'),

                TextColumn::make('alert_date')
                    ->label('アラート日')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('current_quantity')
                    ->label('在庫数')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('expiration_status_filter')
                    ->label('ステータス')
                    ->options([
                        'expired' => '期限切れ',
                        'alert' => 'アラート中',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (! $data['value']) {
                            return;
                        }

                        if ($data['value'] === 'expired') {
                            $query->whereRaw('real_stock_lots.expiration_date < CURDATE()');
                        } elseif ($data['value'] === 'alert') {
                            $query->whereRaw('real_stock_lots.expiration_date >= CURDATE()')
                                ->whereNotNull('real_stock_lots.alert_date')
                                ->whereRaw('real_stock_lots.alert_date <= CURDATE()');
                        }
                    }),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::orderBy('code')->pluck('name', 'id')->toArray())
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->where('real_stocks.warehouse_id', $data['value'])
                        : $query
                    ),
            ]);
    }
}
