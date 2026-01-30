<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsOrderConfirmationWaitingTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-confirmation-waiting-table'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->width('120px'),

                TextColumn::make('batch_code_formatted')
                    ->label('実行時刻')
                    ->state(function ($record) {
                        return \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('m/d H:i');
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('batch_code', $direction))
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

                TextInputColumn::make('order_quantity')
                    ->label('発注数')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->alignEnd()
                    ->width('70px')
                    ->extraInputAttributes(['style' => 'width: 65px; text-align: right;'])
                    ->disabled(fn ($record) => ! $record->status->isEditable())
                    ->afterStateUpdated(function ($record, $state) {
                        $record->update([
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);
                    }),

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
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::APPROVED->value => CandidateStatus::APPROVED->label(),
                        CandidateStatus::CONFIRMED->value => CandidateStatus::CONFIRMED->label(),
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
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注候補詳細')
                    ->modalWidth('6xl')
                    ->fillForm(fn ($record) => [
                        'order_quantity' => $record->order_quantity,
                        'expected_arrival_date' => $record->expected_arrival_date,
                    ])
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

                        // 入荷予定日の算出理由
                        $leadTimeDays = $log?->lead_time_days ?? 0;
                        $arrivalDateAdjustment = $details['到着日調整'] ?? 0;

                        return [
                            Grid::make(3)
                                ->schema([
                                    View::make('filament.components.order-candidate-left-panel-with-arrival')
                                        ->viewData([
                                            'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('Y/m/d H:i'),
                                            'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                            'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                            'expectedArrivalDate' => $record->expected_arrival_date
                                                ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                                                : '-',
                                            'originalArrivalDate' => $record->original_arrival_date
                                                ? \Carbon\Carbon::parse($record->original_arrival_date)->format('Y/m/d')
                                                : '-',
                                            'leadTimeDays' => $leadTimeDays,
                                            'arrivalDateAdjustment' => $arrivalDateAdjustment,
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
                                                    'purchaseUnit' => $details['最小仕入単位'] ?? 1,
                                                    'purchaseUnitAdjustment' => $details['単位調整説明'] ?? null,
                                                    'orderQuantity' => $record->order_quantity ?? 0,
                                                ]),

                                            Section::make('発注数・入荷予定日変更')
                                                ->schema([
                                                    Grid::make(2)
                                                        ->schema([
                                                            TextInput::make('order_quantity')
                                                                ->label('発注数')
                                                                ->numeric()
                                                                ->required()
                                                                ->minValue(0)
                                                                ->disabled(! $record->status->isEditable())
                                                                ->helperText(! $record->status->isEditable() ? 'このステータスでは変更できません' : null),

                                                            DatePicker::make('expected_arrival_date')
                                                                ->label('入荷予定日')
                                                                ->required()
                                                                ->disabled(! $record->status->isEditable())
                                                                ->helperText(! $record->status->isEditable() ? 'このステータスでは変更できません' : null),
                                                        ]),
                                                ]),
                                        ])
                                        ->columnSpan(2),
                                ]),
                        ];
                    })
                    ->action(function ($record, array $data) {
                        if (! $record->status->isEditable()) {
                            Notification::make()
                                ->title('このステータスでは編集できません')
                                ->warning()
                                ->send();

                            return;
                        }

                        $updated = false;
                        $updateData = [
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ];

                        if ($data['order_quantity'] != $record->order_quantity) {
                            $updateData['order_quantity'] = $data['order_quantity'];
                            $updated = true;
                        }

                        $newArrivalDate = $data['expected_arrival_date'] instanceof \Carbon\Carbon
                            ? $data['expected_arrival_date']->format('Y-m-d')
                            : $data['expected_arrival_date'];
                        $currentArrivalDate = $record->expected_arrival_date
                            ? $record->expected_arrival_date->format('Y-m-d')
                            : null;

                        if ($newArrivalDate !== $currentArrivalDate) {
                            $updateData['expected_arrival_date'] = $newArrivalDate;
                            $updated = true;
                        }

                        if ($updated) {
                            $record->update($updateData);
                            Notification::make()
                                ->title('発注候補を更新しました')
                                ->success()
                                ->send();
                        }
                    }),

                Action::make('cancelApproval')
                    ->label('承認取消')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CandidateStatus::APPROVED)
                    ->requiresConfirmation()
                    ->modalHeading('承認取消')
                    ->modalDescription('この発注候補の承認を取り消し、承認前に戻します。')
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
                        ->modalDescription('選択した発注候補の承認を取り消します。')
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
                            $count = WmsOrderCandidate::whereIn('id', $approvedIds)
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
