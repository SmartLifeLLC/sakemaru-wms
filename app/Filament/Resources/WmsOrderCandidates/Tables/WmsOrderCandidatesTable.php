<?php

namespace App\Filament\Resources\WmsOrderCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\Concerns\OptimisticLockException;
use App\Models\WmsMonthlySafetyStock;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderAuditService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
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
    use HasExportAction;
    use HasModifierDisplay;
    use HasOptimizedFilters;

    protected static function getFilterModelTable(): string
    {
        return (new WmsOrderCandidate)->getTable();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-candidates-table sticky-actions'])
            ->columns([
                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('ordering_code')
                    ->label('発注CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('ordering_unit_quantity')
                    ->label('発注CD入数')
                    ->state(fn (WmsOrderCandidate $record) => WmsOrderConfirmationWaitingTable::resolveOrderingUnitQuantity($record) ?? '-')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => is_numeric($state) ? 'warning' : 'gray')
                    ->toggleable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('item.packaging')
                    ->label('規格')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('supplier.partner_name')
                    ->label('仕入先')
                    ->state(fn ($record) => $record->supplier ? "[{$record->supplier->partner_code}]{$record->supplier->partner_name}" : '-')
                    ->toggleable(),

                TextColumn::make('current_stock')
                    ->label('現在庫')
                    ->state(fn ($record) => $record->current_stock ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('satellite_demand_qty')
                    ->label('移動依頼')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('incoming_quantity_override')
                    ->label('入荷数')
                    ->state(fn ($record) => $record->incoming_quantity_override ?? $record->original_incoming_quantity ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('calculated_available')
                    ->label('見込在庫')
                    ->state(fn ($record) => $record->calculated_available ?? '-')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('safety_stock')
                    ->label('発注点')
                    ->state(fn ($record) => $record->safety_stock ?? '-')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('shortage_qty')
                    ->label('不足分')
                    ->state(fn ($record) => $record->shortage_qty ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->shortage_qty ?? 0) > 0 ? 'danger' : null),

                TextInputColumn::make('case_quantity')
                    ->label('発注ケース')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->alignEnd()
                    ->extraInputAttributes(['style' => 'width: 60px; text-align: right;'])
                    ->disabled(fn ($record) => $record->status !== CandidateStatus::PENDING
                        || ($record->item?->capacity_case ?? 1) <= 1)
                    ->afterStateUpdated(function ($record, $state) {
                        if ($record->status !== CandidateStatus::PENDING) {
                            return;
                        }
                        try {
                            $newQuantity = (int) $state;

                            if ($newQuantity === 0 && $record->quantity_type !== QuantityType::CASE) {
                                return;
                            }

                            // 現在PIECEの行をCASEに変更する場合、既存CASE行があれば統合
                            if ($newQuantity > 0 && $record->quantity_type !== QuantityType::CASE) {
                                $existingCaseRow = WmsOrderCandidate::where('batch_code', $record->batch_code)
                                    ->where('item_id', $record->item_id)
                                    ->where('warehouse_id', $record->warehouse_id)
                                    ->where('contractor_id', $record->contractor_id)
                                    ->where('quantity_type', QuantityType::CASE)
                                    ->where('id', '!=', $record->id)
                                    ->first();

                                if ($existingCaseRow) {
                                    $existingCaseRow->update([
                                        'order_quantity' => $existingCaseRow->order_quantity + $newQuantity,
                                        'is_manually_modified' => true,
                                        'modified_by' => auth()->id(),
                                        'modified_at' => now(),
                                    ]);
                                    $record->delete();
                                    Notification::make()->title('既存のケース行に統合しました')->success()->send();

                                    return;
                                }
                            }

                            $oldQuantity = $record->order_quantity;
                            $casePrice = $record->item?->current_price?->purchase_case_price;
                            $record->updateWithLock([
                                'order_quantity' => $newQuantity,
                                'quantity_type' => QuantityType::CASE->value,
                                'purchase_unit_price' => $casePrice,
                                'is_manually_modified' => true,
                                'modified_by' => auth()->id(),
                                'modified_at' => now(),
                            ]);
                            if ($oldQuantity !== $newQuantity) {
                                app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $newQuantity);
                            }
                        } catch (OptimisticLockException $e) {
                            Notification::make()->title('更新エラー')->body($e->getMessage())->danger()->send();
                        }
                    }),

                TextInputColumn::make('piece_quantity')
                    ->label('発注バラ')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->alignEnd()
                    ->extraInputAttributes(['style' => 'width: 60px; text-align: right;'])
                    ->disabled(fn ($record) => $record->status !== CandidateStatus::PENDING)
                    ->afterStateUpdated(function ($record, $state) {
                        if ($record->status !== CandidateStatus::PENDING) {
                            return;
                        }
                        try {
                            $newQuantity = (int) $state;

                            if ($newQuantity === 0 && $record->quantity_type !== QuantityType::PIECE) {
                                return;
                            }

                            // 現在CASEの行をPIECEに変更する場合、既存PIECE行があれば統合
                            if ($newQuantity > 0 && $record->quantity_type !== QuantityType::PIECE) {
                                $existingPieceRow = WmsOrderCandidate::where('batch_code', $record->batch_code)
                                    ->where('item_id', $record->item_id)
                                    ->where('warehouse_id', $record->warehouse_id)
                                    ->where('contractor_id', $record->contractor_id)
                                    ->where('quantity_type', QuantityType::PIECE)
                                    ->where('id', '!=', $record->id)
                                    ->first();

                                if ($existingPieceRow) {
                                    $existingPieceRow->update([
                                        'order_quantity' => $existingPieceRow->order_quantity + $newQuantity,
                                        'is_manually_modified' => true,
                                        'modified_by' => auth()->id(),
                                        'modified_at' => now(),
                                    ]);
                                    $record->delete();
                                    Notification::make()->title('既存のバラ行に統合しました')->success()->send();

                                    return;
                                }
                            }

                            $oldQuantity = $record->order_quantity;
                            $piecePrice = $record->item?->current_price?->purchase_unit_price;
                            $record->updateWithLock([
                                'order_quantity' => $newQuantity,
                                'quantity_type' => QuantityType::PIECE->value,
                                'purchase_unit_price' => $piecePrice,
                                'is_manually_modified' => true,
                                'modified_by' => auth()->id(),
                                'modified_at' => now(),
                            ]);
                            if ($oldQuantity !== $newQuantity) {
                                app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $newQuantity);
                            }
                        } catch (OptimisticLockException $e) {
                            Notification::make()->title('更新エラー')->body($e->getMessage())->danger()->send();
                        }
                    }),

                TextColumn::make('sales_3d')
                    ->label('3日')
                    ->state(fn ($record) => $record->salesSummary?->last_3d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->last_3d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_7d')
                    ->label('7日')
                    ->state(fn ($record) => $record->salesSummary?->last_7d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->last_7d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_30d')
                    ->label('30日')
                    ->state(fn ($record) => $record->salesSummary?->last_30d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->last_30d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('item.capacity_case')
                    ->label('入数')
                    ->numeric()
                    ->alignCenter(),

                TextColumn::make('case_price_display')
                    ->label('ケース単価')
                    ->state(fn ($record) => $record->item?->current_price?->purchase_case_price !== null
                        ? number_format((float) $record->item->current_price->purchase_case_price, 2)
                        : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('piece_price_display')
                    ->label('バラ単価')
                    ->state(fn ($record) => $record->item?->current_price?->purchase_unit_price !== null
                        ? number_format((float) $record->item->current_price->purchase_unit_price, 2)
                        : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('purchase_total')
                    ->label('仕入合計')
                    ->state(function ($record) {
                        if ($record->purchase_unit_price === null || ! $record->order_quantity) {
                            return '-';
                        }
                        $total = (float) $record->purchase_unit_price * $record->order_quantity;

                        return number_format($total, 2);
                    })
                    ->alignEnd()
                    ->toggleable()

                    ->summarize(
                        Summarizer::make()
                            ->label('合計')
                            ->numeric(thousandsSeparator: ',', decimalPlaces: 2)
                            ->using(function (Builder $query) {
                                return (float) $query->sum(
                                    DB::raw('COALESCE(purchase_unit_price, 0) * COALESCE(order_quantity, 0)')
                                );
                            })
                    ),
                TextColumn::make('total_pieces')
                    ->label('総バラ')
                    ->state(function ($record) {
                        $capacityCase = $record->item?->capacity_case ?? 1;
                        $caseQty = $record->quantity_type === QuantityType::CASE ? $record->order_quantity : 0;
                        $pieceQty = $record->quantity_type === QuantityType::PIECE ? $record->order_quantity : 0;

                        return $caseQty * $capacityCase + $pieceQty;
                    })
                    ->numeric()
                    ->alignEnd()
                    ->summarize(
                        Summarizer::make()
                            ->label('合計')
                            ->numeric(thousandsSeparator: ',')
                            ->using(function (Builder $query) {
                                return (int) $query->sum(
                                    DB::raw('CASE WHEN quantity_type = \'CASE\' THEN COALESCE(order_quantity, 0) * COALESCE((SELECT capacity_case FROM items WHERE items.id = wms_order_candidates.item_id), 1) ELSE COALESCE(order_quantity, 0) END')
                                );
                            })
                    ),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('batch_code_formatted')
                    ->label('実行時刻')
                    ->state(function ($record) {
                        return \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('m/d H:i');
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('batch_code', $direction)),

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

                TextColumn::make('origin_type')
                    ->label('生成元')
                    ->badge()
                    ->formatStateUsing(fn (OriginType $state): string => $state->label())
                    ->color(fn ($record) => $record->origin_type?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable(),

                static::modifierColumn(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                static::batchCodeFilter(WmsOrderCandidate::class),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::PENDING->value => CandidateStatus::PENDING->label(),
                        CandidateStatus::EXCLUDED->value => CandidateStatus::EXCLUDED->label(),
                    ]),

                SelectFilter::make('origin_type')
                    ->label('生成元')
                    ->options(collect(OriginType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),

                static::warehouseFilter()->label('発注倉庫'),

                static::contractorFilter(),

                static::supplierFilter(),

                static::modifierFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewCalculation')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注候補詳細')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(fn ($record, $action) => $record->status === CandidateStatus::PENDING
                        ? $action->makeModalSubmitAction('submit', [])->label('変更を保存')->color('danger')
                        : false)
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->fillForm(fn ($record) => [
                        'case_quantity' => $record->case_quantity,
                        'piece_quantity' => $record->piece_quantity,
                        'expected_arrival_date' => $record->expected_arrival_date,
                        'safety_stock' => $record->safety_stock,
                        'ordering_code' => $record->search_code ?? $record->ordering_code,
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
                        $capacityCase = $item?->capacity_case ?? 1;
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

                        // 手動変更判定: 算出日と現在の予定日を比較
                        $shiftedDays = (int) ($details['到着日調整'] ?? 0);
                        $isDateManuallyChanged = false;
                        $calculatedDateFormatted = null;
                        if ($record->original_arrival_date && $record->expected_arrival_date) {
                            $calculatedDate = \Carbon\Carbon::parse($record->original_arrival_date)->addDays($shiftedDays);
                            $calculatedDateFormatted = $calculatedDate->format('Y/m/d');
                            $isDateManuallyChanged = $calculatedDate->format('Y-m-d') !== $record->expected_arrival_date->format('Y-m-d');
                        }

                        $schema = [
                            View::make('filament.components.order-candidate-detail')
                                ->viewData([
                                    'batchCode' => $record->batch_code,
                                    'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('Y/m/d H:i'),
                                    'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
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
                                    'currentEffectiveStock' => $record->current_effective_stock ?? 0,
                                    'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                    'orderQuantity' => $record->order_quantity ?? 0,
                                    'hasCalculationLog' => ! empty($details),
                                    'formula' => str_replace(['安全在庫', '入庫予定'], ['発注点', '入荷予定'], $details['計算式'] ?? '-'),
                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                    'incomingStock' => $details['入荷予定'] ?? $details['入庫予定数'] ?? 0,
                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                    'safetyStock' => $details['発注点'] ?? $details['安全在庫'] ?? 0,
                                    'shortageQty' => $details['不足数'] ?? 0,
                                    'purchaseUnit' => $details['最小仕入単位'] ?? 1,
                                    'purchaseUnitAdjustment' => $details['単位調整説明'] ?? null,
                                    'isEditable' => $isEditable,
                                ]),
                        ];

                        if ($isEditable) {
                            $schema[] = Hidden::make('safety_stock');

                            $schema[] = Grid::make(3)->schema([
                                TextInput::make('case_quantity')
                                    ->label('発注ケース')
                                    ->integer()
                                    ->minValue(0)
                                    ->disabled($capacityCase <= 1),

                                TextInput::make('piece_quantity')
                                    ->label('発注バラ')
                                    ->integer()
                                    ->minValue(0),

                                DatePicker::make('expected_arrival_date')
                                    ->label('入荷予定日')
                                    ->required(),
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
                        if ($record->status !== CandidateStatus::PENDING) {
                            Notification::make()
                                ->title('承認後は変更できません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $updated = false;
                        $oldQuantity = $record->order_quantity;
                        $updateData = [
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ];

                        $caseQty = (int) ($data['case_quantity'] ?? 0);
                        $pieceQty = (int) ($data['piece_quantity'] ?? 0);
                        $currentPrice = $record->item?->current_price;

                        if ($caseQty > 0) {
                            $updateData['order_quantity'] = $caseQty;
                            $updateData['quantity_type'] = QuantityType::CASE->value;
                            $updateData['purchase_unit_price'] = $currentPrice?->purchase_case_price;
                            $updated = true;
                        } elseif ($pieceQty > 0) {
                            $updateData['order_quantity'] = $pieceQty;
                            $updateData['quantity_type'] = QuantityType::PIECE->value;
                            $updateData['purchase_unit_price'] = $currentPrice?->purchase_unit_price;
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

                        if (isset($data['safety_stock']) && (int) $data['safety_stock'] !== (int) $record->safety_stock) {
                            $newSafetyStock = (int) $data['safety_stock'];
                            $updateData['safety_stock'] = $newSafetyStock;
                            $updated = true;

                            WmsMonthlySafetyStock::updateOrCreate(
                                [
                                    'item_id' => $record->item_id,
                                    'warehouse_id' => $record->warehouse_id,
                                    'contractor_id' => $record->contractor_id,
                                    'month' => now()->month,
                                ],
                                [
                                    'safety_stock' => $newSafetyStock,
                                ]
                            );
                        }

                        if (isset($data['ordering_code']) && $data['ordering_code'] !== ($record->search_code ?? $record->ordering_code)) {
                            $newSearchCode = $data['ordering_code'];
                            $updateData['search_code'] = $newSearchCode;
                            $updateData['ordering_code'] = str_pad($newSearchCode, 13, '0', STR_PAD_LEFT);
                            $updated = true;
                        }

                        if ($updated) {
                            try {
                                $record->updateWithLock($updateData);

                                if ($oldQuantity !== ($updateData['order_quantity'] ?? $oldQuantity)) {
                                    app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $updateData['order_quantity']);
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

                Action::make('delete')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('発注候補を削除')
                    ->modalDescription('この発注候補を削除します。この操作は取り消せません。')
                    ->action(function ($record) {
                        $record->delete();

                        Notification::make()
                            ->title('発注候補を削除しました')
                            ->warning()
                            ->send();
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
                        ->modalDescription('選択した発注候補を承認しますか？')
                        ->action(function (Collection $records) {
                            // 一括UPDATEで高速化（N+1問題を解消）
                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (! empty($pendingIds)) {
                                WmsOrderCandidate::whereIn('id', $pendingIds)
                                    ->with('item.current_price')
                                    ->get()
                                    ->each(function (WmsOrderCandidate $candidate) {
                                        WmsOrderConfirmationWaitingTable::applyOrderingUnitConversionForApproval($candidate);
                                        $candidate->update([
                                            'status' => CandidateStatus::APPROVED,
                                            'updated_at' => now(),
                                        ]);
                                    });
                            }

                            $count = count($pendingIds);

                            Notification::make()
                                ->title("{$count}件を承認しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkUpdateArrivalDate')
                        ->label('入荷予定日変更')
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
                            DatePicker::make('expected_arrival_date')
                                ->label('入荷予定日')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            if (empty($data['expected_arrival_date'])) {
                                Notification::make()
                                    ->title('入荷予定日を指定してください')
                                    ->warning()
                                    ->send();

                                return;
                            }

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

                            $count = WmsOrderCandidate::whereIn('id', $pendingIds)
                                ->update([
                                    'expected_arrival_date' => $data['expected_arrival_date'],
                                    'is_manually_modified' => true,
                                    'modified_by' => auth()->id(),
                                    'modified_at' => now(),
                                    'updated_at' => now(),
                                ]);

                            Notification::make()
                                ->title("{$count}件の入荷予定日を更新しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkDelete')
                        ->label('選択を削除')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('選択した発注候補を削除')
                        ->modalDescription('選択した承認前の発注候補を削除します。この操作は取り消せません。')
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

                            $count = WmsOrderCandidate::whereIn('id', $pendingIds)->delete();

                            Notification::make()
                                ->title("{$count}件を削除しました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('batch_code', 'desc');
    }
}
