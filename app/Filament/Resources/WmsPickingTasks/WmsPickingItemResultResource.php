<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingItemResults;
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

class WmsPickingItemResultResource extends Resource
{
    protected static ?string $model = WmsPickingItemResult::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = '欠品・引当修正';

    protected static ?string $modelLabel = 'ピッキング明細';

    protected static ?string $slug = 'wms-picking-item-results';

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::OUTBOUND->label();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(50)
            ->paginationPageOptions([25, 50, 100, 200])
            ->striped()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                TernaryFilter::make('shortage')
                    ->label('欠品のみ')
                    ->queries(
                        true: fn (Builder $query) => $query->whereColumn('ordered_qty', '>', 'planned_qty'),
                        false: fn (Builder $query) => $query->whereColumn('ordered_qty', '<=', 'planned_qty'),
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('partner')
                    ->label('得意先')
                    ->relationship('earning.buyer.partner', 'name')
                    ->searchable()
                    ->preload(),
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
            'index' => ListWmsPickingItemResults::route('/'),
        ];
    }
}
