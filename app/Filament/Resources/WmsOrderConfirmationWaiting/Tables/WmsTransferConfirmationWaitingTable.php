<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\TransferCandidateExecutionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class WmsTransferConfirmationWaitingTable
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
            ->extraAttributes(['class' => 'transfer-confirmation-waiting-table sticky-actions'])
            ->columns([
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

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $itemIds = DB::connection('sakemaru')
                            ->table('item_search_information as isi')
                            ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
                            ->where('isi.is_active', true)
                            ->where(fn ($q) => $q
                                ->where('iqi.own_code', 'like', "%{$search}%")
                                ->orWhere('iqi.product_code', 'like', "%{$search}%"))
                            ->distinct()
                            ->pluck('isi.item_id')
                            ->all();

                        return $query->where(fn ($q) => $q
                            ->where('item_code', 'like', "%{$search}%")
                            ->orWhereIn('item_id', $itemIds));
                    })
                    ->sortable()
                    ->width('100px'),

                TextColumn::make('search_code')
                    ->label('検索CD')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('-')
                    ->width('120px'),

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
                    ->width('70px'),

                TextColumn::make('expected_arrival_date')
                    ->label('移動出荷日')
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

                static::modifierColumn(),

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

                static::candidateCreatorFilter(),

                static::modifierFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('移動候補詳細')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(fn ($record, $action) => $record->status->isEditable()
                        ? $action->makeModalSubmitAction('submit', [])->label('変更を保存')->color('danger')
                        : false)
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->fillForm(fn ($record) => [
                        'expected_arrival_date' => $record->expected_arrival_date,
                    ])
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
                                    'formula' => $details['計算式'] ?? '-',
                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                    'incomingStock' => $details['入庫予定数'] ?? 0,
                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                    'safetyStock' => $details['安全在庫'] ?? 0,
                                    'shortageQty' => $details['不足数'] ?? 0,
                                ]),
                        ];

                        if ($isEditable) {
                            $schema[] = DatePicker::make('expected_arrival_date')
                                ->label('移動出荷日')
                                ->required();
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

                        $updated = false;
                        $updateData = [
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ];

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
                                ->title('移動候補を更新しました')
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
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('bulkConfirmSelectedTransfers')
                        ->label('選択を物流発注(店間）確定')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (): bool => auth()->user()?->email === 'admin@sakemaru.ai')
                        ->requiresConfirmation()
                        ->modalHeading('選択した物流発注(店間）候補を確定')
                        ->modalDescription(function (Collection $records): string {
                            $approved = $records->filter(fn ($r) => $r->status === CandidateStatus::APPROVED);
                            $zeroCount = $approved->filter(fn ($r) => (int) $r->transfer_quantity <= 0)->count();
                            $confirmableCount = max(0, $approved->count() - $zeroCount);

                            return "選択した承認済み物流発注(店間）候補を、作成者に関係なく確定します。\n\n".
                                "確定対象: {$confirmableCount}件\n".
                                "発注数0のため削除: {$zeroCount}件";
                        })
                        ->modalContent(function (Collection $records): ?HtmlString {
                            $approved = $records->filter(fn ($r) => $r->status === CandidateStatus::APPROVED);
                            $zeroCount = $approved->filter(fn ($r) => (int) $r->transfer_quantity <= 0)->count();

                            if ($zeroCount <= 0) {
                                return null;
                            }

                            $confirmableCount = max(0, $approved->count() - $zeroCount);

                            return new HtmlString(
                                '<div class="mb-4 rounded-lg border-2 border-red-300 bg-red-50 p-5 text-center dark:border-red-700 dark:bg-red-950/30">'.
                                '<div class="text-xl font-black leading-tight text-red-700 dark:text-red-300">確定対象: '.number_format($confirmableCount).'件</div>'.
                                '<div class="mt-2 text-2xl font-black leading-tight text-red-700 dark:text-red-300">発注数0のため削除: '.number_format($zeroCount).'件</div>'.
                                '<div class="mt-3 text-sm text-red-700 dark:text-red-300">確定時に削除になります。</div>'.
                                '</div>'
                            );
                        })
                        ->modalSubmitActionLabel('確定実行')
                        ->modalCancelActionLabel('確定せず閉じる')
                        ->action(function (Collection $records) {
                            $user = auth()->user();

                            if ($user?->email !== 'admin@sakemaru.ai' || ! $user->id) {
                                Notification::make()
                                    ->title('この操作は許可されていません')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $approvedIds = $records
                                ->filter(fn ($r) => $r->status === CandidateStatus::APPROVED && (int) $r->transfer_quantity > 0)
                                ->pluck('id')
                                ->map(fn ($id) => (int) $id)
                                ->all();

                            $zeroQuantityIds = $records
                                ->filter(fn ($r) => $r->status === CandidateStatus::APPROVED && (int) $r->transfer_quantity <= 0)
                                ->pluck('id')
                                ->map(fn ($id) => (int) $id)
                                ->all();

                            $deletedZeroCount = 0;
                            if (! empty($zeroQuantityIds)) {
                                $deletedZeroCount = WmsStockTransferCandidate::whereIn('id', $zeroQuantityIds)
                                    ->where('status', CandidateStatus::APPROVED)
                                    ->where('transfer_quantity', '<=', 0)
                                    ->delete();
                            }

                            if (empty($approvedIds)) {
                                Notification::make()
                                    ->title($deletedZeroCount > 0 ? '発注数0の物流発注(店間）候補を削除しました' : '承認済みのレコードがありません')
                                    ->body($deletedZeroCount > 0 ? "削除: {$deletedZeroCount}件" : null)
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $executed = app(TransferCandidateExecutionService::class)
                                ->executeMultiple($approvedIds, (int) $user->id)
                                ->count();

                            if ($executed === 0) {
                                Notification::make()
                                    ->title('確定できた物流発注(店間）候補がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title("{$executed}件の物流発注(店間）候補を確定しました")
                                ->body($deletedZeroCount > 0 ? "発注数0のため削除: {$deletedZeroCount}件" : null)
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
                        ->modalDescription('選択した物流発注(店間）候補の承認を取り消します。')
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

    private static function candidateCreatorFilter(): SelectFilter
    {
        return SelectFilter::make('candidate_created_by')
            ->label('作成者')
            ->searchable()
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
