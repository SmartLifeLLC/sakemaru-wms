<?php

namespace App\Filament\Resources\WmsPickingTasks\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Filament\Resources\WmsPickingTasks\WmsPickingWaitingV2Resource;
use App\Models\Sakemaru\DeliveryCourse;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsPickingWaitingsV2Table
{
    use HasExportAction;
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->extraAttributes(['class' => 'sticky-actions-left'])
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->modifyQueryUsing(fn (Builder $query) => static::applyCourseAggregation($query))
            ->columns([
                TextColumn::make('shipment_date')
                    ->label('出荷日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('deliveryCourse.code')
                    ->label('配送コースCD')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース名')
                    ->default('未設定')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('source_type_summary')
                    ->label('伝票種別')
                    ->badge()
                    ->state(function ($record): string {
                        $hasEarning = (int) $record->earning_count > 0;
                        $hasStockTransfer = (int) $record->stock_transfer_count > 0;

                        return match (true) {
                            $hasEarning && $hasStockTransfer => '混合',
                            $hasStockTransfer => '移動',
                            default => '売上',
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        '混合' => 'warning',
                        '移動' => 'info',
                        default => 'success',
                    }),

                TextColumn::make('task_count')
                    ->label('タスク数')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((int) $state).'件')
                    ->sortable(),

                TextColumn::make('detail_count')
                    ->label('明細数')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((int) $state).'件')
                    ->sortable(),

                TextColumn::make('item_count')
                    ->label('商品数')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((int) $state).'点')
                    ->sortable(),

                TextColumn::make('destination_count')
                    ->label('得意先/移動先数')
                    ->alignRight()
                    ->state(fn ($record) => (int) $record->earning_count + (int) $record->stock_transfer_count)
                    ->formatStateUsing(fn ($state) => number_format((int) $state).'件'),

                TextColumn::make('planned_qty_total')
                    ->label('予定数')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->sortable(),

                TextColumn::make('soft_shortage_count')
                    ->label('引当欠品')
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? '欠品あり ('.number_format((int) $state).'件)' : '-')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('最新生成日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('updated_at')
                    ->label('最新更新日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                static::warehouseFilter(),

                SelectFilter::make('delivery_course_id')
                    ->label('配送コース')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return DeliveryCourse::query()
                            ->where(fn ($q) => $q
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"])
                            ->toArray();
                    }),

                SelectFilter::make('has_soft_shortage')
                    ->label('引当欠品')
                    ->options([
                        'with_shortage' => '欠品あり',
                        'without_shortage' => '欠品なし',
                    ])
                    ->query(function ($query, $state) {
                        return match ($state['value'] ?? null) {
                            'with_shortage' => $query->whereHas('pickingItemResults', fn ($subQuery) => $subQuery->where('has_soft_shortage', true)),
                            'without_shortage' => $query->whereDoesntHave('pickingItemResults', fn ($subQuery) => $subQuery->where('has_soft_shortage', true)),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('openDetails')
                    ->label('明細')
                    ->icon('heroicon-o-table-cells')
                    ->color('primary')
                    ->url(fn ($record) => route('filament.admin.resources.wms-picking-item-edit-v2.index', [
                        'warehouse_id' => $record->warehouse_id,
                        'delivery_course_id' => $record->delivery_course_id,
                        'shipment_date' => $record->shipment_date?->format('Y-m-d') ?? $record->shipment_date,
                    ])),
                Action::make('execute')
                    ->label('ピッキング実施')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->url(fn ($record) => WmsPickingWaitingV2Resource::getUrl('execute', ['record' => $record->id])),
            ], position: RecordActionsPosition::BeforeColumns)
            ->recordUrl(fn ($record) => route('filament.admin.resources.wms-picking-item-edit-v2.index', [
                'warehouse_id' => $record->warehouse_id,
                'delivery_course_id' => $record->delivery_course_id,
                'shipment_date' => $record->shipment_date?->format('Y-m-d') ?? $record->shipment_date,
            ]))
            ->defaultSort('delivery_course_id')
            ->toolbarActions([
                static::getExportAction(),
            ]);
    }

    private static function applyCourseAggregation(Builder $query): Builder
    {
        return $query
            ->leftJoin('wms_picking_item_results as pir', 'pir.picking_task_id', '=', 'wms_picking_tasks.id')
            ->select([
                'wms_picking_tasks.warehouse_id',
                'wms_picking_tasks.delivery_course_id',
                'wms_picking_tasks.shipment_date',
            ])
            ->selectRaw('MIN(wms_picking_tasks.id) as id')
            ->selectRaw('GROUP_CONCAT(DISTINCT wms_picking_tasks.id ORDER BY wms_picking_tasks.id) as task_ids')
            ->selectRaw('COUNT(DISTINCT wms_picking_tasks.id) as task_count')
            ->selectRaw('COUNT(pir.id) as detail_count')
            ->selectRaw('COUNT(DISTINCT pir.item_id) as item_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN pir.earning_id IS NOT NULL THEN pir.earning_id END) as earning_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN pir.stock_transfer_id IS NOT NULL THEN pir.stock_transfer_id END) as stock_transfer_count')
            ->selectRaw('COALESCE(SUM(pir.ordered_qty), 0) as ordered_qty_total')
            ->selectRaw('COALESCE(SUM(pir.planned_qty), 0) as planned_qty_total')
            ->selectRaw('COALESCE(SUM(pir.picked_qty), 0) as picked_qty_total')
            ->selectRaw('COALESCE(SUM(pir.shortage_qty), 0) as shortage_qty_total')
            ->selectRaw('SUM(CASE WHEN pir.has_soft_shortage = 1 THEN 1 ELSE 0 END) as soft_shortage_count')
            ->selectRaw('MAX(wms_picking_tasks.created_at) as created_at')
            ->selectRaw('MAX(wms_picking_tasks.updated_at) as updated_at')
            ->groupBy([
                'wms_picking_tasks.shipment_date',
                'wms_picking_tasks.warehouse_id',
                'wms_picking_tasks.delivery_course_id',
            ]);
    }
}
