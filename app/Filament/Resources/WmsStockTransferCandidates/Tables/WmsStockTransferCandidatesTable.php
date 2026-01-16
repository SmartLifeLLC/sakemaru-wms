<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsStockTransferCandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'transfer-candidates-table'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('計算時刻')
                    ->state(function ($record) {
                        // batch_code は YmdHis 形式 (例: 20251227230547)
                        return \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('m/d H:i');
                    })
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

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable()
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

                TextColumn::make('suggested_quantity')
                    ->label('算出数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('transfer_quantity')
                    ->label('移動数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

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
                    ->modalHeading('移動候補を変更')
                    ->modalWidth('6xl')
                    ->fillForm(fn ($record) => [
                        'transfer_quantity' => $record->transfer_quantity,
                        'expected_arrival_date' => $record->expected_arrival_date,
                        'delivery_course_id' => $record->delivery_course_id,
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

                        return [
                            Grid::make(3)
                                ->schema([
                                    View::make('filament.components.stock-transfer-left-panel')
                                        ->viewData([
                                            'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('Y/m/d H:i'),
                                            'satelliteWarehouseName' => $record->satelliteWarehouse ? "[{$record->satelliteWarehouse->code}]{$record->satelliteWarehouse->name}" : '-',
                                            'hubWarehouseName' => $record->hubWarehouse ? "[{$record->hubWarehouse->code}]{$record->hubWarehouse->name}" : '-',
                                            'expectedArrivalDate' => $record->expected_arrival_date
                                                ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                                                : '-',
                                            'itemCode' => $item?->code ?? '-',
                                            'itemName' => $item?->name ?? '-',
                                            'packaging' => $item?->packaging ?? '-',
                                            'capacityText' => $capacityText,
                                        ])
                                        ->columnSpan(1),

                                    Section::make('移動情報')
                                        ->schema([
                                            View::make('filament.components.stock-transfer-right-panel')
                                                ->viewData([
                                                    'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                                    'transferQuantity' => $record->transfer_quantity ?? 0,
                                                    'hasCalculationLog' => ! empty($details),
                                                    'formula' => $details['計算式'] ?? '-',
                                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                                    'incomingStock' => $details['入庫予定数'] ?? 0,
                                                    'safetyStock' => $details['安全在庫'] ?? 0,
                                                    'calculatedAvailable' => $details['利用可能在庫'] ?? 0,
                                                    'shortageQty' => $details['不足数'] ?? 0,
                                                ]),

                                            Section::make('変更')
                                                ->schema([
                                                    TextInput::make('transfer_quantity')
                                                        ->label('移動数')
                                                        ->numeric()
                                                        ->required()
                                                        ->minValue(0),
                                                    DatePicker::make('expected_arrival_date')
                                                        ->label('移動出荷日')
                                                        ->required(),
                                                    Select::make('delivery_course_id')
                                                        ->label('配送コース')
                                                        ->options(fn () => DeliveryCourse::query()
                                                            ->orderBy('name')
                                                            ->pluck('name', 'id'))
                                                        ->searchable(),
                                                ]),
                                        ])
                                        ->columnSpan(2),
                                ]),
                        ];
                    })
                    ->action(function ($record, array $data) {
                        $hasChanges = $data['transfer_quantity'] != $record->transfer_quantity
                            || $data['expected_arrival_date'] != $record->expected_arrival_date?->format('Y-m-d')
                            || $data['delivery_course_id'] != $record->delivery_course_id;

                        if ($hasChanges) {
                            $record->update([
                                'transfer_quantity' => $data['transfer_quantity'],
                                'expected_arrival_date' => $data['expected_arrival_date'],
                                'delivery_course_id' => $data['delivery_course_id'],
                                'is_manually_modified' => true,
                                'modified_by' => auth()->id(),
                                'modified_at' => now(),
                            ]);
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
                        Notification::make()
                            ->title('移動候補を除外しました')
                            ->warning()
                            ->send();
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

                            $count = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->each(fn ($record) => $record->update($updateData))
                                ->count();

                            Notification::make()
                                ->title("{$count}件を更新しました")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
