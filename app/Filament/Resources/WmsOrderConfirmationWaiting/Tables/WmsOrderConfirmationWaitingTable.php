<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
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

                        return number_format((float) $record->purchase_unit_price * $record->order_quantity, 2);
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

                static::warehouseFilter()->label('在庫拠点倉庫'),

                static::contractorFilter(),

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
                                    'safetyStock' => $details['安全在庫'] ?? 0,
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
                Action::make('adjustSixPackOrders')
                    ->label('6缶パック補正')
                    ->icon('heroicon-o-calculator')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('6缶パック発注数・単価補正')
                    ->modalDescription('承認待ちの発注候補から6缶パック発注CDの商品を確認し、発注数と単価を補正します。')
                    ->modalSubmitActionLabel('補正を実行')
                    ->modalCancelActionLabel('補正せず閉じる')
                    ->action(function () {
                        $result = static::adjustSixPackOrderCandidates();

                        if ($result['target_count'] === 0) {
                            Notification::make()
                                ->title('6缶パック発注候補はありません')
                                ->body('承認待ちの発注候補に6缶パック発注CDの商品は見つかりませんでした。')
                                ->info()
                                ->send();

                            return;
                        }

                        $notification = Notification::make()
                            ->title('6缶パック補正を実行しました')
                            ->body("対象: {$result['target_count']}件 / 更新: {$result['updated_count']}件 / 変更なし: {$result['unchanged_count']}件")
                            ->success();

                        if ($result['skipped_count'] > 0) {
                            $notification
                                ->title('6缶パック補正が一部未完了です')
                                ->body("対象: {$result['target_count']}件 / 更新: {$result['updated_count']}件 / 変更なし: {$result['unchanged_count']}件 / 単価未設定: {$result['skipped_count']}件")
                                ->warning();
                        }

                        $notification->send();
                    }),
                static::getExportAction(),
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

    /**
     * 承認待ちの6缶パック候補を、発注数=6缶パック数、単価=バラ単価×6に補正する。
     *
     * @return array{target_count: int, updated_count: int, unchanged_count: int, skipped_count: int}
     */
    private static function adjustSixPackOrderCandidates(): array
    {
        $result = [
            'target_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'skipped_count' => 0,
        ];

        WmsOrderCandidate::query()
            ->where('status', CandidateStatus::APPROVED)
            ->with('item.current_price')
            ->orderBy('id')
            ->get()
            ->each(function (WmsOrderCandidate $record) use (&$result) {
                if (! static::isSixPackOrderingCandidate($record)) {
                    return;
                }

                $result['target_count']++;

                $expectedUnitPrice = static::resolveSixPackPurchaseUnitPrice($record);
                if ($expectedUnitPrice === null) {
                    $result['skipped_count']++;

                    return;
                }

                $expectedOrderQuantity = static::resolveSixPackOrderQuantity($record);
                $currentUnitPrice = $record->purchase_unit_price !== null
                    ? round((float) $record->purchase_unit_price, 2)
                    : null;

                $needsUpdate = $record->quantity_type !== QuantityType::CASE
                    || (int) $record->order_quantity !== $expectedOrderQuantity
                    || $currentUnitPrice === null
                    || abs($currentUnitPrice - $expectedUnitPrice) >= 0.01;

                if (! $needsUpdate) {
                    $result['unchanged_count']++;

                    return;
                }

                $record->update([
                    'quantity_type' => QuantityType::CASE->value,
                    'order_quantity' => $expectedOrderQuantity,
                    'purchase_unit_price' => $expectedUnitPrice,
                    'is_manually_modified' => true,
                    'modified_by' => auth()->id(),
                    'modified_at' => now(),
                ]);
                $result['updated_count']++;
            });

        return $result;
    }

    private static function isSixPackOrderingCandidate(WmsOrderCandidate $record): bool
    {
        $orderingCode = static::normalizeOrderingCode($record->ordering_code);
        if (! $orderingCode) {
            return false;
        }

        return DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->where('isi.item_id', $record->item_id)
            ->where('isi.is_active', true)
            ->where('iqi.can_order', true)
            ->where('iqi.quantity', 6)
            ->whereRaw('LPAD(isi.search_string, 13, "0") = ?', [$orderingCode])
            ->exists();
    }

    private static function resolveSixPackOrderQuantity(WmsOrderCandidate $record): int
    {
        $currentQuantity = max(0, (int) $record->order_quantity);
        $suggestedQuantity = max(0, (int) $record->suggested_quantity);

        if ($suggestedQuantity >= 6
            && $suggestedQuantity % 6 === 0
            && $currentQuantity * 6 !== $suggestedQuantity
        ) {
            return (int) ($suggestedQuantity / 6);
        }

        return $currentQuantity;
    }

    private static function resolveSixPackPurchaseUnitPrice(WmsOrderCandidate $record): ?float
    {
        $piecePrice = $record->item?->current_price?->purchase_unit_price;

        if ($piecePrice === null) {
            return null;
        }

        return round((float) $piecePrice * 6, 2);
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
}
