<?php

namespace App\Filament\Resources\WmsPickingTasks\Tables;

use App\Filament\Resources\WmsPickingTasks\WmsPickingTaskResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WmsPickingTasksTable
{
    public static function configure(Table $table, bool $isCompletedView = false, bool $isWaitingView = false): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('タスクID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        'SHORTAGE' => 'danger',
                        'CANCELLED' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'SHORTAGE' => '欠品あり',
                        'CANCELLED' => 'キャンセル',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('serial_ids')
                    ->label('識別ID')
                    ->default('-')
                    ->formatStateUsing(function ($record) {
                        $serialIds = $record->pickingItemResults()
                            ->with('trade')
                            ->get()
                            ->pluck('trade.serial_id')
                            ->filter()
                            ->unique()
                            ->sort()
                            ->values()
                            ->toArray();

                        return !empty($serialIds) ? implode(', ', $serialIds) : '-';
                    })
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('pickingItemResults.trade', function ($q) use ($search) {
                            $q->where('serial_id', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('partner_codes')
                    ->label('得意先コード')
                    ->default('-')
                    ->formatStateUsing(function ($record) {
                        $partnerCodes = $record->pickingItemResults()
                            ->with('earning.buyer.partner')
                            ->get()
                            ->pluck('earning.buyer.partner.code')
                            ->filter()
                            ->unique()
                            ->sort()
                            ->values()
                            ->toArray();

                        return !empty($partnerCodes) ? implode(', ', $partnerCodes) : '-';
                    })
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('pickingItemResults.earning.buyer.partner', function ($q) use ($search) {
                            $q->where('code', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('partner_names')
                    ->label('得意先名')
                    ->default('-')
                    ->formatStateUsing(function ($record) {
                        $partnerNames = $record->pickingItemResults()
                            ->with('earning.buyer.partner')
                            ->get()
                            ->pluck('earning.buyer.partner.name')
                            ->filter()
                            ->unique()
                            ->sort()
                            ->values()
                            ->toArray();

                        return !empty($partnerNames) ? implode(', ', $partnerNames) : '-';
                    })
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('pickingItemResults.earning.buyer.partner', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('floor.name')
                    ->label('フロア')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('picker.display_name')
                    ->label('ピッカー')
                    ->default('未割当')
                    ->badge()
                    ->color(fn($state) => $state !== '未割当' ? 'success' : 'danger')
                    ->sortable(),

                TextColumn::make('warehouse.code')
                    ->label('倉庫コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('deliveryCourse.code')
                    ->label('配送コースコード')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース名')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label('ピッキング日時')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('created_at')
                    ->label('生成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('delivery_course_id')
                    ->label('配送コース')
                    ->relationship('deliveryCourse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('wms_picking_area_id')
                    ->label('ピッキングエリア')
                    ->relationship('pickingArea', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'SHORTAGE' => '欠品あり',
                        'CANCELLED' => 'キャンセル',
                    ]),

                SelectFilter::make('picker_assigned')
                    ->label('担当者割当状況')
                    ->options([
                        'assigned' => '割当済み',
                        'unassigned' => '未割当',
                    ])
                    ->query(function ($query, $state) {
                        return match ($state['value'] ?? null) {
                            'assigned' => $query->whereNotNull('picker_id'),
                            'unassigned' => $query->whereNull('picker_id'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([


                Action::make('revert_to_picking')
                    ->label('取消')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('ステータス変更確認')
                    ->modalDescription('このタスクのステータスを「ピッキング中」に戻しますか？')
                    ->action(function ($record) {
                        DB::connection('sakemaru')->transaction(function () use ($record) {
                            $record->update([
                                'status' => 'PICKING',
                                'completed_at' => null,
                            ]);

                            Notification::make()
                                ->title('ステータスを変更しました')
                                ->body('タスクのステータスを「ピッキング中」に戻しました')
                                ->success()
                                ->send();
                        });
                    })
                    ->visible(fn ($record) => $isCompletedView && in_array($record->status, ['COMPLETED', 'SHORTAGE'])),



                Action::make('edit_items')
                    ->label('明細確認・修正')
                    ->icon('heroicon-o-list-bullet')
                    ->color('success')
                    ->url(fn ($record) => \App\Filament\Resources\WmsPickingTasks\WmsPickingItemEditResource::getUrl('index', [
                        'tableFilters' => [
                            'picking_task_id' => [
                                'value' => $record->id,
                            ],
                        ],
                    ]))
                    ->visible(fn ($record) => $isWaitingView),

                Action::make('assign_picker')
                    ->label('担当者割当')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->form([
                        \Filament\Forms\Components\Select::make('picker_id')
                            ->label('担当者')
                            ->options(\App\Models\User::pluck('name', 'id')) // Assuming User model is used for pickers
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'picker_id' => $data['picker_id'],
                            'status' => 'PICKING',
                        ]);

                        Notification::make()
                            ->title('担当者を割り当てました')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record) => $isWaitingView),

                Action::make('remove_picker')
                    ->label('担当解除')
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update([
                            'picker_id' => null,
                            'status' => 'PENDING',
                        ]);

                        Notification::make()
                            ->title('担当者を解除しました')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record) => !$isWaitingView && !$isCompletedView),

            ], position: RecordActionsPosition::BeforeColumns)
            ->defaultSort('created_at', 'desc')
            ->toolbarActions([
                BulkAction::make('bulk_assign_picker')
                    ->label('一括担当者割当')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->form([
                        \Filament\Forms\Components\Select::make('picker_id')
                            ->label('担当者')
                            ->options(\App\Models\User::pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $records->each(function ($record) use ($data) {
                            $record->update([
                                'picker_id' => $data['picker_id'],
                                'status' => 'PICKING',
                            ]);
                        });

                        Notification::make()
                            ->title('一括で担当者を割り当てました')
                            ->success()
                            ->send();
                    })
                    ->visible(fn () => $isWaitingView),
                BulkAction::make('assignPicker')
                    ->label('担当者を割り当てる')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->schema([
                        Select::make('picker_id')
                            ->label('ピッカー')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                return \App\Models\WmsPicker::active()
                                    ->orderBy('code')
                                    ->get()
                                    ->pluck('display_name', 'id');
                            })
                            ->helperText('担当するピッカーを選択してください'),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $pickerId = $data['picker_id'];
                        $picker = \App\Models\WmsPicker::find($pickerId);

                        // Filter out completed tasks
                        $validRecords = $records->filter(fn ($task) => $task->status !== 'COMPLETED');

                        if ($validRecords->isEmpty()) {
                            Notification::make()
                                ->title('割り当てできません')
                                ->body('完了済みのタスクには担当者を割り当てることができません')
                                ->warning()
                                ->send();
                            return;
                        }

                        // Check for restricted area access permission
                        $restrictedTasks = $validRecords->filter(fn ($task) => $task->is_restricted_area);

                        if ($restrictedTasks->isNotEmpty() && !$picker->can_access_restricted_area) {
                            Notification::make()
                                ->title('権限エラー')
                                ->body("選択されたタスクには制限エリアが含まれています。{$picker->display_name}は制限エリアへのアクセス権限がありません。")
                                ->danger()
                                ->send();
                            return;
                        }

                        $count = 0;

                        DB::connection('sakemaru')->transaction(function () use ($validRecords, $pickerId, &$count) {
                            foreach ($validRecords as $task) {
                                // Only assign if not already assigned
                                if ($task->picker_id === null) {
                                    $task->update([
                                        'picker_id' => $pickerId,
                                        'status' => 'PICKING',
                                    ]);
                                    $count++;
                                }
                            }
                        });

                        Notification::make()
                            ->title('担当者を割り当てました')
                            ->body("{$count}件のタスクを{$picker->display_name}に割り当てました")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation(),

                BulkAction::make('unassignPicker')
                    ->label('担当者割当を解除')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->action(function (Collection $records) {
                        // Filter out completed tasks
                        $validRecords = $records->filter(fn ($task) => $task->status !== 'COMPLETED');

                        if ($validRecords->isEmpty()) {
                            Notification::make()
                                ->title('解除できません')
                                ->body('完了済みのタスクの担当者は解除できません')
                                ->warning()
                                ->send();
                            return;
                        }

                        $count = 0;

                        DB::connection('sakemaru')->transaction(function () use ($validRecords, &$count) {
                            foreach ($validRecords as $task) {
                                if ($task->picker_id !== null) {
                                    $task->update([
                                        'picker_id' => null,
                                        'status' => 'PENDING',
                                    ]);
                                    $count++;
                                }
                            }
                        });

                        Notification::make()
                            ->title('担当者割当を解除しました')
                            ->body("{$count}件のタスクの担当者を解除しました")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation(),

                BulkAction::make('revert_to_picking')
                    ->label('完了取消')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->action(function (Collection $records) {
                        // Filter only completed or shortage tasks
                        $validRecords = $records->filter(fn ($task) => in_array($task->status, ['COMPLETED', 'SHORTAGE']));

                        if ($validRecords->isEmpty()) {
                            Notification::make()
                                ->title('取消できません')
                                ->body('完了またはタスクのみ取消できます')
                                ->warning()
                                ->send();
                            return;
                        }

                        $count = 0;

                        DB::connection('sakemaru')->transaction(function () use ($validRecords, &$count) {
                            foreach ($validRecords as $task) {
                                $task->update([
                                    'status' => 'PICKING',
                                    'completed_at' => null,
                                ]);
                                $count++;
                            }
                        });

                        Notification::make()
                            ->title('完了を取り消しました')
                            ->body("{$count}件のタスクのステータスを「ピッキング中」に戻しました")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->modalHeading('完了取消確認')
                    ->modalDescription('選択したタスクのステータスを「ピッキング中」に戻しますか？'),

                BulkAction::make('print')
                    ->label('印刷')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(function (Collection $records) {
                        $count = $records->count();
                        Notification::make()
                            ->title('印刷機能')
                            ->body("{$count}件のタスクを印刷します（今後実装予定）")
                            ->info()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('forceShipBulk')
                    ->label('一括強制出荷（管理者）')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->action(function (Collection $records) {
                        // Filter out completed tasks
                        $validRecords = $records->filter(fn ($task) => $task->status !== 'COMPLETED');

                        if ($validRecords->isEmpty()) {
                            Notification::make()
                                ->title('強制出荷できません')
                                ->body('すべて完了済みのタスクです')
                                ->warning()
                                ->send();
                            return;
                        }

                        $completedCount = 0;
                        $totalItems = 0;

                        DB::connection('sakemaru')->transaction(function () use ($validRecords, &$completedCount, &$totalItems) {
                            foreach ($validRecords as $task) {
                                // すべての商品のピッキング数を予定数に自動設定
                                $items = $task->pickingItemResults;

                                foreach ($items as $item) {
                                    $item->update([
                                        'picked_qty' => $item->planned_qty,
                                        'shortage_qty' => 0,
                                        'status' => 'COMPLETED',
                                        'picked_at' => now(),
                                    ]);
                                    $totalItems++;
                                }

                                // タスクを完了
                                $task->update([
                                    'status' => 'COMPLETED',
                                    'completed_at' => now(),
                                ]);

                                // このタスクに関連する全ての伝票のピッキングステータスを更新
                                // Note: earning_id is now stored in wms_picking_item_results
                                $earningIds = $task->pickingItemResults()
                                    ->distinct('earning_id')
                                    ->whereNotNull('earning_id')
                                    ->pluck('earning_id')
                                    ->toArray();

                                if (!empty($earningIds)) {
                                    DB::connection('sakemaru')
                                        ->table('earnings')
                                        ->whereIn('id', $earningIds)
                                        ->update([
                                            'picking_status' => 'COMPLETED',
                                            'updated_at' => now(),
                                        ]);
                                }

                                $completedCount++;
                            }
                        });

                        Notification::make()
                            ->title('一括強制出荷しました')
                            ->body("{$completedCount}件のタスク（{$totalItems}商品）を強制出荷しました")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->modalHeading('一括強制出荷確認')
                    ->modalDescription('選択したすべてのタスクを強制出荷します。各タスクのすべての商品のピッキング数が予定数に自動設定され、出荷可能状態になります。この操作は取り消せません。'),
            ]);
    }
}
