<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\WmsOrderCandidate;
use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsOrderConfirmedTable
{
    use HasExportAction;
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-confirmed-table sticky-actions'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->sortable()
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('executed_at')
                    ->label('実行時刻')
                    ->state(function ($record) {
                        try {
                            return \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)
                                ->format('m月d日 H時i分');
                        } catch (\Exception $e) {
                            return '-';
                        }
                    })
                    ->width('110px'),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

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

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('order_quantity')
                    ->label('発注数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定')
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
                SelectFilter::make('batch_code')
                    ->label('実行CD')
                    ->options(fn () => WmsOrderCandidate::query()
                        ->whereIn('status', [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])
                        ->select('batch_code')
                        ->distinct()
                        ->orderByDesc('batch_code')
                        ->limit(50)
                        ->pluck('batch_code', 'batch_code')
                        ->toArray())
                    ->default(fn () => WmsOrderCandidate::query()
                        ->whereIn('status', [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])
                        ->orderByDesc('batch_code')
                        ->value('batch_code'))
                    ->searchable(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::CONFIRMED->value => CandidateStatus::CONFIRMED->label(),
                        CandidateStatus::EXECUTED->value => CandidateStatus::EXECUTED->label(),
                    ]),

                static::warehouseFilter(),

                static::contractorFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注確定詳細')
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->infolist(function (?WmsOrderCandidate $record): array {
                        if (! $record) {
                            return [];
                        }

                        $item = $record->item;

                        return [
                            Grid::make(2)
                                ->schema([
                                    View::make('filament.components.order-confirmed-detail-left')
                                        ->viewData([
                                            'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('Y/m/d H:i'),
                                            'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                            'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                            'itemCode' => $item?->code ?? '-',
                                            'itemName' => $item?->name ?? '-',
                                            'expectedArrivalDate' => $record->expected_arrival_date
                                                ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                                                : '-',
                                        ])
                                        ->columnSpan(1),

                                    View::make('filament.components.order-confirmed-detail-right')
                                        ->viewData([
                                            'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                            'orderQuantity' => $record->order_quantity ?? 0,
                                            'status' => $record->status->label(),
                                            'statusColor' => $record->status->color(),
                                            'transmittedAt' => $record->transmitted_at
                                                ? $record->transmitted_at->format('Y/m/d H:i')
                                                : null,
                                        ])
                                        ->columnSpan(1),
                                ]),
                        ];
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('batch_code', 'desc');
    }
}
