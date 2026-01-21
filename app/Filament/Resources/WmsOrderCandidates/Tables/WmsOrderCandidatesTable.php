<?php

namespace App\Filament\Resources\WmsOrderCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\Concerns\OptimisticLockException;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderAuditService;
use App\Services\AutoOrder\OrderValidationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
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

class WmsOrderCandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-candidates-table'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('計算時刻')
                    ->state(function ($record) {
                        // batch_code は YmdHis 形式 (例: 20251227230547)
                        return \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('m/d H:i');
                    })
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

                TextColumn::make('safety_stock')
                    ->label('発注点')
                    ->state(fn ($record) => $record->safety_stock ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('self_shortage_qty')
                    ->label('倉庫不足')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px')
                    ->toggleable(),

                TextColumn::make('satellite_demand_qty')
                    ->label('移動依頼')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px')
                    ->toggleable(),

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
                    // 承認前（PENDING）のみ編集可能
                    ->disabled(fn ($record) => $record->status !== CandidateStatus::PENDING)
                    ->afterStateUpdated(function ($record, $state) {
                        // 承認後の編集は許可しない
                        if ($record->status !== CandidateStatus::PENDING) {
                            Notification::make()
                                ->title('承認後は発注数量を変更できません')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $oldQuantity = $record->order_quantity;
                            $newQuantity = (int) $state;

                            $record->updateWithLock([
                                'order_quantity' => $newQuantity,
                                'is_manually_modified' => true,
                                'modified_by' => auth()->id(),
                                'modified_at' => now(),
                            ]);

                            // 監査ログ（数量が実際に変更された場合のみ）
                            if ($oldQuantity !== $newQuantity) {
                                app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $newQuantity);
                            }
                        } catch (OptimisticLockException $e) {
                            Notification::make()
                                ->title('更新エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable()
                    ->width('75px'),

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

                TextColumn::make('transmission_status')
                    ->label('送信')
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
                        CandidateStatus::PENDING->value => CandidateStatus::PENDING->label(),
                        CandidateStatus::EXCLUDED->value => CandidateStatus::EXCLUDED->label(),
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('発注倉庫')
                    ->relationship('warehouse', 'name'),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($contractor) => [
                            $contractor->id => "[{$contractor->code}]{$contractor->name}",
                        ]))
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        // 全角英数字を半角に変換
                        $search = mb_convert_kana($search, 'as');

                        return Contractor::query()
                            ->where(function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($contractor) => [
                                $contractor->id => "[{$contractor->code}]{$contractor->name}",
                            ])
                            ->toArray();
                    }),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('承認')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        try {
                            $record->updateWithLock(['status' => CandidateStatus::APPROVED]);

                            // 監査ログ
                            app(OrderAuditService::class)->logApproval($record);

                            Notification::make()
                                ->title('発注候補を承認しました')
                                ->success()
                                ->send();
                        } catch (OptimisticLockException $e) {
                            Notification::make()
                                ->title('承認エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('viewCalculation')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注候補詳細')
                    ->modalWidth('6xl')
                    ->fillForm(fn ($record) => [
                        'order_quantity' => $record->order_quantity,
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

                                            Section::make('発注数変更')
                                                ->schema([
                                                    TextInput::make('order_quantity')
                                                        ->label('発注数')
                                                        ->numeric()
                                                        ->required()
                                                        ->minValue(0)
                                                        // 承認前（PENDING）のみ編集可能
                                                        ->disabled($record->status !== CandidateStatus::PENDING)
                                                        ->helperText(
                                                            $record->status !== CandidateStatus::PENDING
                                                                ? '承認後は発注数量を変更できません'
                                                                : null
                                                        ),
                                                ]),
                                        ])
                                        ->columnSpan(2),
                                ]),
                        ];
                    })
                    ->action(function ($record, array $data) {
                        // 承認後の編集は許可しない
                        if ($record->status !== CandidateStatus::PENDING) {
                            Notification::make()
                                ->title('承認後は発注数量を変更できません')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($data['order_quantity'] != $record->order_quantity) {
                            try {
                                $oldQuantity = $record->order_quantity;
                                $newQuantity = $data['order_quantity'];

                                $record->updateWithLock([
                                    'order_quantity' => $newQuantity,
                                    'is_manually_modified' => true,
                                    'modified_by' => auth()->id(),
                                    'modified_at' => now(),
                                ]);

                                // 監査ログ
                                app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $newQuantity);

                                Notification::make()
                                    ->title('発注数を更新しました')
                                    ->success()
                                    ->send();
                            } catch (OptimisticLockException $e) {
                                Notification::make()
                                    ->title('更新エラー')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }
                    }),

                Action::make('exclude')
                    ->label('除外')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->schema([
                        Textarea::make('exclusion_reason')
                            ->label('除外理由'),
                    ])
                    ->action(function ($record, array $data) {
                        try {
                            $reason = $data['exclusion_reason'] ?? null;
                            $record->updateWithLock([
                                'status' => CandidateStatus::EXCLUDED,
                                'exclusion_reason' => $reason,
                            ]);

                            // 監査ログ
                            app(OrderAuditService::class)->logExclusion($record, $reason);

                            Notification::make()
                                ->title('発注候補を除外しました')
                                ->warning()
                                ->send();
                        } catch (OptimisticLockException $e) {
                            Notification::make()
                                ->title('除外エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
                                ->label('除外理由'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->each(fn ($record) => $record->update([
                                    'status' => CandidateStatus::EXCLUDED,
                                    'exclusion_reason' => $data['exclusion_reason'] ?? null,
                                ]))
                                ->count();

                            Notification::make()
                                ->title("{$count}件を除外しました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
