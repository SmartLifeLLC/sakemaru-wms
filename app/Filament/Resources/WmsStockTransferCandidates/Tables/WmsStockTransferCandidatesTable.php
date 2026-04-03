<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\TransferOrderRecalculationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsStockTransferCandidatesTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'transfer-candidates-table sticky-actions'])
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

                TextColumn::make('deliveryCourse.code')
                    ->label('配送CD')
                    ->state(fn ($record) => $record->deliveryCourse?->code ?? '-')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('70px'),

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

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable()
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

                // 在庫関連カラム（直接カラムから取得、なければ計算ログにフォールバック）
                TextColumn::make('current_effective_stock')
                    ->label('現在庫')
                    ->state(function ($record) {
                        // 直接カラムがあればそちらを使用
                        if ($record->current_effective_stock !== null) {
                            return $record->current_effective_stock;
                        }
                        // フォールバック: 計算ログから取得
                        $log = $record->calculationLog;

                        return $log?->current_effective_stock ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('55px'),

                // 移動元倉庫（hub_warehouse）の該当商品在庫
                TextColumn::make('hub_effective_stock')
                    ->label('倉庫在庫')
                    ->state(fn ($record) => $record->hub_effective_stock ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('incoming_quantity')
                    ->label('入荷数')
                    ->state(function ($record) {
                        // 直接カラムがあればそちらを使用
                        if ($record->incoming_quantity !== null) {
                            return $record->incoming_quantity;
                        }
                        // フォールバック: 計算ログから取得
                        $log = $record->calculationLog;

                        return $log?->incoming_quantity ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('55px'),

                TextColumn::make('calculated_available')
                    ->label('見込在庫')
                    ->state(function ($record) {
                        // 直接カラムがあればそちらを使用
                        if ($record->calculated_available !== null) {
                            return $record->calculated_available;
                        }
                        // フォールバック: 計算ログから取得
                        $log = $record->calculationLog;
                        $details = $log?->calculation_details ?? [];

                        return $details['利用可能在庫'] ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('65px'),

                TextColumn::make('safety_stock')
                    ->label('発注点')
                    ->state(function ($record) {
                        // 直接カラムがあればそちらを使用
                        if ($record->safety_stock !== null) {
                            return $record->safety_stock;
                        }
                        // フォールバック: 計算ログから取得
                        $log = $record->calculationLog;

                        return $log?->safety_stock_setting ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('shortage_qty')
                    ->label('不足分')
                    ->state(function ($record) {
                        // 直接カラムがあればそちらを使用
                        if ($record->shortage_qty !== null) {
                            return $record->shortage_qty;
                        }
                        // フォールバック: 計算ログから取得
                        $log = $record->calculationLog;

                        return $log?->calculated_shortage_qty ?? '-';
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('55px')
                    ->color(function ($record) {
                        $shortageQty = $record->shortage_qty ?? $record->calculationLog?->calculated_shortage_qty ?? 0;

                        return $shortageQty > 0 ? 'danger' : null;
                    }),

                TextColumn::make('suggested_quantity')
                    ->label('算出数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextInputColumn::make('transfer_quantity')
                    ->label('移動数')
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
                                ->title('承認後は移動数を変更できません')
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
                                    ->title('移動数を更新しました')
                                    ->body("関連発注候補の発注数も {$updatedOrder->order_quantity} に再計算されました。")
                                    ->success()
                                    ->send();

                                return;
                            }
                        }

                        Notification::make()
                            ->title('移動数を更新しました')
                            ->success()
                            ->send();
                    }),

                TextColumn::make('expected_arrival_date')
                    ->label('移動出荷日')
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
                    ->options(fn () => WmsStockTransferCandidate::query()
                        ->select('batch_code')
                        ->distinct()
                        ->orderByDesc('batch_code')
                        ->limit(50)
                        ->pluck('batch_code', 'batch_code')
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(collect(CandidateStatus::cases())->mapWithKeys(fn ($status) => [
                        $status->value => $status->label(),
                    ])),

                SelectFilter::make('satellite_warehouse_id')
                    ->label('在庫依頼倉庫')
                    ->relationship('satelliteWarehouse', 'name'),

                SelectFilter::make('hub_warehouse_id')
                    ->label('移動元倉庫')
                    ->relationship('hubWarehouse', 'name'),

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
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('approve')
                    ->label('承認')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update(['status' => CandidateStatus::APPROVED]);
                        Notification::make()
                            ->title('移動候補を承認しました')
                            ->success()
                            ->send();
                    }),

                Action::make('unapprove')
                    ->label('承認解除')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === CandidateStatus::APPROVED)
                    ->requiresConfirmation()
                    ->modalHeading('承認を解除')
                    ->modalDescription('この移動候補の承認を解除し、承認前の状態に戻します。')
                    ->action(function ($record) {
                        $record->update(['status' => CandidateStatus::PENDING]);
                        Notification::make()
                            ->title('承認を解除しました')
                            ->warning()
                            ->send();
                    }),

                Action::make('edit')
                    ->label('変更')
                    ->icon('heroicon-o-pencil')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->modalHeading('移動数変更')
                    ->modalWidth('4xl')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->fillForm(fn ($record) => [
                        'transfer_quantity' => $record->transfer_quantity,
                        'expected_arrival_date' => $record->expected_arrival_date,
                        'delivery_course_id' => $record->delivery_course_id,
                    ])
                    ->schema(function (?WmsStockTransferCandidate $record): array {
                        if (! $record) {
                            return [];
                        }

                        $item = $record->item;

                        return [
                            Grid::make(2)
                                ->schema([
                                    View::make('filament.components.stock-transfer-edit-left-panel')
                                        ->viewData([
                                            'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('Y/m/d H:i'),
                                            'satelliteWarehouseName' => $record->satelliteWarehouse ? "[{$record->satelliteWarehouse->code}]{$record->satelliteWarehouse->name}" : '-',
                                            'hubWarehouseName' => $record->hubWarehouse ? "[{$record->hubWarehouse->code}]{$record->hubWarehouse->name}" : '-',
                                            'itemCode' => $item?->code ?? '-',
                                            'itemName' => $item?->name ?? '-',
                                            'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                            'transferQuantity' => $record->transfer_quantity ?? 0,
                                        ])
                                        ->columnSpan(1),

                                    Section::make('変更項目')
                                        ->schema([
                                            Grid::make(2)
                                                ->schema([
                                                    TextInput::make('transfer_quantity')
                                                        ->label('移動数')
                                                        ->numeric()
                                                        ->required()
                                                        ->minValue(0),
                                                    DatePicker::make('expected_arrival_date')
                                                        ->label('移動出荷日')
                                                        ->required(),
                                                ]),
                                            Select::make('delivery_course_id')
                                                ->label('配送コース')
                                                ->options(fn () => DeliveryCourse::query()
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id'))
                                                ->searchable(),
                                        ])
                                        ->columnSpan(1),
                                ]),
                        ];
                    })
                    ->action(function ($record, array $data) {
                        $hasChanges = $data['transfer_quantity'] != $record->transfer_quantity
                            || $data['expected_arrival_date'] != $record->expected_arrival_date?->format('Y-m-d')
                            || $data['delivery_course_id'] != $record->delivery_course_id;

                        if ($hasChanges) {
                            $oldQuantity = $record->transfer_quantity;
                            $newQuantity = (int) $data['transfer_quantity'];

                            $record->update([
                                'transfer_quantity' => $newQuantity,
                                'expected_arrival_date' => $data['expected_arrival_date'],
                                'delivery_course_id' => $data['delivery_course_id'],
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
                        $record->update([
                            'status' => CandidateStatus::EXCLUDED,
                            'exclusion_reason' => $data['exclusion_reason'] ?? null,
                        ]);

                        // 関連発注候補を再計算（除外により発注不要になる可能性あり）
                        $recalcService = app(TransferOrderRecalculationService::class);
                        $orderExcluded = $recalcService->checkAndExcludeOrderCandidate($record);

                        if ($orderExcluded) {
                            Notification::make()
                                ->title('移動候補を除外しました')
                                ->body('関連発注候補も発注不要となったため除外されました。')
                                ->warning()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('移動候補を除外しました')
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
                            // PENDING状態のレコードIDを取得してバルクアップデート
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
                            $recalcService = app(TransferOrderRecalculationService::class);
                            $excludedOrderCount = 0;

                            $count = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->each(function ($record) use ($data, $recalcService, &$excludedOrderCount) {
                                    $record->update([
                                        'status' => CandidateStatus::EXCLUDED,
                                        'exclusion_reason' => $data['exclusion_reason'] ?? null,
                                    ]);

                                    // 関連発注候補を再計算
                                    if ($recalcService->checkAndExcludeOrderCandidate($record)) {
                                        $excludedOrderCount++;
                                    }
                                })
                                ->count();

                            $message = "{$count}件を除外しました";
                            if ($excludedOrderCount > 0) {
                                $message .= "（関連発注候補 {$excludedOrderCount}件も除外）";
                            }

                            Notification::make()
                                ->title($message)
                                ->warning()
                                ->send();
                        }),

                    BulkAction::make('bulkUpdateCourseAndDate')
                        ->label('配送コース・入荷日を一括変更')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->modalHeading('配送コース・移動出荷日を一括変更')
                        ->modalDescription('選択した承認前の移動候補の配送コースと移動出荷日を変更します。空欄の項目は変更されません。')
                        ->schema([
                            Select::make('delivery_course_id')
                                ->label('配送コース')
                                ->options(fn () => DeliveryCourse::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->placeholder('変更しない'),
                            DatePicker::make('expected_arrival_date')
                                ->label('移動出荷日'),
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

                            // PENDING状態のレコードIDを取得してバルクアップデート
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
}
