<?php

namespace App\Filament\Resources\WmsOrderIncomingSchedules\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Services\AutoOrder\IncomingConfirmationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsOrderIncomingSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (IncomingScheduleStatus $state): string => $state->label())
                    ->color(fn (IncomingScheduleStatus $state): string => $state->color())
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('order_source')
                    ->label('発注元')
                    ->badge()
                    ->formatStateUsing(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => '自動',
                        OrderSource::MANUAL => '手動',
                    })
                    ->color(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => 'info',
                        OrderSource::MANUAL => 'gray',
                    })
                    ->width('60px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->width('150px'),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('search_code')
                    ->label('検索コード')
                    ->searchable()
                    ->limit(20)
                    ->placeholder('-')
                    ->width('120px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->grow(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('expected_quantity')
                    ->label('予定数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('received_quantity')
                    ->label('入庫済')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('remaining')
                    ->label('残数')
                    ->state(fn ($record) => $record->remaining_quantity)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => $record->remaining_quantity > 0 ? 'warning' : 'success')
                    ->width('70px'),

                TextColumn::make('quantity_type')
                    ->label('単位')
                    ->formatStateUsing(fn ($state) => match ($state?->value ?? $state) {
                        'PIECE' => 'バラ',
                        'CASE' => 'ケース',
                        'CARTON' => 'ボール',
                        default => '-',
                    })
                    ->alignCenter()
                    ->width('60px'),

                TextColumn::make('order_date')
                    ->label('発注日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('expected_arrival_date')
                    ->label('入庫予定日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('80px'),

                TextColumn::make('actual_arrival_date')
                    ->label('入庫日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('70px'),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y/m/d')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('order_candidate_id')
                    ->label('発注候補ID')
                    ->alignCenter()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('80px'),

                TextColumn::make('manual_order_number')
                    ->label('発注番号')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('purchase_slip_number')
                    ->label('仕入伝票')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('note')
                    ->label('備考')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(collect(IncomingScheduleStatus::cases())->mapWithKeys(fn ($status) => [
                        $status->value => $status->label(),
                    ])),

                SelectFilter::make('order_source')
                    ->label('発注元')
                    ->options([
                        'AUTO' => '自動発注',
                        'MANUAL' => '手動発注',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->relationship('contractor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('入庫処理')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => in_array($record->status, [
                        IncomingScheduleStatus::PENDING,
                        IncomingScheduleStatus::PARTIAL,
                    ]))
                    ->modalHeading('入庫処理')
                    ->schema([
                        TextInput::make('received_quantity')
                            ->label('入庫数量')
                            ->numeric()
                            ->required()
                            ->default(fn ($record) => $record->remaining_quantity)
                            ->helperText(fn ($record) => "残数: {$record->remaining_quantity}"),

                        DatePicker::make('actual_date')
                            ->label('入荷日')
                            ->default(now())
                            ->required(),

                        DatePicker::make('expiration_date')
                            ->label('賞味期限')
                            ->default(fn ($record) => $record->expiration_date)
                            ->helperText('商品の賞味期限を入力してください（任意）'),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(IncomingConfirmationService::class);

                        try {
                            $receivedQty = (int) $data['received_quantity'];
                            $remainingQty = $record->remaining_quantity;
                            $expirationDate = $data['expiration_date'] ?? null;

                            if ($receivedQty >= $remainingQty) {
                                // 全量入庫
                                $service->confirmIncoming(
                                    $record,
                                    auth()->id(),
                                    $record->expected_quantity,
                                    $data['actual_date'],
                                    $expirationDate
                                );
                                Notification::make()
                                    ->title('入庫を確定しました')
                                    ->success()
                                    ->send();
                            } else {
                                // 一部入庫
                                $service->recordPartialIncoming(
                                    $record,
                                    $receivedQty,
                                    auth()->id(),
                                    $data['actual_date'],
                                    $expirationDate
                                );
                                Notification::make()
                                    ->title('一部入庫を記録しました')
                                    ->body("入庫数: {$receivedQty} / 残数: ".($remainingQty - $receivedQty))
                                    ->success()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('キャンセル')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => in_array($record->status, [
                        IncomingScheduleStatus::PENDING,
                        IncomingScheduleStatus::PARTIAL,
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('入庫予定をキャンセル')
                    ->modalDescription('この入庫予定をキャンセルしますか？')
                    ->schema([
                        Textarea::make('reason')
                            ->label('キャンセル理由'),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(IncomingConfirmationService::class);

                        try {
                            $service->cancelIncoming(
                                $record,
                                auth()->id(),
                                $data['reason'] ?? ''
                            );
                            Notification::make()
                                ->title('入庫予定をキャンセルしました')
                                ->warning()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('入庫予定詳細')
                    ->modalWidth('lg')
                    ->schema(function ($record) {
                        return [
                            \Filament\Schemas\Components\Section::make('基本情報')
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('warehouse')
                                        ->label('倉庫')
                                        ->state(fn () => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-'),
                                    \Filament\Infolists\Components\TextEntry::make('item')
                                        ->label('商品')
                                        ->state(fn () => $record->item ? "[{$record->item->code}]{$record->item->name}" : '-'),
                                    \Filament\Infolists\Components\TextEntry::make('contractor')
                                        ->label('発注先')
                                        ->state(fn () => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-'),
                                ]),
                            \Filament\Schemas\Components\Section::make('数量')
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('expected_quantity')
                                        ->label('予定数量')
                                        ->state(fn () => $record->expected_quantity),
                                    \Filament\Infolists\Components\TextEntry::make('received_quantity')
                                        ->label('入庫済数量')
                                        ->state(fn () => $record->received_quantity),
                                    \Filament\Infolists\Components\TextEntry::make('remaining')
                                        ->label('残数量')
                                        ->state(fn () => $record->remaining_quantity),
                                ]),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkUpdateDates')
                        ->label('入荷日・賞味期限を更新')
                        ->icon('heroicon-o-calendar')
                        ->color('info')
                        ->modalHeading('入荷日・賞味期限の一括更新')
                        ->modalDescription('選択した入庫予定の入荷日・賞味期限を更新します（確定は行いません）')
                        ->schema([
                            DatePicker::make('actual_arrival_date')
                                ->label('入荷日')
                                ->helperText('空欄の場合は更新しません'),

                            DatePicker::make('expiration_date')
                                ->label('賞味期限')
                                ->helperText('空欄の場合は更新しません'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $validRecords = $records->filter(fn ($r) => in_array($r->status, [
                                IncomingScheduleStatus::PENDING,
                                IncomingScheduleStatus::PARTIAL,
                            ]));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('更新可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $updateData = [];
                            if (! empty($data['actual_arrival_date'])) {
                                $updateData['actual_arrival_date'] = $data['actual_arrival_date'];
                            }
                            if (! empty($data['expiration_date'])) {
                                $updateData['expiration_date'] = $data['expiration_date'];
                            }

                            if (empty($updateData)) {
                                Notification::make()
                                    ->title('更新する項目がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($validRecords as $record) {
                                $record->update($updateData);
                                $count++;
                            }

                            Notification::make()
                                ->title("{$count}件を更新しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkConfirm')
                        ->label('選択を入庫確定')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('一括入庫確定')
                        ->modalDescription('選択した入庫予定を全量入庫確定します。')
                        ->schema([
                            DatePicker::make('actual_date')
                                ->label('入庫日')
                                ->default(now())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(IncomingConfirmationService::class);
                            $validRecords = $records->filter(fn ($r) => in_array($r->status, [
                                IncomingScheduleStatus::PENDING,
                                IncomingScheduleStatus::PARTIAL,
                            ]));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('確定可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $result = $service->confirmMultiple(
                                $validRecords->pluck('id')->toArray(),
                                auth()->id(),
                                $data['actual_date']
                            );

                            Notification::make()
                                ->title("{$result['success']}件を入庫確定しました")
                                ->body($result['failed'] > 0 ? "{$result['failed']}件でエラーが発生" : null)
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkCancel')
                        ->label('選択をキャンセル')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('一括キャンセル')
                        ->modalDescription('選択した入庫予定をキャンセルします。')
                        ->schema([
                            Textarea::make('reason')
                                ->label('キャンセル理由'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(IncomingConfirmationService::class);
                            $validRecords = $records->filter(fn ($r) => in_array($r->status, [
                                IncomingScheduleStatus::PENDING,
                                IncomingScheduleStatus::PARTIAL,
                            ]));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('キャンセル可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($validRecords as $record) {
                                try {
                                    $service->cancelIncoming($record, auth()->id(), $data['reason'] ?? '');
                                    $count++;
                                } catch (\Exception $e) {
                                    // Skip errors
                                }
                            }

                            Notification::make()
                                ->title("{$count}件をキャンセルしました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('expected_arrival_date', 'asc');
    }
}
