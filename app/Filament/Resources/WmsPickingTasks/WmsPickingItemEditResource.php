<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingItemEdits;
use App\Models\Sakemaru\Partner;
use App\Models\WmsPickingItemResult;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\PaginationOptions;


class WmsPickingItemEditResource extends Resource
{
    protected static ?string $model = WmsPickingItemResult::class;

    protected static ?string $slug = 'wms-picking-item-edit';

    // Hide from navigation menu
    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {

        return $table
            ->modifyQueryUsing(function (Builder $query,$livewire) {
                // リクエストから値を取得
                // Pageクラスのプロパティを参照
                // ※ $livewireがListWmsPickingItemEditsのインスタンスか念のためチェックしても良い
                if (isset($livewire->pickingTaskId) && filled($livewire->pickingTaskId)) {
                    $query->where('picking_task_id', $livewire->pickingTaskId);
                }
            })
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->striped()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->alignCenter(),
                TextColumn::make('pickingTask.wave.id')->label('wave id'),
                TextColumn::make('trade.serial_id')
                    ->label('伝票番号')
                    ->alignCenter()
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('earning.buyer.partner.code')
                    ->label('得意先コード')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('earning.buyer.partner.name')
                    ->label('得意先名')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('ordered_qty_case')
                    ->label('ケース')
                    ->state(fn ($record) => $record->ordered_qty_type === 'CASE' ? $record->ordered_qty : '-')
                    ->alignment('center'),
                TextColumn::make('ordered_qty_piece')
                    ->label('バラ')
                    ->state(fn ($record) => $record->ordered_qty_type === 'PIECE' ? $record->ordered_qty : '-')
                    ->alignment('center'),

                TextInputColumn::make('planned_qty')
                    ->width('50px')
                    ->label('引当数')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->type('number')
                    ->step(1)
                    ->alignCenter()
                    ->disabled(fn ($record) => !$record->pickingTask || $record->pickingTask->status !== \App\Models\WmsPickingTask::STATUS_PENDING)
                    ->extraCellAttributes([
                        'class' => 'p-0',
                    ])
                    ->extraInputAttributes([
                        'class' =>
                            'w-16 !h-7 !p-0 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-xs',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ])
                    ->afterStateUpdated(function ($record, $state) {
                        if (!is_numeric($state) || $state < 0) {
                            Notification::make()
                                ->title('エラー')
                                ->body('有効な数値を入力してください')
                                ->danger()
                                ->send();
                            return;
                        }

                        if ($state > $record->ordered_qty) {
                            Notification::make()
                                ->title('エラー')
                                ->body("引当数は受注数（{$record->ordered_qty}）を超えることはできません")
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->planned_qty = $state;
                        $record->save();

                        Notification::make()
                            ->title('引当数を更新しました')
                            ->success()
                            ->send();
                    }),
                TextColumn::make('shortage_qty')
                    ->label('欠品数')
                    ->state(fn ($record) => max(0, $record->ordered_qty - $record->planned_qty))
                    ->alignment('center')
                    ->color(fn ($record) => ($record->ordered_qty - $record->planned_qty) > 0 ? 'danger' : 'success')
                    ->weight(fn ($record) => ($record->ordered_qty - $record->planned_qty) > 0 ? 'bold' : 'normal')
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state : '-'),
            ])
            ->filters([
//                TernaryFilter::make('shortage')
//                    ->label('欠品のみ')
//                    ->queries(
//                        true: fn (Builder $query) => $query->whereColumn('ordered_qty', '>', 'planned_qty'),
//                        false: fn (Builder $query) => $query->whereColumn('ordered_qty', '<=', 'planned_qty'),
//                        blank: fn (Builder $query) => $query,
//                    ),
                SelectFilter::make('partner')
                    ->label('得意先')
                    ->options(function () {
                        // Get pickingTaskId from URL query parameters
                        $pickingTaskId = request()->input('tableFilters.picking_task_id.value');

                        if (!$pickingTaskId) {
                            return [];
                        }

                        // Get unique partners from this picking task

                        $partners = Partner::whereHas('trades.wmsPickingItemResults', function (Builder $query) use ($pickingTaskId) {
                            $query->where('picking_task_id', $pickingTaskId);
                        })->get();
                        return $partners->pluck('name', 'id')->toArray();
                    })
                    ->query(function (Builder $query, $state) {
                        if (!empty($state['value'])) {
                            return $query->whereHas('earning.buyer.partner', function ($q) use ($state) {
                                $q->where('id', $state['value']);
                            });
                        }
                        return $query;
                    }),
                SelectFilter::make('item')
                    ->label('商品')
                    ->relationship('item', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('picking_task_id')
                    ->label('タスクID')
                    ->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingItemEdits::route('/'),
        ];
    }
}
