<?php

namespace App\Filament\Resources\WmsShortageAllocations\Tables;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsShortageAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('shortage.warehouse.name')
                    ->label('元倉庫')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('targetWarehouse.name')
                    ->label('横持ち出荷倉庫')
                    ->sortable()
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('shortage.item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('shortage.item.name')
                    ->label('商品名')
                    ->searchable()
                    ->alignment('center')
                    ->wrap(),

                TextColumn::make('shortage.item.volume')
                    ->label('容量')
                    ->alignment('center')
                    ->formatStateUsing(fn ($record) =>
                        $record->shortage?->item?->volume && $record->shortage?->item?->volume_unit
                            ? $record->shortage->item->volume . \App\Enums\EVolumeUnit::tryFrom($record->shortage->item->volume_unit)?->name()
                            : ''
                    ),

                TextColumn::make('shortage.item.case_size')
                    ->label('入り数')
                    ->alignment('center')
                    ->formatStateUsing(fn ($state) => $state ? $state : '-'),

                TextColumn::make('shortage.trade.partner.name')
                    ->label('得意先')
                    ->searchable()
                    ->limit(20)
                    ->alignment('center'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース')
                    ->searchable()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('assign_qty_type')
                    ->label('単位')
                    ->formatStateUsing(fn (string $state): string => \App\Enums\QuantityType::tryFrom($state)?->name() ?? $state)
                    ->alignment('center'),

                TextColumn::make('assign_qty')
                    ->label('予定数')
                    ->alignment('center'),

                TextInputColumn::make('picked_qty')
                    ->label('ピック数')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->disabled(fn (WmsShortageAllocation $record): bool =>
                        !in_array($record->status, ['RESERVED', 'PICKING'])
                    )
                    ->afterStateUpdated(function (WmsShortageAllocation $record, $state) {
                        // picked_qtyがassign_qtyを超えないようにチェック
                        if ($state > $record->assign_qty) {
                            Notification::make()
                                ->title('エラー')
                                ->body("ピック数は予定数（{$record->assign_qty}）を超えることはできません")
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->picked_qty = $state;

                        // ピック数量が入力されたらステータスをPICKINGに変更（完了していない場合）
                        if ($state > 0 && !$record->is_finished) {
                            $record->status = 'PICKING';
                        }

                        $record->save();

                        Notification::make()
                            ->title('ピック数を更新しました')
                            ->success()
                            ->send();
                    })
                    ->alignment('center')
                    ->extraInputAttributes([
                        'class' => 'w-20 !h-8 !py-1 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-sm',
                        'style' => 'height: 32px !important; padding-top: 0.25rem !important; padding-bottom: 0.25rem !important;',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ]),

                TextColumn::make('remaining_qty')
                    ->label('欠品数')
                    ->getStateUsing(fn (WmsShortageAllocation $record): int => $record->remaining_qty)
                    ->color(fn (WmsShortageAllocation $record): string => $record->remaining_qty > 0 ? 'warning' : 'success')
                    ->weight('bold')
                    ->alignment('center'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'RESERVED' => 'info',
                        'PICKING' => 'warning',
                        'FULFILLED' => 'success',
                        'SHORTAGE' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '承認待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                        default => $state,
                    })
                    ->alignment('center'),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '承認待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                    ]),

                SelectFilter::make('shortage.warehouse_id')
                    ->label('元倉庫')
                    ->relationship('shortage.warehouse', 'name')
                    ->searchable(),

                SelectFilter::make('target_warehouse_id')
                    ->label('横持ち出荷倉庫')
                    ->relationship('targetWarehouse', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                // 完了ボタン
                Action::make('complete')
                    ->label('完了')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WmsShortageAllocation $record): bool =>
                        !$record->is_finished && in_array($record->status, ['PICKING', 'RESERVED'])
                    )
                    ->requiresConfirmation()
                    ->modalHeading('横持ち出荷の完了確認')
                    ->modalDescription(fn (WmsShortageAllocation $record): string =>
                        "予定数: {$record->assign_qty}、ピック数: {$record->picked_qty}、欠品数: {$record->remaining_qty}"
                    )
                    ->modalSubmitActionLabel('完了')
                    ->action(function (WmsShortageAllocation $record): void {
                        $record->is_finished = true;
                        $record->finished_at = now();
                        $record->finished_user_id = auth()->id();

                        // 完了時のステータス判定
                        if ($record->picked_qty >= $record->assign_qty) {
                            $record->status = 'FULFILLED';
                        } elseif ($record->picked_qty > 0 && $record->remaining_qty > 0) {
                            $record->status = 'SHORTAGE';
                        }

                        $record->save();

                        Notification::make()
                            ->title('横持ち出荷を完了しました')
                            ->body("ステータス: {$record->status}")
                            ->success()
                            ->send();
                    }),

                // 追加の横持ち出荷アクション（残数量がある場合のみ）
                Action::make('addPartialShipment')
                    ->label('追加横持ち出荷')
                    ->icon('heroicon-o-plus-circle')
                    ->color('info')
                    ->visible(fn (WmsShortageAllocation $record): bool =>
                        $record->remaining_qty > 0 && in_array($record->status, ['PICKING', 'RESERVED'])
                    )
                    ->modalHeading('追加の横持ち出荷指示')
                    ->modalSubmitActionLabel('確定')
                    ->form([
                        Section::make()
                            ->schema([
                                TextInput::make('current_assign_qty')
                                    ->label('現在の出荷数量')
                                    ->default(fn (WmsShortageAllocation $record) => $record->assign_qty)
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('current_picked_qty')
                                    ->label('ピッキング済み数量')
                                    ->default(fn (WmsShortageAllocation $record) => $record->picked_qty)
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('remaining_qty_display')
                                    ->label('残数量')
                                    ->default(fn (WmsShortageAllocation $record) => $record->remaining_qty)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('この数量を別の倉庫から横持ち出荷できます'),
                            ])
                            ->columns(3),

                        Select::make('new_from_warehouse_id')
                            ->label('追加横持ち出荷倉庫')
                            ->options(function (WmsShortageAllocation $record) {
                                return Warehouse::where('id', '!=', $record->target_warehouse_id)
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->required()
                            ->searchable()
                            ->validationAttribute('横持ち出荷倉庫'),

                        TextInput::make('new_assign_qty')
                            ->label('追加横持ち出荷数量')
                            ->numeric()
                            ->required()
                            ->default(fn (WmsShortageAllocation $record) => $record->remaining_qty)
                            ->minValue(1)
                            ->maxValue(fn (WmsShortageAllocation $record) => $record->remaining_qty)
                            ->helperText(fn (WmsShortageAllocation $record) => "最大: {$record->remaining_qty}")
                            ->validationAttribute('出荷数量'),
                    ])
                    ->action(function (WmsShortageAllocation $record, array $data): void {
                        try {
                            $service = app(ProxyShipmentService::class);

                            // 新しい横持ち出荷レコードを作成
                            $newAllocation = $service->createProxyShipment(
                                shortage: $record->shortage,
                                fromWarehouseId: $data['new_from_warehouse_id'],
                                assignQty: $data['new_assign_qty'],
                                assignQtyType: $record->assign_qty_type,
                                createdBy: auth()->id() ?? 0
                            );

                            Notification::make()
                                ->title('追加の横持ち出荷を作成しました')
                                ->body("横持ち出荷ID: {$newAllocation->id}、数量: {$data['new_assign_qty']}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkComplete')
                        ->label('一括完了')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('横持ち出荷の一括完了確認')
                        ->modalDescription(fn (Collection $records): string =>
                            "選択された {$records->count()} 件の横持ち出荷を完了します。"
                        )
                        ->modalSubmitActionLabel('完了')
                        ->action(function (Collection $records): void {
                            $userId = auth()->id();
                            $completedCount = 0;
                            $fulfilledCount = 0;
                            $shortageCount = 0;

                            foreach ($records as $record) {
                                // 既に完了している、またはステータスがPICKING/RESERVED以外の場合はスキップ
                                if ($record->is_finished || !in_array($record->status, ['PICKING', 'RESERVED'])) {
                                    continue;
                                }

                                $record->is_finished = true;
                                $record->finished_at = now();
                                $record->finished_user_id = $userId;

                                // 完了時のステータス判定
                                if ($record->picked_qty >= $record->assign_qty) {
                                    $record->status = 'FULFILLED';
                                    $fulfilledCount++;
                                } elseif ($record->picked_qty > 0 && $record->remaining_qty > 0) {
                                    $record->status = 'SHORTAGE';
                                    $shortageCount++;
                                }

                                $record->save();
                                $completedCount++;
                            }

                            Notification::make()
                                ->title('横持ち出荷を一括完了しました')
                                ->body("完了件数: {$completedCount}件 (完了: {$fulfilledCount}件、欠品: {$shortageCount}件)")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
