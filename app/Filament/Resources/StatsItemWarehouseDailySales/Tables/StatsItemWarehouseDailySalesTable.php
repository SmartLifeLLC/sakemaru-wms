<?php

namespace App\Filament\Resources\StatsItemWarehouseDailySales\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;

class StatsItemWarehouseDailySalesTable
{
    use HasExportAction;
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->defaultSort('business_date', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                // デフォルトで直近7日に絞る（フィルタ未指定時のOOM防止）
                $query->where('business_date', '>=', now()->subDays(7)->toDateString());
            })
            ->columns([
                TextColumn::make('business_date')
                    ->label('日付')
                    ->date('Y/m/d')
                    ->sortable()
                    ->width('110px'),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->sortable('warehouse_id')
                    ->width('80px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->width('120px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->width('100px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->grow()
                    ->searchable(),

                TextColumn::make('shipped_piece_qty')
                    ->label('バラ数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->width('90px'),

                TextColumn::make('shipped_case_qty')
                    ->label('ケース数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->width('90px'),

                TextColumn::make('shipped_bottle_qty')
                    ->label('ボール数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->width('90px'),
            ])
            ->filters([
                Filter::make('business_date')
                    ->label('日付範囲')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('from')
                                ->label('開始日')
                                ->default(now()->subDays(7)->toDateString()),
                            DatePicker::make('to')
                                ->label('終了日')
                                ->default(now()->toDateString()),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->where('business_date', '>=', $date))
                            ->when($data['to'], fn (Builder $q, $date) => $q->where('business_date', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = '開始: ' . $data['from'];
                        }
                        if ($data['to'] ?? null) {
                            $indicators[] = '終了: ' . $data['to'];
                        }

                        return $indicators;
                    }),

                static::warehouseFilter(),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ]);
    }
}
