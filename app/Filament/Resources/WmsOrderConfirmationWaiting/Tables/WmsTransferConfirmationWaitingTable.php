<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsStockTransferCandidate;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsTransferConfirmationWaitingTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'transfer-confirmation-waiting-table'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('計算時刻')
                    ->state(function ($record) {
                        return \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('m/d H:i');
                    })
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('satelliteWarehouse.name')
                    ->label('依頼倉庫')
                    ->state(fn ($record) => $record->satelliteWarehouse ? "[{$record->satelliteWarehouse->code}]{$record->satelliteWarehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->width('140px'),

                TextColumn::make('hubWarehouse.name')
                    ->label('移動元倉庫')
                    ->state(fn ($record) => $record->hubWarehouse ? "[{$record->hubWarehouse->code}]{$record->hubWarehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->width('140px'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース')
                    ->state(fn ($record) => $record->deliveryCourse?->name ?? '-')
                    ->sortable()
                    ->width('100px'),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable()
                    ->width('100px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('item.packaging')
                    ->label('規格')
                    ->alignCenter()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('item.capacity_case')
                    ->label('入数')
                    ->numeric()
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('suggested_quantity')
                    ->label('算出数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('transfer_quantity')
                    ->label('移動数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('expected_arrival_date')
                    ->label('移動出荷日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::APPROVED->value => CandidateStatus::APPROVED->label(),
                    ]),

                SelectFilter::make('satellite_warehouse_id')
                    ->label('依頼倉庫')
                    ->relationship('satelliteWarehouse', 'name'),

                SelectFilter::make('hub_warehouse_id')
                    ->label('移動元倉庫')
                    ->relationship('hubWarehouse', 'name'),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($contractor) => [
                            $contractor->id => "[{$contractor->code}]{$contractor->name}",
                        ]))
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('cancelApproval')
                    ->label('承認取消')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CandidateStatus::APPROVED)
                    ->requiresConfirmation()
                    ->modalHeading('承認取消')
                    ->modalDescription('この移動候補の承認を取り消し、承認前に戻します。')
                    ->action(function ($record) {
                        $record->update([
                            'status' => CandidateStatus::PENDING,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);
                        Notification::make()
                            ->title('承認を取り消しました')
                            ->warning()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkCancelApproval')
                        ->label('選択の承認取消')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('一括承認取消')
                        ->modalDescription('選択した移動候補の承認を取り消します。')
                        ->action(function (Collection $records) {
                            // APPROVED状態のIDのみ抽出
                            $approvedIds = $records
                                ->filter(fn ($r) => $r->status === CandidateStatus::APPROVED)
                                ->pluck('id')
                                ->toArray();

                            if (empty($approvedIds)) {
                                Notification::make()
                                    ->title('承認済みのレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // 一括更新
                            $count = WmsStockTransferCandidate::whereIn('id', $approvedIds)
                                ->update([
                                    'status' => CandidateStatus::PENDING,
                                    'modified_by' => auth()->id(),
                                    'modified_at' => now(),
                                ]);

                            Notification::make()
                                ->title("{$count}件の承認を取り消しました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('batch_code', 'desc');
    }
}
