<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderExecutionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsOrderConfirmedTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-confirmed-table'])
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

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('170px'),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->wrap()
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

                TextColumn::make('transmitted_at')
                    ->label('送信日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->alignCenter()
                    ->width('90px'),

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
                        CandidateStatus::CONFIRMED->value => CandidateStatus::CONFIRMED->label(),
                        CandidateStatus::EXECUTED->value => CandidateStatus::EXECUTED->label(),
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('在庫拠点倉庫')
                    ->relationship('warehouse', 'name'),

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
                Action::make('reconfirm')
                    ->label('再確定')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === CandidateStatus::CONFIRMED)
                    ->requiresConfirmation()
                    ->modalHeading('発注再確定')
                    ->modalDescription('この発注候補を再確定し、入庫予定を再作成します。既存の入庫予定（PENDING状態）は削除されます。')
                    ->action(function ($record) {
                        $service = app(OrderExecutionService::class);

                        try {
                            $schedules = $service->confirmCandidate($record, auth()->id());
                            Notification::make()
                                ->title('発注を再確定しました')
                                ->body("入庫予定 {$schedules->count()}件 を再作成しました")
                                ->success()
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
                    ->modalHeading('発注候補詳細')
                    ->modalWidth('6xl')
                    ->schema(function (?WmsOrderCandidate $record): array {
                        if (! $record) {
                            return [];
                        }

                        $log = WmsOrderCalculationLog::where('batch_code', $record->batch_code)
                            ->where('warehouse_id', $record->warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->first();

                        $details = $log?->calculation_details ?? [];
                        $item = $record->item;
                        $capacityText = '-';
                        if ($item) {
                            $parts = [];
                            if ($item->capacity_case) {
                                $parts[] = "ケース: {$item->capacity_case}";
                            }
                            if ($item->capacity_carton) {
                                $parts[] = "ボール: {$item->capacity_carton}";
                            }
                            $capacityText = implode(' / ', $parts) ?: '-';
                        }

                        return [
                            Grid::make(3)
                                ->schema([
                                    View::make('filament.components.order-candidate-left-panel')
                                        ->viewData([
                                            'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('Y/m/d H:i'),
                                            'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                            'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                            'expectedArrivalDate' => $record->expected_arrival_date
                                                ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                                                : '-',
                                            'itemCode' => $item?->code ?? '-',
                                            'itemName' => $item?->name ?? '-',
                                            'packaging' => $item?->packaging ?? '-',
                                            'capacityText' => $capacityText,
                                        ])
                                        ->columnSpan(1),

                                    Section::make('発注情報')
                                        ->schema([
                                            View::make('filament.components.order-candidate-right-panel')
                                                ->viewData([
                                                    'selfShortageQty' => $record->self_shortage_qty ?? 0,
                                                    'satelliteDemandQty' => $record->satellite_demand_qty ?? 0,
                                                    'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                                    'hasCalculationLog' => ! empty($details),
                                                    'formula' => $details['計算式'] ?? '-',
                                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                                    'incomingStock' => $details['入庫予定数'] ?? 0,
                                                    'hasTransferIncoming' => isset($details['移動入庫予定']),
                                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                                    'hasTransferOutgoing' => isset($details['移動出庫予定']),
                                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                                    'safetyStock' => $details['安全在庫'] ?? 0,
                                                    'calculatedAvailable' => $details['利用可能在庫'] ?? 0,
                                                    'shortageQty' => $details['不足数'] ?? 0,
                                                ]),

                                            Section::make('確定情報')
                                                ->schema([
                                                    View::make('filament.components.order-confirmed-info')
                                                        ->viewData([
                                                            'orderQuantity' => $record->order_quantity,
                                                            'status' => $record->status->label(),
                                                            'statusColor' => $record->status->color(),
                                                            'transmittedAt' => $record->transmitted_at
                                                                ? $record->transmitted_at->format('Y/m/d H:i')
                                                                : null,
                                                        ]),
                                                ]),
                                        ])
                                        ->columnSpan(2),
                                ]),
                        ];
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkReconfirm')
                        ->label('選択を再確定')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('一括再確定')
                        ->modalDescription('選択した発注候補を再確定し、入庫予定を再作成します。')
                        ->action(function (Collection $records) {
                            $service = app(OrderExecutionService::class);
                            $successCount = 0;
                            $totalSchedules = 0;

                            foreach ($records as $record) {
                                if ($record->status !== CandidateStatus::CONFIRMED) {
                                    continue;
                                }

                                try {
                                    $schedules = $service->confirmCandidate($record, auth()->id());
                                    $successCount++;
                                    $totalSchedules += $schedules->count();
                                } catch (\Exception $e) {
                                    // Skip errors
                                }
                            }

                            Notification::make()
                                ->title("{$successCount}件を再確定しました")
                                ->body("入庫予定 {$totalSchedules}件 を再作成しました")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('batch_code', 'desc');
    }
}
