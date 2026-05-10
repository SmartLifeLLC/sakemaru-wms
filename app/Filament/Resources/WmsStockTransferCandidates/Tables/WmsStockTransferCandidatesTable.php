<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Filament\Support\StockTransferSlipHistory;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsMonthlySafetyStock;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\TransferOrderRecalculationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WmsStockTransferCandidatesTable
{
    use HasExportAction;
    use HasModifierDisplay;
    use HasOptimizedFilters;

    protected static function getFilterModelTable(): string
    {
        return (new WmsStockTransferCandidate)->getTable();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'transfer-candidates-table sticky-actions'])
            ->columns([
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable()
                    ->toggleable()
                    ->width('75px'),

                TextColumn::make('satelliteWarehouse.name')
                    ->label('依頼倉庫')
                    ->state(fn ($record) => $record->satelliteWarehouse ? "[{$record->satelliteWarehouse->code}]{$record->satelliteWarehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('140px'),

                TextColumn::make('hubWarehouse.name')
                    ->label('移動元倉庫')
                    ->state(fn ($record) => $record->hubWarehouse ? "[{$record->hubWarehouse->code}]{$record->hubWarehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('140px'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース')
                    ->state(fn ($record) => $record->deliveryCourse?->name ?? '-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('120px'),

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('search_code')
                    ->label('発注CD')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('-')
                    ->width('120px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
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
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('50px'),

                // 在庫関連カラム（直接カラムから取得、なければ計算ログにフォールバック）
                TextColumn::make('current_effective_stock')
                    ->label('現在庫')
                    ->state(function ($record) {
                        if ($record->current_effective_stock !== null) {
                            return $record->current_effective_stock;
                        }
                        $log = $record->calculationLog;

                        return $log?->current_effective_stock ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('55px'),

                TextColumn::make('hub_effective_stock')
                    ->label('倉庫在庫')
                    ->state(fn ($record) => $record->hub_effective_stock ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('70px'),

                TextColumn::make('incoming_quantity')
                    ->label('入荷予定数')
                    ->state(function ($record) {
                        if ($record->incoming_quantity !== null) {
                            return $record->incoming_quantity;
                        }
                        $log = $record->calculationLog;

                        return $log?->incoming_quantity ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('55px'),

                TextColumn::make('calculated_available')
                    ->label('見込在庫')
                    ->state(function ($record) {
                        if ($record->calculated_available !== null) {
                            return $record->calculated_available;
                        }
                        $log = $record->calculationLog;
                        $details = $log?->calculation_details ?? [];

                        return $details['利用可能在庫'] ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('65px'),

                TextColumn::make('safety_stock')
                    ->label('発注点')
                    ->state(function ($record) {
                        if ($record->safety_stock !== null) {
                            return $record->safety_stock;
                        }
                        $log = $record->calculationLog;

                        return $log?->safety_stock_setting ?? 0;
                    })
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('60px'),

                TextColumn::make('auto_order_quantity')
                    ->label('自動発注数')
                    ->state(function ($record) {
                        $details = $record->calculationLog?->calculation_details ?? [];

                        return $details['旧自動発注数']
                            ?? static::resolveItemContractor($record)?->auto_order_quantity
                            ?? 0;
                    })
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('75px'),

                TextColumn::make('suggested_quantity')
                    ->label('算出数')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('60px'),

                TextInputColumn::make('transfer_quantity')
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
                                ->title('承認後は発注数を変更できません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $oldQuantity = $record->transfer_quantity;
                        $newQuantity = (int) $state;

                        $record->update([
                            'transfer_quantity' => $newQuantity,
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);

                        // 移動数量が変更された場合、関連発注候補を再計算
                        if ($oldQuantity !== $newQuantity) {
                            $recalcService = app(TransferOrderRecalculationService::class);
                            $updatedOrder = $recalcService->recalculateOrderForTransfer($record, $oldQuantity, $newQuantity);

                            if ($updatedOrder) {
                                Notification::make()
                                    ->title('発注数を更新しました')
                                    ->body("関連発注候補の発注数も {$updatedOrder->order_quantity} に再計算されました。")
                                    ->success()
                                    ->send();

                                return;
                            }
                        }

                        Notification::make()
                            ->title('発注数を更新しました')
                            ->success()
                            ->send();
                    }),

                TextColumn::make('sales_3d')
                    ->label('3日')
                    ->state(fn ($record) => $record->salesSummary?->last_3d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->width('45px')
                    ->color(fn ($record) => ($record->salesSummary?->last_3d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_7d')
                    ->label('7日')
                    ->state(fn ($record) => $record->salesSummary?->last_7d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->width('45px')
                    ->color(fn ($record) => ($record->salesSummary?->last_7d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_30d')
                    ->label('30日')
                    ->state(fn ($record) => $record->salesSummary?->last_30d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->width('45px')
                    ->color(fn ($record) => ($record->salesSummary?->last_30d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable()
                    ->width('70px'),

                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('120px'),

                TextColumn::make('batch_code_formatted')
                    ->label('実行時刻')
                    ->state(function ($record) {
                        return \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('m/d H:i');
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('batch_code', $direction))
                    ->toggleable()
                    ->width('80px'),

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

                static::modifierColumn(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                static::batchCodeFilter(WmsStockTransferCandidate::class),

                static::statusFilter(CandidateStatus::class)
                    ->default(CandidateStatus::PENDING->value),

                static::candidateCreatorFilter(),

                Filter::make('executed_at_range')
                    ->label('実行時刻')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('executed_from')
                                ->label('開始'),
                            DateTimePicker::make('executed_until')
                                ->label('終了'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['executed_from'] ?? null, fn (Builder $q, $date) => $q
                                ->where('batch_code', '>=', \Carbon\Carbon::parse($date)->format('YmdHis')))
                            ->when($data['executed_until'] ?? null, fn (Builder $q, $date) => $q
                                ->where('batch_code', '<=', \Carbon\Carbon::parse($date)->endOfMinute()->format('YmdHis').'999'));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['executed_from'] ?? null) {
                            $indicators[] = '実行時刻開始: '.\Carbon\Carbon::parse($data['executed_from'])->format('Y/m/d H:i');
                        }
                        if ($data['executed_until'] ?? null) {
                            $indicators[] = '実行時刻終了: '.\Carbon\Carbon::parse($data['executed_until'])->format('Y/m/d H:i');
                        }

                        return $indicators;
                    }),

                SelectFilter::make('satellite_warehouse_id')
                    ->label('在庫依頼倉庫')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Warehouse::query()
                            ->where('is_active', true)
                            ->where(fn ($q) => $q
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                            ->toArray();
                    }),

                SelectFilter::make('hub_warehouse_id')
                    ->label('移動元倉庫')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Warehouse::query()
                            ->where('is_active', true)
                            ->where(fn ($q) => $q
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                            ->toArray();
                    }),

                static::contractorFilter(),

                static::modifierFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('edit')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('移動候補詳細')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(fn ($record, $action) => $record->status === CandidateStatus::PENDING
                        ? $action->makeModalSubmitAction('submit', [])->label('変更を保存')->color('danger')
                        : false)
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->fillForm(function ($record) {
                        $itemContractor = static::resolveItemContractor($record);

                        return [
                            'transfer_quantity' => $record->transfer_quantity,
                            'expected_arrival_date' => $record->expected_arrival_date,
                            'delivery_course_id' => $record->delivery_course_id,
                            'safety_stock' => $record->safety_stock,
                            'auto_order_quantity' => $itemContractor?->auto_order_quantity ?? 0,
                            'is_auto_order' => (bool) ($itemContractor?->is_auto_order ?? false),
                            'ordering_code' => $record->search_code ?? $record->ordering_code,
                        ];
                    })
                    ->schema(function (?WmsStockTransferCandidate $record): array {
                        if (! $record) {
                            return [];
                        }

                        $log = WmsOrderCalculationLog::where('batch_code', $record->batch_code)
                            ->where('warehouse_id', $record->satellite_warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->first();

                        $details = $log?->calculation_details ?? [];
                        $item = $record->item;
                        $itemContractor = static::resolveItemContractor($record);
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

                        $isEditable = $record->status === CandidateStatus::PENDING;

                        // 手動変更判定
                        $shiftedDays = (int) ($details['到着日調整'] ?? 0);
                        $isDateManuallyChanged = false;
                        $calculatedDateFormatted = null;
                        if ($record->original_arrival_date && $record->expected_arrival_date) {
                            $calculatedDate = \Carbon\Carbon::parse($record->original_arrival_date)->addDays($shiftedDays);
                            $calculatedDateFormatted = $calculatedDate->format('Y/m/d');
                            $isDateManuallyChanged = $calculatedDate->format('Y-m-d') !== \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y-m-d');
                        }

                        $schema = [
                            View::make('filament.components.transfer-candidate-detail')
                                ->viewData([
                                    'batchCode' => $record->batch_code,
                                    'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('Y/m/d H:i'),
                                    'satelliteWarehouseName' => $record->satelliteWarehouse ? "[{$record->satelliteWarehouse->code}]{$record->satelliteWarehouse->name}" : '-',
                                    'hubWarehouseName' => $record->hubWarehouse ? "[{$record->hubWarehouse->code}]{$record->hubWarehouse->name}" : '-',
                                    'deliveryCourseName' => $record->deliveryCourse?->name ?? '-',
                                    'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                    'expectedArrivalDate' => $record->expected_arrival_date
                                        ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                                        : '-',
                                    'leadTimeDays' => $log?->lead_time_days ?? 0,
                                    'orderDate' => $record->created_at?->format('m/d') ?? '-',
                                    'originalArrivalDate' => $record->original_arrival_date
                                        ? \Carbon\Carbon::parse($record->original_arrival_date)->format('m/d')
                                        : null,
                                    'shiftedDays' => $shiftedDays,
                                    'shiftReasons' => $details['調整理由'] ?? '',
                                    'isDateManuallyChanged' => $isDateManuallyChanged,
                                    'calculatedDate' => $calculatedDateFormatted,
                                    'itemCode' => $record->item_code ?? $item?->code ?? '-',
                                    'searchCode' => $record->search_code ?? '-',
                                    'itemName' => $item?->name ?? '-',
                                    'packaging' => $item?->packaging ?? '-',
                                    'capacityText' => $capacityText,
                                    'statusLabel' => $record->status->label(),
                                    'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                    'transferQuantity' => $record->transfer_quantity ?? 0,
                                    'hasCalculationLog' => ! empty($details),
                                    'formula' => str_replace(['安全在庫', '入庫予定'], ['発注点', '入荷予定'], $details['計算式'] ?? '-'),
                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                    'incomingStock' => $details['入荷予定'] ?? $details['入庫予定数'] ?? 0,
                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                    'safetyStock' => $details['発注点'] ?? $details['安全在庫'] ?? 0,
                                    'settingAutoOrderQuantity' => $itemContractor?->auto_order_quantity ?? 0,
                                    'isAutoOrder' => (bool) ($itemContractor?->is_auto_order ?? false),
                                    'shortageQty' => $details['不足数'] ?? 0,
                                    'isEditable' => $isEditable,
                                ]),
                        ];

                        if ($isEditable) {
                            $schema[] = Grid::make(3)->schema([
                                TextInput::make('transfer_quantity')
                                    ->label('発注数')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),
                                DatePicker::make('expected_arrival_date')
                                    ->label('入荷予定日')
                                    ->required(),
                                Select::make('delivery_course_id')
                                    ->label('配送コース')
                                    ->options(fn () => DeliveryCourse::query()
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->searchable(),
                            ]);

                            $schema[] = Grid::make(2)->schema([
                                TextInput::make('safety_stock')
                                    ->label('発注点')
                                    ->integer()
                                    ->minValue(0),
                                TextInput::make('auto_order_quantity')
                                    ->label('自動発注数')
                                    ->integer()
                                    ->minValue(0),
                                Toggle::make('is_auto_order')
                                    ->label('自動発注ON/OFF'),
                            ]);

                            $codes = DB::connection('sakemaru')
                                ->table('item_search_information')
                                ->where('item_id', $record->item_id)
                                ->where('is_active', true)
                                ->select('search_string', 'code_type', 'is_used_for_ordering')
                                ->get();

                            if ($codes->isNotEmpty()) {
                                $codeOptions = $codes->mapWithKeys(function ($code) {
                                    $label = $code->search_string;
                                    if ($code->is_used_for_ordering) {
                                        $label .= ' (現在の発注用)';
                                    }

                                    return [$code->search_string => $label];
                                })->toArray();

                                $schema[] = Select::make('ordering_code')
                                    ->label('発注CD')
                                    ->options($codeOptions)
                                    ->searchable()
                                    ->helperText('この商品に登録されている検索コードから発注CDを選択');
                            }
                        }

                        return $schema;
                    })
                    ->action(function ($record, array $data) {
                        $itemContractor = static::resolveItemContractor($record);
                        $hasChanges = $data['transfer_quantity'] != $record->transfer_quantity
                            || $data['expected_arrival_date'] != $record->expected_arrival_date?->format('Y-m-d')
                            || $data['delivery_course_id'] != $record->delivery_course_id
                            || (isset($data['safety_stock']) && (int) $data['safety_stock'] !== (int) $record->safety_stock)
                            || (isset($data['auto_order_quantity']) && (int) $data['auto_order_quantity'] !== (int) ($itemContractor?->auto_order_quantity ?? 0))
                            || (array_key_exists('is_auto_order', $data) && (bool) $data['is_auto_order'] !== (bool) ($itemContractor?->is_auto_order ?? false))
                            || (isset($data['ordering_code']) && $data['ordering_code'] !== ($record->search_code ?? $record->ordering_code));

                        if ($hasChanges) {
                            $oldQuantity = $record->transfer_quantity;
                            $newQuantity = (int) $data['transfer_quantity'];

                            $updateData = [
                                'transfer_quantity' => $newQuantity,
                                'expected_arrival_date' => $data['expected_arrival_date'],
                                'delivery_course_id' => $data['delivery_course_id'],
                                'is_manually_modified' => true,
                                'modified_by' => auth()->id(),
                                'modified_at' => now(),
                            ];

                            if (isset($data['safety_stock']) && (int) $data['safety_stock'] !== (int) $record->safety_stock) {
                                $newSafetyStock = (int) $data['safety_stock'];
                                $updateData['safety_stock'] = $newSafetyStock;

                                if ($record->contractor_id) {
                                    WmsMonthlySafetyStock::updateOrCreate(
                                        [
                                            'item_id' => $record->item_id,
                                            'warehouse_id' => $record->satellite_warehouse_id,
                                            'contractor_id' => $record->contractor_id,
                                            'month' => now()->month,
                                        ],
                                        [
                                            'safety_stock' => $newSafetyStock,
                                        ]
                                    );
                                }
                            }

                            $itemContractorData = [];
                            if (isset($data['safety_stock']) && (int) $data['safety_stock'] !== (int) ($itemContractor?->safety_stock ?? 0)) {
                                $itemContractorData['safety_stock'] = max(0, (int) $data['safety_stock']);
                            }
                            if (isset($data['auto_order_quantity']) && (int) $data['auto_order_quantity'] !== (int) ($itemContractor?->auto_order_quantity ?? 0)) {
                                $itemContractorData['auto_order_quantity'] = max(0, (int) $data['auto_order_quantity']);
                            }
                            if (array_key_exists('is_auto_order', $data) && (bool) $data['is_auto_order'] !== (bool) ($itemContractor?->is_auto_order ?? false)) {
                                $itemContractorData['is_auto_order'] = (bool) $data['is_auto_order'];
                            }
                            if ($itemContractorData !== []) {
                                ItemContractor::where('item_id', $record->item_id)
                                    ->where('warehouse_id', $record->satellite_warehouse_id)
                                    ->where('contractor_id', $record->contractor_id)
                                    ->update($itemContractorData);
                            }

                            if (isset($data['ordering_code']) && $data['ordering_code'] !== ($record->search_code ?? $record->ordering_code)) {
                                $newSearchCode = $data['ordering_code'];
                                $updateData['search_code'] = $newSearchCode;
                                $updateData['ordering_code'] = str_pad($newSearchCode, 13, '0', STR_PAD_LEFT);
                            }

                            $record->update($updateData);

                            // 移動数量が変更された場合、関連発注候補を再計算
                            if ($oldQuantity !== $newQuantity) {
                                $recalcService = app(TransferOrderRecalculationService::class);
                                $updatedOrder = $recalcService->recalculateOrderForTransfer($record, $oldQuantity, $newQuantity);

                                if ($updatedOrder) {
                                    Notification::make()
                                        ->title('移動候補を更新しました')
                                        ->body("関連発注候補の発注数も {$updatedOrder->order_quantity} に再計算されました。")
                                        ->success()
                                        ->send();

                                    return;
                                }
                            }

                            Notification::make()
                                ->title('移動候補を更新しました')
                                ->success()
                                ->send();
                        }
                    }),

                Action::make('viewTransferSlip')
                    ->label('伝票履歴')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn ($record) => $record->status === CandidateStatus::EXECUTED)
                    ->modalHeading(fn ($record) => "移動伝票履歴 バッチ:{$record->batch_code}")
                    ->modalWidth('6xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalContent(fn ($record) => view(
                        'filament.components.stock-transfer-slip-history',
                        StockTransferSlipHistory::resolveForBatchCode($record->batch_code),
                    )),

                Action::make('toggleAutoOrder')
                    ->label('発注消')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalHeading('自動発注対象から除外')
                    ->modalDescription(fn ($record) => "[{$record->item_code}] {$record->item?->name}\nこの商品を自動発注対象から除外しますか？")
                    ->action(function ($record) {
                        \App\Models\Sakemaru\ItemContractor::where('item_id', $record->item_id)
                            ->where('contractor_id', $record->contractor_id)
                            ->where('warehouse_id', $record->satellite_warehouse_id)
                            ->update(['is_auto_order' => false]);

                        WmsStockTransferCandidate::where('item_id', $record->item_id)
                            ->where('contractor_id', $record->contractor_id)
                            ->where('satellite_warehouse_id', $record->satellite_warehouse_id)
                            ->where('status', CandidateStatus::PENDING)
                            ->delete();

                        \App\Models\WmsOrderCandidate::where('item_id', $record->item_id)
                            ->where('contractor_id', $record->contractor_id)
                            ->where('warehouse_id', $record->satellite_warehouse_id)
                            ->where('status', CandidateStatus::PENDING)
                            ->delete();

                        Notification::make()->title('自動発注対象から除外しました')->success()->send();
                    })
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING),

                Action::make('delete')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('移動候補を削除')
                    ->modalDescription('この移動候補を削除します。この操作は取り消せません。')
                    ->action(function ($record) {
                        // 関連発注候補を再計算（削除により発注不要になる可能性あり）
                        $recalcService = app(TransferOrderRecalculationService::class);
                        $orderExcluded = $recalcService->checkAndExcludeOrderCandidate($record);

                        $record->delete();

                        if ($orderExcluded) {
                            Notification::make()
                                ->title('移動候補を削除しました')
                                ->body('関連発注候補も発注不要となったため除外されました。')
                                ->warning()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('移動候補を削除しました')
                                ->warning()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('bulkApprove')
                        ->label('選択を承認')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (empty($pendingIds)) {
                                Notification::make()
                                    ->title('承認前の候補がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = WmsStockTransferCandidate::whereIn('id', $pendingIds)
                                ->update([
                                    'status' => CandidateStatus::APPROVED,
                                    'updated_at' => now(),
                                ]);

                            Notification::make()
                                ->title("{$count}件を承認しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkDelete')
                        ->label('選択を削除')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('選択した移動候補を削除')
                        ->modalDescription('選択した承認前の移動候補を削除します。この操作は取り消せません。')
                        ->action(function (Collection $records) {
                            $recalcService = app(TransferOrderRecalculationService::class);
                            $excludedOrderCount = 0;

                            $pendingRecords = $records->where('status', CandidateStatus::PENDING);

                            if ($pendingRecords->isEmpty()) {
                                Notification::make()
                                    ->title('承認前の候補がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = $pendingRecords
                                ->each(function ($record) use ($recalcService, &$excludedOrderCount) {
                                    if ($recalcService->checkAndExcludeOrderCandidate($record)) {
                                        $excludedOrderCount++;
                                    }
                                    $record->delete();
                                })
                                ->count();

                            $message = "{$count}件を削除しました";
                            if ($excludedOrderCount > 0) {
                                $message .= "（関連発注候補 {$excludedOrderCount}件も除外）";
                            }

                            Notification::make()
                                ->title($message)
                                ->warning()
                                ->send();
                        }),

                    BulkAction::make('bulkUpdateCourseAndDate')
                        ->label('コース・入荷日変更')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->modalHeading('')
                        ->extraModalWindowAttributes(['class' => 'bulk-update-course-date-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('変更を適用')->color('danger'))
                        ->modalCancelActionLabel('変更せず閉じる')
                        ->schema(fn (Collection $records) => [
                            ViewField::make('header')
                                ->view('filament.components.bulk-update-course-date-header', [
                                    'totalCount' => $records->count(),
                                    'pendingCount' => $records->where('status', CandidateStatus::PENDING)->count(),
                                ]),
                            Select::make('delivery_course_id')
                                ->label('配送コース')
                                ->options(fn () => DeliveryCourse::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->placeholder('変更しない'),
                            DatePicker::make('expected_arrival_date')
                                ->label('入荷予定日'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $updateData = [];

                            if (! empty($data['delivery_course_id'])) {
                                $updateData['delivery_course_id'] = $data['delivery_course_id'];
                            }

                            if (! empty($data['expected_arrival_date'])) {
                                $updateData['expected_arrival_date'] = $data['expected_arrival_date'];
                            }

                            if (empty($updateData)) {
                                Notification::make()
                                    ->title('変更項目がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $updateData['is_manually_modified'] = true;
                            $updateData['modified_by'] = auth()->id();
                            $updateData['modified_at'] = now();
                            $updateData['updated_at'] = now();

                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (empty($pendingIds)) {
                                Notification::make()
                                    ->title('承認前の候補がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = WmsStockTransferCandidate::whereIn('id', $pendingIds)
                                ->update($updateData);

                            Notification::make()
                                ->title("{$count}件を更新しました")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('batch_code', 'desc');
    }

    private static function resolveItemContractor(WmsStockTransferCandidate $record): ?ItemContractor
    {
        return ItemContractor::query()
            ->where('item_id', $record->item_id)
            ->where('warehouse_id', $record->satellite_warehouse_id)
            ->where('contractor_id', $record->contractor_id)
            ->first();
    }

    private static function candidateCreatorFilter(): SelectFilter
    {
        return SelectFilter::make('candidate_created_by')
            ->label('作成者')
            ->searchable()
            ->default(auth()->id())
            ->options(fn () => self::buildCandidateCreatorOptions())
            ->getSearchResultsUsing(fn (string $search) => self::buildCandidateCreatorOptions($search))
            ->query(function ($query, array $data) {
                if (blank($data['value'])) {
                    return;
                }

                $query->whereIn('batch_code', WmsAutoOrderJobControl::query()
                    ->where('created_by', $data['value'])
                    ->select('batch_code'));
            });
    }

    private static function buildCandidateCreatorOptions(?string $search = null): array
    {
        $query = User::query()
            ->whereIn('id', fn ($q) => $q
                ->select('created_by')
                ->from((new WmsAutoOrderJobControl)->getTable())
                ->whereNotNull('created_by')
                ->distinct());

        if ($search) {
            $search = mb_convert_kana($search, 'as');
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        return $query
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => "[{$u->code}]{$u->name}"])
            ->toArray();
    }
}
