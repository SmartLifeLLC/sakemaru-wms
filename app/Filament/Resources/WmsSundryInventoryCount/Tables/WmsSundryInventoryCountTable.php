<?php

namespace App\Filament\Resources\WmsSundryInventoryCount\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Resources\WmsSundryInventoryCountResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsSundryInventoryCount;
use App\Services\SundryInventoryCount\SundryInventoryCountService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class WmsSundryInventoryCountTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount([
                    'items',
                    'items as counted_items_count' => fn (Builder $query) => $query->whereNotNull('counted_quantity'),
                ])
                ->withSum('items as items_system_amount', 'system_amount')
                ->withSum('items as items_difference_amount', 'difference_amount')
                ->withSum('amounts as amounts_system_amount', 'system_amount')
                ->withSum('amounts as amounts_difference_amount', 'difference_amount'))
            ->columns([
                TextColumn::make('count_no')
                    ->label('棚卸しNo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse_code')
                    ->label('倉庫CD')
                    ->sortable(),

                TextColumn::make('warehouse_name')
                    ->label('倉庫')
                    ->sortable(),

                TextColumn::make('memo')
                    ->label('メモ')
                    ->limit(40)
                    ->placeholder('-'),

                TextColumn::make('count_date')
                    ->label('棚卸し日')
                    ->date('Y/m/d')
                    ->sortable(),

                TextColumn::make('theory_end_date')
                    ->label('受払終了日')
                    ->date('Y/m/d')
                    ->placeholder('-'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (WmsSundryInventoryCount $record) => $record->status_label)
                    ->color(fn (WmsSundryInventoryCount $record) => $record->status_color),

                TextColumn::make('progress')
                    ->label('入力')
                    ->state(fn (WmsSundryInventoryCount $record): string => (int) $record->items_count === 0
                        ? '-'
                        : ((int) $record->counted_items_count).'/'.((int) $record->items_count)),

                TextColumn::make('system_amount_total')
                    ->label('理論金額')
                    ->alignEnd()
                    ->state(fn (WmsSundryInventoryCount $record): string => '¥'.number_format(
                        (float) $record->items_system_amount + (float) $record->amounts_system_amount
                    )),

                TextColumn::make('difference_amount_total')
                    ->label('差異金額')
                    ->alignEnd()
                    ->state(fn (WmsSundryInventoryCount $record): string => '¥'.number_format(
                        (float) $record->items_difference_amount + (float) $record->amounts_difference_amount
                    )),

                TextColumn::make('createdByUser.name')
                    ->label('作成者')
                    ->placeholder('システム'),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->multiple()
                    ->options(WmsSundryInventoryCount::statusOptions()),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('count_date')
                ->orderByRaw('CAST(warehouse_code AS UNSIGNED)')
                ->orderByDesc('id'))
            ->recordActions([
                Action::make('view')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (WmsSundryInventoryCount $record) => WmsSundryInventoryCountResource::getUrl('view', ['record' => $record])),
            ], position: RecordActionsPosition::AfterColumns)
            ->extraAttributes(['class' => 'sticky-actions']);
    }

    public static function getCreateAction(): Action
    {
        return Action::make('createSundryInventoryCount')
            ->label('棚卸し（雑貨）作成')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('棚卸し（雑貨）作成')
            ->modalDescription('対象の分類の商品だけを明細にします。棚卸し日までの受払で理論在庫を作成します（未来日の場合は本日まで）。')
            ->modalWidth('lg')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(
                fn ($action) => $action->makeModalSubmitAction('submit', [])
                    ->label('作成')
                    ->color('danger')
            )
            ->modalCancelActionLabel('作成せず閉じる')
            ->schema([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::query()
                        ->where('is_active', true)
                        ->orderBy('code')
                        ->get(['id', 'code', 'name'])
                        ->mapWithKeys(fn (Warehouse $warehouse): array => [$warehouse->id => "[{$warehouse->code}]{$warehouse->name}"]))
                    ->default(fn () => auth()->user()?->getSelectedWarehouseId())
                    ->searchable()
                    ->required(),

                DatePicker::make('count_date')
                    ->label('棚卸し日')
                    ->default(now())
                    ->required(),

                Select::make('category_ids')
                    ->label('対象中分類')
                    ->options(fn () => (new SundryInventoryCountService)->categoryOptions())
                    ->default(fn () => (new SundryInventoryCountService)->defaultCategoryIds())
                    ->multiple()
                    ->searchable()
                    ->helperText('在庫管理ありの商品は数量で、在庫管理なしの商品は中分類ごとの金額で棚卸しします。対象外の商品は、作成後に「商品追加」で個別に追加できます。'),

                Select::make('amount_category_ids')
                    ->label('金額で棚卸しする大分類（在庫管理なし）')
                    ->options(fn () => (new SundryInventoryCountService)->amountCategoryOptions())
                    ->default(fn () => (new SundryInventoryCountService)->defaultAmountCategoryIds())
                    ->multiple()
                    ->searchable()
                    ->helperText('選んだ大分類は、対象中分類に無い中分類も含めて在庫管理なし商品を中分類ごとの金額で棚卸しし、合計を在庫金額報告書と突き合わせます。'),

                Textarea::make('memo')
                    ->label('メモ')
                    ->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    $count = (new SundryInventoryCountService)->create($data);
                } catch (ValidationException $e) {
                    Notification::make()
                        ->danger()
                        ->title('棚卸し（雑貨）を作成できません')
                        ->body(collect($e->errors())->flatten()->implode("\n"))
                        ->send();

                    return null;
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->danger()
                        ->title('棚卸し（雑貨）を作成できません')
                        ->body($e->getMessage())
                        ->send();

                    return null;
                }

                Notification::make()
                    ->success()
                    ->title('棚卸し（雑貨）を作成しました')
                    ->body('数量明細: '.$count->items()->count().'件 / 金額明細: '.$count->amounts()->count().'件')
                    ->send();

                return redirect(WmsSundryInventoryCountResource::getUrl('view', ['record' => $count]));
            });
    }
}
