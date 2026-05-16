<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\Sakemaru\User;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderExecutionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WmsOrderConfirmationWaitingTable
{
    use HasExportAction;
    use HasModifierDisplay;
    use HasOptimizedFilters;

    private static array $itemContractorOrderSettingCache = [];

    private static array $orderingUnitQuantityCache = [];

    protected static function getFilterModelTable(): string
    {
        return (new WmsOrderCandidate)->getTable();
    }

    public static function applyItemContractorJoin(EloquentBuilder $query): EloquentBuilder
    {
        $mainTable = (new WmsOrderCandidate)->getTable();

        return $query
            ->select([
                "{$mainTable}.*",
                'ic.safety_stock as ic_safety_stock',
                'ic.max_stock as ic_max_stock',
                'ic.min_stock as ic_min_stock',
                'ic.auto_order_quantity as ic_auto_order_quantity',
                'ic.is_auto_order as ic_is_auto_order',
            ])
            ->leftJoin('item_contractors as ic', function ($join) use ($mainTable) {
                $join->on('ic.warehouse_id', '=', "{$mainTable}.warehouse_id")
                    ->on('ic.item_id', '=', "{$mainTable}.item_id")
                    ->on('ic.contractor_id', '=', "{$mainTable}.contractor_id");
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-confirmation-waiting-table sticky-actions'])
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('170px'),

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('ordering_unit_quantity')
                    ->label('発注CD入数')
                    ->state(fn (WmsOrderCandidate $record) => static::resolveOrderingUnitQuantity($record) ?? '-')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => is_numeric($state) ? 'warning' : 'gray')
                    ->toggleable(),

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

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('120px'),

                TextColumn::make('current_effective_stock')
                    ->label('現在庫')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('setting_safety_stock')
                    ->label('発注点')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_safety_stock ?? $record->safety_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('60px'),

                TextColumn::make('setting_max_stock')
                    ->label('最大発注点')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_max_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('75px'),

                TextColumn::make('setting_min_stock')
                    ->label('最低在庫数')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_min_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('75px'),

                TextColumn::make('setting_auto_order_quantity')
                    ->label('自動発注数')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_auto_order_quantity ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('75px'),

                TextColumn::make('setting_is_auto_order')
                    ->label('自動発注')
                    ->state(fn (WmsOrderCandidate $record) => ((bool) ($record->ic_is_auto_order ?? false)) ? 'ON' : 'OFF')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ON' ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('suggested_quantity')
                    ->label('算出数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextInputColumn::make('case_quantity')
                    ->label('発注ケース')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->alignEnd()
                    ->width('75px')
                    ->extraInputAttributes(['style' => 'width: 60px; text-align: right;'])
                    ->disabled(fn ($record) => ! $record->status->isEditable()
                        || ($record->item?->capacity_case ?? 1) <= 1)
                    ->afterStateUpdated(function ($record, $state) {
                        if (! $record->status->isEditable()) {
                            return;
                        }
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

                        $casePrice = $record->item?->current_price?->purchase_case_price;
                        $record->update([
                            'order_quantity' => $newQuantity,
                            'quantity_type' => QuantityType::CASE->value,
                            'purchase_unit_price' => $casePrice,
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);
                    }),

                TextInputColumn::make('piece_quantity')
                    ->label('発注バラ')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->alignEnd()
                    ->width('75px')
                    ->extraInputAttributes(['style' => 'width: 60px; text-align: right;'])
                    ->disabled(fn ($record) => ! $record->status->isEditable())
                    ->afterStateUpdated(function ($record, $state) {
                        if (! $record->status->isEditable()) {
                            return;
                        }
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

                        $piecePrice = $record->item?->current_price?->purchase_unit_price;
                        $record->update([
                            'order_quantity' => $newQuantity,
                            'quantity_type' => QuantityType::PIECE->value,
                            'purchase_unit_price' => $piecePrice,
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ]);
                    }),

                TextColumn::make('item.capacity_case')
                    ->label('入数')
                    ->numeric()
                    ->alignCenter()
                    ->width('50px'),

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
                    ->width('55px')
                    ->summarize(
                        Summarizer::make()
                            ->label('')
                            ->using(function (Builder $query) {
                                return (int) $query->sum(
                                    DB::raw('CASE WHEN quantity_type = \'CASE\' THEN COALESCE(order_quantity, 0) * COALESCE((SELECT capacity_case FROM items WHERE items.id = wms_order_candidates.item_id), 1) ELSE COALESCE(order_quantity, 0) END')
                                );
                            })
                    ),

                TextColumn::make('case_price_display')
                    ->label('ケース単価')
                    ->state(fn ($record) => $record->item?->current_price?->purchase_case_price !== null
                        ? number_format((float) $record->item->current_price->purchase_case_price, 2)
                        : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('85px'),

                TextColumn::make('piece_price_display')
                    ->label('バラ単価')
                    ->state(fn ($record) => $record->item?->current_price?->purchase_unit_price !== null
                        ? number_format((float) $record->item->current_price->purchase_unit_price, 2)
                        : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('80px'),

                TextColumn::make('purchase_total')
                    ->label('仕入合計')
                    ->state(function ($record) {
                        if ($record->purchase_unit_price === null || ! $record->order_quantity) {
                            return '-';
                        }

                        return number_format((float) $record->purchase_unit_price * $record->order_quantity);
                    })
                    ->alignEnd()
                    ->width('90px'),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->width('120px'),

                TextColumn::make('batch_code_formatted')
                    ->label('実行時刻')
                    ->state(function ($record) {
                        return \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('m/d H:i');
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('batch_code', $direction))
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

                TextColumn::make('origin_type')
                    ->label('生成元')
                    ->badge()
                    ->color(fn ($record) => $record->origin_type?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable()
                    ->width('75px'),

                static::modifierColumn(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('jx_only')
                    ->label('JX送信対象')
                    ->placeholder('すべて')
                    ->trueLabel('JX対象のみ')
                    ->falseLabel('JX対象外のみ')
                    ->queries(
                        true: fn (EloquentBuilder $query) => $query->whereIn(
                            'contractor_id',
                            static::getJxContractorIds()
                        ),
                        false: fn (EloquentBuilder $query) => $query->whereNotIn(
                            'contractor_id',
                            static::getJxContractorIds()
                        ),
                    ),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::APPROVED->value => CandidateStatus::APPROVED->label(),
                        CandidateStatus::CONFIRMED->value => CandidateStatus::CONFIRMED->label(),
                    ]),

                static::warehouseFilter()
                    ->label('在庫拠点倉庫')
                    ->query(function ($query, array $data) {
                        if (blank($data['value'])) {
                            return;
                        }

                        $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $data['value']);
                    }),

                static::contractorFilter(),

                static::candidateCreatorFilter(),

                static::modifierFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注候補詳細')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(fn ($record, $action) => $record->status->isEditable()
                        ? $action->makeModalSubmitAction('submit', [])->label('変更を保存')->color('danger')
                        : false)
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->fillForm(fn ($record) => [
                        'case_quantity' => $record->case_quantity,
                        'piece_quantity' => $record->piece_quantity,
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

                        $isEditable = $record->status->isEditable();

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
                                    'formula' => $details['計算式'] ?? '-',
                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                    'incomingStock' => $details['入庫予定数'] ?? 0,
                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                    'safetyStock' => $details['発注点'] ?? $details['安全在庫'] ?? (int) ($record->ic_safety_stock ?? $record->safety_stock ?? 0),
                                    'maxStock' => $details['最大発注点'] ?? (int) ($record->ic_max_stock ?? 0),
                                    'minStock' => (int) ($record->ic_min_stock ?? 0),
                                    'autoOrderQuantity' => $details['旧自動発注数'] ?? (int) ($record->ic_auto_order_quantity ?? 0),
                                    'isAutoOrder' => (bool) ($record->ic_is_auto_order ?? false),
                                    'shortageQty' => $details['不足数'] ?? 0,
                                    'purchaseUnit' => $details['最小仕入単位'] ?? 1,
                                    'purchaseUnitAdjustment' => $details['単位調整説明'] ?? null,
                                ]),
                        ];

                        if ($isEditable) {
                            $schema[] = Grid::make(3)->schema([
                                TextInput::make('case_quantity')
                                    ->label('発注ケース')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->disabled($capacityCase <= 1),

                                TextInput::make('piece_quantity')
                                    ->label('発注バラ')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0),

                                DatePicker::make('expected_arrival_date')
                                    ->label('入荷予定日')
                                    ->required(),
                            ]);
                        }

                        return $schema;
                    })
                    ->action(function ($record, array $data) {
                        if (! $record->status->isEditable()) {
                            Notification::make()
                                ->title('このステータスでは編集できません')
                                ->warning()
                                ->send();

                            return;
                        }

                        $updateData = [
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ];
                        $updated = false;

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
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('bulkConfirmSelectedOrders')
                        ->label('選択を発注確定')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('選択した発注候補を確定')
                        ->modalDescription('選択した承認済み発注候補だけを確定し、入荷予定を作成します。選択していない候補は確定しません。')
                        ->modalSubmitActionLabel('確定実行')
                        ->modalCancelActionLabel('確定せず閉じる')
                        ->action(function (Collection $records) {
                            $user = auth()->user();
                            if (! $user?->id) {
                                Notification::make()
                                    ->title('ログインユーザーを確認できません')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $approvedIds = $records
                                ->filter(fn ($r) => $r->status === CandidateStatus::APPROVED)
                                ->pluck('id')
                                ->map(fn ($id) => (int) $id)
                                ->all();

                            if (empty($approvedIds)) {
                                Notification::make()
                                    ->title('承認済みのレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $service = app(OrderExecutionService::class);
                            $confirmed = 0;
                            $failed = 0;

                            WmsOrderCandidate::whereIn('id', $approvedIds)
                                ->where('status', CandidateStatus::APPROVED)
                                ->get()
                                ->each(function (WmsOrderCandidate $candidate) use ($service, $user, &$confirmed, &$failed) {
                                    try {
                                        $service->confirmCandidate($candidate, (int) $user->id);
                                        $confirmed++;
                                    } catch (\Throwable $e) {
                                        report($e);
                                        $failed++;
                                    }
                                });

                            if ($confirmed === 0) {
                                Notification::make()
                                    ->title('確定できた発注候補がありません')
                                    ->body($failed > 0 ? "失敗: {$failed}件" : null)
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title("{$confirmed}件の発注候補を確定しました")
                                ->body($failed > 0 ? "失敗: {$failed}件" : null)
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

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

    public static function resolveItemContractorOrderSettings(WmsOrderCandidate $record): array
    {
        $key = implode(':', [
            $record->warehouse_id,
            $record->item_id,
            $record->contractor_id,
        ]);

        if (array_key_exists($key, static::$itemContractorOrderSettingCache)) {
            return static::$itemContractorOrderSettingCache[$key];
        }

        $setting = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $record->warehouse_id)
            ->where('item_id', $record->item_id)
            ->where('contractor_id', $record->contractor_id)
            ->select('safety_stock', 'max_stock', 'min_stock', 'auto_order_quantity', 'is_auto_order')
            ->first();

        return static::$itemContractorOrderSettingCache[$key] = [
            'safety_stock' => (int) ($setting?->safety_stock ?? $record->safety_stock ?? 0),
            'max_stock' => (int) ($setting?->max_stock ?? 0),
            'min_stock' => (int) ($setting?->min_stock ?? 0),
            'auto_order_quantity' => (int) ($setting?->auto_order_quantity ?? 0),
            'is_auto_order' => (bool) ($setting?->is_auto_order ?? false),
        ];
    }

    public static function preloadItemContractorOrderSettings(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $tuples = $records
            ->map(fn (WmsOrderCandidate $record): array => [
                $record->warehouse_id,
                $record->item_id,
                $record->contractor_id,
            ])
            ->unique(fn (array $tuple): string => implode(':', $tuple))
            ->values();

        $missingTuples = $tuples
            ->reject(fn (array $tuple): bool => array_key_exists(implode(':', $tuple), static::$itemContractorOrderSettingCache))
            ->values();

        if ($missingTuples->isEmpty()) {
            return;
        }

        $settings = DB::connection('sakemaru')
            ->table('item_contractors')
            ->whereRaw(
                '(warehouse_id, item_id, contractor_id) IN ('.
                $missingTuples->map(fn () => '(?, ?, ?)')->implode(', ').')',
                $missingTuples->flatten()->toArray()
            )
            ->select('warehouse_id', 'item_id', 'contractor_id', 'safety_stock', 'max_stock', 'min_stock', 'auto_order_quantity', 'is_auto_order')
            ->get()
            ->keyBy(fn ($setting): string => "{$setting->warehouse_id}:{$setting->item_id}:{$setting->contractor_id}");

        $recordsByKey = $records->keyBy(
            fn (WmsOrderCandidate $record): string => "{$record->warehouse_id}:{$record->item_id}:{$record->contractor_id}"
        );

        foreach ($missingTuples as $tuple) {
            $key = implode(':', $tuple);
            $setting = $settings->get($key);
            $record = $recordsByKey->get($key);

            static::$itemContractorOrderSettingCache[$key] = [
                'safety_stock' => (int) ($setting?->safety_stock ?? $record?->safety_stock ?? 0),
                'max_stock' => (int) ($setting?->max_stock ?? 0),
                'min_stock' => (int) ($setting?->min_stock ?? 0),
                'auto_order_quantity' => (int) ($setting?->auto_order_quantity ?? 0),
                'is_auto_order' => (bool) ($setting?->is_auto_order ?? false),
            ];
        }
    }

    public static function resolveOrderingUnitQuantity(WmsOrderCandidate $record): ?int
    {
        $orderingCode = static::normalizeOrderingCode($record->ordering_code);
        $capacityCase = (int) ($record->item?->capacity_case ?? 0);

        if ($capacityCase <= 0) {
            $capacityCase = (int) (DB::connection('sakemaru')
                ->table('items')
                ->where('id', $record->item_id)
                ->value('capacity_case') ?? 0);
        }

        $key = static::orderingUnitQuantityCacheKey((int) $record->item_id, $orderingCode, $capacityCase);
        if (array_key_exists($key, static::$orderingUnitQuantityCache)) {
            return static::$orderingUnitQuantityCache[$key];
        }

        $query = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->where('isi.item_id', $record->item_id)
            ->where('isi.is_active', true)
            ->where('iqi.quantity', '>', 1)
            ->when($capacityCase > 1, fn ($query) => $query->where('iqi.quantity', '!=', $capacityCase));

        if ($orderingCode) {
            $exact = (clone $query)
                ->whereRaw('LPAD(isi.search_string, 13, "0") = ?', [$orderingCode])
                ->value('iqi.quantity');

            if ($exact !== null) {
                return static::$orderingUnitQuantityCache[$key] = (int) $exact;
            }
        }

        $quantity = $query
            ->whereRaw("isi.search_string REGEXP '[1-9]'")
            ->orderByDesc('isi.is_used_for_ordering')
            ->orderBy('iqi.quantity')
            ->value('iqi.quantity');

        return static::$orderingUnitQuantityCache[$key] = $quantity !== null ? (int) $quantity : null;
    }

    public static function preloadOrderingUnitQuantities(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $recordTargets = $records
            ->map(function (WmsOrderCandidate $record): array {
                $capacityCase = (int) ($record->item?->capacity_case ?? 0);

                return [
                    'item_id' => (int) $record->item_id,
                    'ordering_code' => static::normalizeOrderingCode($record->ordering_code),
                    'capacity_case' => $capacityCase,
                ];
            })
            ->values();

        $missingCapacityItemIds = $recordTargets
            ->where('capacity_case', '<=', 0)
            ->pluck('item_id')
            ->unique()
            ->values();

        $capacityCases = $missingCapacityItemIds->isEmpty()
            ? collect()
            : DB::connection('sakemaru')
                ->table('items')
                ->whereIn('id', $missingCapacityItemIds->all())
                ->pluck('capacity_case', 'id');

        $targets = $recordTargets
            ->map(function (array $target) use ($capacityCases): array {
                $capacityCase = (int) ($target['capacity_case'] ?: ($capacityCases->get($target['item_id']) ?? 0));

                return [
                    'item_id' => $target['item_id'],
                    'ordering_code' => $target['ordering_code'],
                    'capacity_case' => $capacityCase,
                    'key' => static::orderingUnitQuantityCacheKey($target['item_id'], $target['ordering_code'], $capacityCase),
                ];
            })
            ->unique('key')
            ->reject(fn (array $target): bool => array_key_exists($target['key'], static::$orderingUnitQuantityCache))
            ->values();

        if ($targets->isEmpty()) {
            return;
        }

        $itemIds = $targets->pluck('item_id')->unique()->values()->all();

        $quantityRows = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->whereIn('isi.item_id', $itemIds)
            ->where('isi.is_active', true)
            ->where('iqi.quantity', '>', 1)
            ->select([
                'isi.item_id',
                'isi.search_string',
                'isi.is_used_for_ordering',
                'iqi.quantity',
            ])
            ->get()
            ->groupBy('item_id');

        foreach ($targets as $target) {
            $rows = ($quantityRows->get($target['item_id']) ?? collect())
                ->filter(fn ($row): bool => (int) $row->quantity !== (int) $target['capacity_case'] || (int) $target['capacity_case'] <= 1);

            $exact = null;
            if ($target['ordering_code'] !== null) {
                $exact = $rows->first(
                    fn ($row): bool => static::normalizeOrderingCode((string) $row->search_string) === $target['ordering_code']
                );
            }

            if ($exact !== null) {
                static::$orderingUnitQuantityCache[$target['key']] = (int) $exact->quantity;

                continue;
            }

            $fallback = $rows
                ->filter(fn ($row): bool => preg_match('/[1-9]/', (string) $row->search_string) === 1)
                ->sort(function ($a, $b): int {
                    return ((int) $b->is_used_for_ordering <=> (int) $a->is_used_for_ordering)
                        ?: ((int) $a->quantity <=> (int) $b->quantity);
                })
                ->first();

            static::$orderingUnitQuantityCache[$target['key']] = $fallback !== null ? (int) $fallback->quantity : null;
        }
    }

    private static function orderingUnitQuantityCacheKey(int $itemId, ?string $orderingCode, int $capacityCase): string
    {
        return "{$itemId}:".($orderingCode ?? '').":{$capacityCase}";
    }

    private static function normalizeOrderingCode(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '' || preg_match('/^0+$/', $code) === 1) {
            return null;
        }

        return str_pad($code, 13, '0', STR_PAD_LEFT);
    }

    /**
     * JX送信対象の仕入先IDを取得（直接送信先＋集約元）
     *
     * @return array<int>
     */
    private static function getJxContractorIds(): array
    {
        static $ids = null;

        if ($ids !== null) {
            return $ids;
        }

        // JX設定がある仕入先（直接送信先）
        $jxContractorIds = DB::connection('sakemaru')
            ->table('wms_order_jx_settings')
            ->pluck('contractor_id')
            ->toArray();

        // transmission_contractor_id で集約される仕入先
        $mappedContractorIds = DB::connection('sakemaru')
            ->table('wms_contractor_settings')
            ->whereIn('transmission_contractor_id', $jxContractorIds)
            ->pluck('contractor_id')
            ->toArray();

        $ids = array_values(array_unique(array_merge($jxContractorIds, $mappedContractorIds)));

        return $ids;
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
