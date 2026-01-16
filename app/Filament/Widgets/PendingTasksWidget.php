<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WmsPickingTasks\WmsPickingItemEditResource;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingTasksWidget extends BaseWidget
{
    public ?int $warehouseId = null;

    public ?int $currentTaskId = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '同一倉庫の未準備タスク';

    public function getTableRecordKey($record): string
    {
        return (string) $record->id;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                if (! $this->warehouseId) {
                    return WmsPickingTask::query()->whereRaw('1 = 0');
                }

                return WmsPickingTask::query()
                    ->where('warehouse_id', $this->warehouseId)
                    ->where('status', WmsPickingTask::STATUS_PENDING)
                    ->when($this->currentTaskId, fn ($q) => $q->where('id', '!=', $this->currentTaskId))
                    ->with([
                        'deliveryCourse',
                        'pickingItemResults.earning.buyer.partner',
                        'pickingItemResults.stockTransfer.to_warehouse',
                    ])
                    ->orderBy('created_at', 'asc');
            })
            ->columns([
                TextColumn::make('id')
                    ->label('タスクID')
                    ->sortable(),

                TextColumn::make('soft_shortage_count')
                    ->label('引当欠品')
                    ->badge()
                    ->state(function ($record) {
                        return $record->pickingItemResults->where('has_soft_shortage', true)->count();
                    })
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state) => $state > 0 ? "欠品あり ({$state}件)" : '-'),

                TextColumn::make('source_type_display')
                    ->label('伝票種別')
                    ->badge()
                    ->state(function ($record) {
                        $hasStockTransfer = $record->pickingItemResults
                            ->contains(fn ($item) => $item->source_type === WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER);
                        $hasEarning = $record->pickingItemResults
                            ->contains(fn ($item) => $item->source_type === WmsPickingItemResult::SOURCE_TYPE_EARNING || $item->source_type === null);

                        if ($hasStockTransfer && $hasEarning) {
                            return '混合';
                        } elseif ($hasStockTransfer) {
                            return '移動';
                        }

                        return '売上';
                    })
                    ->color(function ($record) {
                        $hasStockTransfer = $record->pickingItemResults
                            ->contains(fn ($item) => $item->source_type === WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER);
                        $hasEarning = $record->pickingItemResults
                            ->contains(fn ($item) => $item->source_type === WmsPickingItemResult::SOURCE_TYPE_EARNING || $item->source_type === null);

                        if ($hasStockTransfer && $hasEarning) {
                            return 'warning';
                        } elseif ($hasStockTransfer) {
                            return 'info';
                        }

                        return 'success';
                    }),

                TextColumn::make('partner_names')
                    ->label('得意先/移動先')
                    ->default('-')
                    ->formatStateUsing(function ($record) {
                        $names = collect();

                        // Collect partner names for earnings
                        $partnerNames = $record->pickingItemResults
                            ->filter(fn ($item) => $item->earning_id !== null)
                            ->pluck('earning.buyer.partner.name')
                            ->filter()
                            ->unique();

                        foreach ($partnerNames as $name) {
                            $names->push($name);
                        }

                        // Collect warehouse names for stock_transfers
                        $warehouseNames = $record->pickingItemResults
                            ->filter(fn ($item) => $item->stock_transfer_id !== null)
                            ->map(function ($item) {
                                $warehouse = $item->stockTransfer?->to_warehouse;

                                return $warehouse ? "[移動]{$warehouse->name}" : null;
                            })
                            ->filter()
                            ->unique();

                        foreach ($warehouseNames as $name) {
                            $names->push($name);
                        }

                        if ($names->isEmpty()) {
                            return '-';
                        }

                        // Sort and format
                        $names = $names->sort()->values();

                        // 2件以上の場合は6文字で省略
                        if ($names->count() >= 2) {
                            $names = $names->map(function ($name) {
                                if (str_starts_with($name, '[移動]')) {
                                    return '[移動]'.mb_substr(str_replace('[移動]', '', $name), 0, 4);
                                }

                                return mb_substr($name, 0, 6);
                            });
                        }

                        return $names->implode(', ');
                    }),

                TextColumn::make('temperature_type')
                    ->label('温度帯')
                    ->badge()
                    ->color(fn ($record) => $record->temperature_type?->color() ?? 'gray')
                    ->formatStateUsing(fn ($record) => $record->temperature_type?->label() ?? '-'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース')
                    ->default('-'),

                TextColumn::make('created_at')
                    ->label('生成日時')
                    ->dateTime('H:i')
                    ->sortable(),
            ])
            ->paginated(false)
            ->striped()
            ->recordUrl(fn ($record) => WmsPickingItemEditResource::getUrl('index', [
                'tableFilters' => [
                    'picking_task_id' => [
                        'value' => $record->id,
                    ],
                ],
            ]))
            ->emptyStateHeading('未準備のタスクはありません')
            ->emptyStateDescription('同一倉庫内のすべてのタスクが準備完了しています');
    }
}
