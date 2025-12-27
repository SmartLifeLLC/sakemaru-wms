<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsStockTransferCandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_code')
                    ->label('バッチコード')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('satelliteWarehouse.name')
                    ->label('Satellite倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('hubWarehouse.name')
                    ->label('Hub倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item.item_name')
                    ->label('商品')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('contractor.contractor_name')
                    ->label('発注先')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('suggested_quantity')
                    ->label('算出数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('transfer_quantity')
                    ->label('移動数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($record) => $record->transfer_quantity !== $record->suggested_quantity ? 'warning' : null),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定日')
                    ->date()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (CandidateStatus $state): string => match ($state) {
                        CandidateStatus::PENDING => 'gray',
                        CandidateStatus::APPROVED => 'success',
                        CandidateStatus::EXCLUDED => 'danger',
                        CandidateStatus::TRANSMITTED => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('lot_status')
                    ->label('ロット')
                    ->badge()
                    ->color(fn (LotStatus $state): string => match ($state) {
                        LotStatus::RAW => 'gray',
                        LotStatus::APPLIED => 'success',
                        LotStatus::BLOCKED => 'danger',
                        LotStatus::NEED_APPROVAL => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('is_manually_modified')
                    ->label('手動修正')
                    ->state(fn ($record) => $record->is_manually_modified ? '修正済' : '-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('batch_code')
                    ->label('バッチコード')
                    ->options(fn () => WmsStockTransferCandidate::distinct()->pluck('batch_code', 'batch_code')),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(CandidateStatus::class),

                SelectFilter::make('lot_status')
                    ->label('ロットステータス')
                    ->options(LotStatus::class),

                SelectFilter::make('satellite_warehouse_id')
                    ->label('Satellite倉庫')
                    ->relationship('satelliteWarehouse', 'name'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('承認')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update(['status' => CandidateStatus::APPROVED]);
                        Notification::make()
                            ->title('移動候補を承認しました')
                            ->success()
                            ->send();
                    }),

                Action::make('exclude')
                    ->label('除外')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->schema([
                        Textarea::make('exclusion_reason')
                            ->label('除外理由')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'status' => CandidateStatus::EXCLUDED,
                            'exclusion_reason' => $data['exclusion_reason'],
                        ]);
                        Notification::make()
                            ->title('移動候補を除外しました')
                            ->warning()
                            ->send();
                    }),

                Action::make('modifyQuantity')
                    ->label('数量修正')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->visible(fn ($record) => in_array($record->status, [CandidateStatus::PENDING, CandidateStatus::APPROVED]))
                    ->schema([
                        TextInput::make('transfer_quantity')
                            ->label('移動数量')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->default(fn ($record) => $record->transfer_quantity),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'transfer_quantity' => $data['transfer_quantity'],
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);
                        Notification::make()
                            ->title('移動数量を修正しました')
                            ->success()
                            ->send();
                    }),

                Action::make('viewCalculation')
                    ->label('計算詳細')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->modalHeading('計算詳細')
                    ->modalSubmitAction(false)
                    ->infolist(function ($record) {
                        $log = WmsOrderCalculationLog::where('batch_code', $record->batch_code)
                            ->where('warehouse_id', $record->satellite_warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->first();

                        if (!$log) {
                            return [
                                TextEntry::make('no_log')
                                    ->label('')
                                    ->state('計算ログが見つかりません'),
                            ];
                        }

                        $details = $log->calculation_details ?? [];

                        return [
                            TextEntry::make('formula')
                                ->label('計算式')
                                ->state($details['formula'] ?? '-'),
                            TextEntry::make('effective_stock')
                                ->label('有効在庫')
                                ->state(number_format($details['effective_stock'] ?? 0)),
                            TextEntry::make('incoming_stock')
                                ->label('入庫予定')
                                ->state(number_format($details['incoming_stock'] ?? 0)),
                            TextEntry::make('safety_stock')
                                ->label('発注点')
                                ->state(number_format($details['safety_stock'] ?? 0)),
                            TextEntry::make('calculated_available')
                                ->label('計算後在庫')
                                ->state(number_format($details['calculated_available'] ?? 0)),
                            TextEntry::make('shortage_qty')
                                ->label('不足数')
                                ->state(number_format($details['shortage_qty'] ?? 0))
                                ->color('danger'),
                        ];
                    }),

                DeleteAction::make()
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkApprove')
                        ->label('選択を承認')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $count = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->each(fn ($record) => $record->update(['status' => CandidateStatus::APPROVED]))
                                ->count();

                            Notification::make()
                                ->title("{$count}件を承認しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkExclude')
                        ->label('選択を除外')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->schema([
                            Textarea::make('exclusion_reason')
                                ->label('除外理由')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->each(fn ($record) => $record->update([
                                    'status' => CandidateStatus::EXCLUDED,
                                    'exclusion_reason' => $data['exclusion_reason'],
                                ]))
                                ->count();

                            Notification::make()
                                ->title("{$count}件を除外しました")
                                ->warning()
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
