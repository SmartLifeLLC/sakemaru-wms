<?php

namespace App\Filament\Resources\WmsOrderCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Models\Concerns\OptimisticLockException;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\WarehouseContractor;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\OrderAuditService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WmsOrderCandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-candidates-table sticky-actions'])
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

                TextColumn::make('supplier.partner_name')
                    ->label('仕入先')
                    ->state(fn ($record) => $record->supplier ? "[{$record->supplier->partner_code}]{$record->supplier->partner_name}" : '-')
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('ordering_code')
                    ->label('発注コード')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('120px'),

                // 在庫・発注関連カラム（順序: 現在庫→移動依頼→入庫予定→計算後在庫→発注点→不足分→入数→発注数）
                TextColumn::make('current_stock')
                    ->label('現在庫')
                    ->state(fn ($record) => $record->current_stock ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->width('55px'),

                TextColumn::make('satellite_demand_qty')
                    ->label('移動依頼')
                    ->numeric()
                    ->alignEnd()
                    ->width('55px'),

                TextColumn::make('incoming_quantity_override')
                    ->label('入庫数')
                    ->state(fn ($record) => $record->incoming_quantity_override ?? $record->original_incoming_quantity ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->width('65px'),

                TextColumn::make('calculated_available')
                    ->label('見込在庫')
                    ->state(fn ($record) => $record->calculated_available ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->width('65px'),

                TextColumn::make('safety_stock')
                    ->label('発注点')
                    ->state(fn ($record) => $record->safety_stock ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->width('55px'),

                TextColumn::make('shortage_qty')
                    ->label('不足分')
                    ->state(fn ($record) => $record->shortage_qty ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->width('55px')
                    ->color(fn ($record) => ($record->shortage_qty ?? 0) > 0 ? 'danger' : null),

                TextColumn::make('item.capacity_case')
                    ->label('入数')
                    ->numeric()
                    ->alignCenter()
                    ->width('50px'),

                TextInputColumn::make('order_quantity')
                    ->label('発注数')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->alignEnd()
                    ->width('70px')
                    ->summarize(
                        Sum::make()
                            ->label('合計')
                    )
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

                TextColumn::make('purchase_unit_price')
                    ->label('仕入単価')
                    ->state(fn ($record) => $record->purchase_unit_price !== null ? number_format((float) $record->purchase_unit_price, 2) : '-')
                    ->alignEnd()
                    ->toggleable()
                    ->width('80px'),

                TextColumn::make('purchase_total')
                    ->label('仕入合計')
                    ->state(function ($record) {
                        if ($record->purchase_unit_price === null || ! $record->order_quantity) {
                            return '-';
                        }
                        $total = (float) $record->purchase_unit_price * $record->order_quantity;

                        return number_format($total, 0);
                    })
                    ->alignEnd()
                    ->toggleable()
                    ->width('90px')
                    ->summarize(
                        Summarizer::make()
                            ->label('合計')
                            ->numeric(thousandsSeparator: ',')
                            ->using(function (Builder $query) {
                                // 仕入合計 = purchase_unit_price * order_quantity
                                // 画面で数量変更されうるため、DBの現在値を集計する。
                                return (float) $query->sum(
                                    DB::raw('COALESCE(purchase_unit_price, 0) * COALESCE(order_quantity, 0)')
                                );
                            })
                    ),

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
                SelectFilter::make('batch_code')
                    ->label('実行CD')
                    ->options(fn () => WmsOrderCandidate::query()
                        ->select('batch_code')
                        ->distinct()
                        ->orderByDesc('batch_code')
                        ->limit(50)
                        ->pluck('batch_code', 'batch_code')
                        ->toArray())
                    ->searchable(),

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

                SelectFilter::make('supplier_id')
                    ->label('仕入先')
                    ->options(fn () => Supplier::query()
                        ->with('partner')
                        ->whereHas('partner')
                        ->get()
                        ->sortBy(fn ($s) => $s->partner?->code)
                        ->mapWithKeys(fn ($supplier) => [
                            $supplier->id => "[{$supplier->partner_code}]{$supplier->partner_name}",
                        ]))
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        // 全角英数字を半角に変換
                        $search = mb_convert_kana($search, 'as');

                        return Supplier::query()
                            ->with('partner')
                            ->whereHas('partner', function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->limit(50)
                            ->get()
                            ->sortBy(fn ($s) => $s->partner?->code)
                            ->mapWithKeys(fn ($supplier) => [
                                $supplier->id => "[{$supplier->partner_code}]{$supplier->partner_name}",
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
                    ->modalDescription(function () {
                        $pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
                        if ($pendingTransferCount > 0) {
                            return "⚠️ 移動候補に未承認のデータが {$pendingTransferCount}件 あります。先に移動候補を承認してください。";
                        }

                        return 'この発注候補を承認しますか？';
                    })
                    ->action(function ($record) {
                        // 移動候補にPENDINGが残っている場合は承認不可
                        $pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
                        if ($pendingTransferCount > 0) {
                            Notification::make()
                                ->title('発注承認不可')
                                ->body("移動候補に未承認のデータが {$pendingTransferCount}件 あります。先に移動候補を承認してください。")
                                ->danger()
                                ->send();

                            return;
                        }

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

                        // 発注先ロット条件を取得
                        $warehouseContractor = WarehouseContractor::where('warehouse_id', $record->warehouse_id)
                            ->where('contractor_id', $record->contractor_id)
                            ->first();
                        $lotConditions = $warehouseContractor ? [
                            $warehouseContractor->lot_condition_1,
                            $warehouseContractor->lot_condition_2,
                            $warehouseContractor->lot_condition_3,
                            $warehouseContractor->lot_condition_4,
                        ] : [];

                        // JANコード取得
                        $janCode = $item?->piece_jan_code_information?->search_string;

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
                                            'itemKana' => $item?->kana ?? '',
                                            'janCode' => $janCode ?? '',
                                            'packaging' => $item?->packaging ?? '-',
                                            'capacityText' => $capacityText,
                                            'lotConditions' => $lotConditions,
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
                                                                ->disabled($record->status !== CandidateStatus::PENDING)
                                                                ->helperText(
                                                                    $record->status !== CandidateStatus::PENDING
                                                                        ? '承認後は変更できません'
                                                                        : null
                                                                ),

                                                            DatePicker::make('expected_arrival_date')
                                                                ->label('入荷予定日')
                                                                ->required()
                                                                ->disabled($record->status !== CandidateStatus::PENDING)
                                                                ->helperText(
                                                                    $record->status !== CandidateStatus::PENDING
                                                                        ? '承認後は変更できません'
                                                                        : null
                                                                ),
                                                        ]),
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
                                ->title('承認後は変更できません')
                                ->danger()
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
                            $oldQuantity = $record->order_quantity;
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
                            try {
                                $record->updateWithLock($updateData);

                                // 監査ログ（発注数が変更された場合のみ）
                                if (isset($oldQuantity)) {
                                    app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $data['order_quantity']);
                                }

                                Notification::make()
                                    ->title('発注候補を更新しました')
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
                        ->modalDescription(function () {
                            $pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
                            if ($pendingTransferCount > 0) {
                                return "⚠️ 移動候補に未承認のデータが {$pendingTransferCount}件 あります。先に移動候補を承認してください。";
                            }

                            return '選択した発注候補を承認しますか？';
                        })
                        ->action(function (Collection $records) {
                            // 移動候補にPENDINGが残っている場合は承認不可
                            $pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
                            if ($pendingTransferCount > 0) {
                                Notification::make()
                                    ->title('発注承認不可')
                                    ->body("移動候補に未承認のデータが {$pendingTransferCount}件 あります。先に移動候補を承認してください。")
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // 一括UPDATEで高速化（N+1問題を解消）
                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (! empty($pendingIds)) {
                                WmsOrderCandidate::whereIn('id', $pendingIds)->update([
                                    'status' => CandidateStatus::APPROVED,
                                    'updated_at' => now(),
                                ]);
                            }

                            $count = count($pendingIds);

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
                            // 一括UPDATEで高速化（N+1問題を解消）
                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (! empty($pendingIds)) {
                                WmsOrderCandidate::whereIn('id', $pendingIds)->update([
                                    'status' => CandidateStatus::EXCLUDED,
                                    'exclusion_reason' => $data['exclusion_reason'] ?? null,
                                    'updated_at' => now(),
                                ]);
                            }

                            $count = count($pendingIds);

                            Notification::make()
                                ->title("{$count}件を除外しました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('batch_code', 'desc');
    }
}
