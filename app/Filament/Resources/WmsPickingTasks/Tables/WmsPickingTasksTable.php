<?php

namespace App\Filament\Resources\WmsPickingTasks\Tables;

use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use App\Filament\Resources\WmsPickingTasks\WmsPickingTaskResource;
use App\Models\WmsAdminOperationLog;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\PaginationOptions;


class WmsPickingTasksTable
{
    public static function configure(Table $table, bool $isCompletedView = false, bool $isWaitingView = false): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('タスクID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        'SHORTAGE' => 'danger',
                        'CANCELLED' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'SHORTAGE' => '欠品あり',
                        'CANCELLED' => 'キャンセル',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('soft_shortage_count')
                    ->label('引当欠品')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state) => $state > 0 ? "欠品あり ({$state}件)" : '-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('picking_shortage_count')
                    ->label('庫内欠品')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->state(function ($record) {
                        // planned_qty - picked_qty の合計を計算
                        return $record->pickingItemResults->sum(function ($item) {
                            return max(0, ($item->planned_qty ?? 0) - ($item->picked_qty ?? 0));
                        });
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state}" : '-')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->visible(! $isWaitingView),

                TextColumn::make('serial_ids')
                    ->label('識別ID')
                    ->default('-')
                    ->formatStateUsing(function ($record) {
                        // Use already eager-loaded relation
                        $serialIds = $record->pickingItemResults
                            ->pluck('trade.serial_id')
                            ->filter()
                            ->unique()
                            ->sort()
                            ->values()
                            ->toArray();

                        return ! empty($serialIds) ? implode(', ', $serialIds) : '-';
                    })
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('pickingItemResults.trade', function ($q) use ($search) {
                            $q->where('serial_id', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('partner_count')
                    ->label('得意先数')
                    ->default('-')
                    ->formatStateUsing(function ($record) {
                        // Use already eager-loaded relation
                        $count = $record->pickingItemResults
                            ->pluck('earning.buyer.partner.id')
                            ->filter()
                            ->unique()
                            ->count();

                        return $count > 0 ? "{$count}件" : '-';
                    })
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('partner_names')
                    ->label('得意先名')
                    ->default('-')
                    ->formatStateUsing(function ($record) {
                        // Use already eager-loaded relation
                        $partnerNames = $record->pickingItemResults
                            ->pluck('earning.buyer.partner.name')
                            ->filter()
                            ->unique()
                            ->sort()
                            ->values();

                        if ($partnerNames->isEmpty()) {
                            return '-';
                        }

                        // 2件以上の場合は6文字で省略（...なし）
                        if ($partnerNames->count() >= 2) {
                            $partnerNames = $partnerNames->map(fn ($name) => mb_substr($name, 0, 6));
                        }

                        return $partnerNames->implode(', ');
                    })
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('pickingItemResults.earning.buyer.partner', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('warehouse.code')
                    ->label('倉庫コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('floor.name')
                    ->label('フロア')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('temperature_type')
                    ->label('温度帯')
                    ->badge()
                    ->color(fn ($record) => $record->temperature_type?->color() ?? 'gray')
                    ->formatStateUsing(fn ($record) => $record->temperature_type?->label() ?? '-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('is_restricted_area')
                    ->label('制限エリア')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => $state ? '制限' : '通常')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('picker.display_name')
                    ->label('ピッカー')
                    ->default('未割当')
                    ->badge()
                    ->color(fn ($state) => $state !== '未割当' ? 'success' : 'danger')
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

                SelectFilter::make('has_soft_shortage')
                    ->label('引当欠品')
                    ->options([
                        'with_shortage' => '欠品あり',
                        'without_shortage' => '欠品なし',
                    ])
                    ->query(function ($query, $state) {
                        return match ($state['value'] ?? null) {
                            'with_shortage' => $query->whereHas('pickingItemResults', function ($subQuery) {
                                $subQuery->where('has_soft_shortage', true);
                            }),
                            'without_shortage' => $query->whereDoesntHave('pickingItemResults', function ($subQuery) {
                                $subQuery->where('has_soft_shortage', true);
                            }),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('execute')
                    ->label('ピッキング実施')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->url(fn ($record) => WmsPickingTaskResource::getUrl('execute', ['record' => $record->id]))
                    ->visible(fn ($record) => in_array($record->status, ['PICKING_READY', 'PICKING'])),

                Action::make('edit_items')
                    ->label('明細確認')
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

                //                Action::make('change_delivery_course')
                //                    ->label('一括コース変更')
                //                    ->icon('heroicon-o-truck')
                //                    ->color('warning')
                //                    ->form(fn ($record) => [
                //                        Select::make('delivery_course_id')
                //                            ->label('配送コース')
                //                            ->options(function () use ($record) {
                //                                if (!$record->warehouse_id) {
                //                                    return [];
                //                                }
                //                                return \App\Models\Sakemaru\DeliveryCourse::where('warehouse_id', $record->warehouse_id)
                //                                    ->orderBy('code')
                //                                    ->get()
                //                                    ->mapWithKeys(fn ($course) => [$course->id => "{$course->code} - {$course->name}"])
                //                                    ->toArray();
                //                            })
                //                            ->required()
                //                            ->searchable()
                //                            ->default(fn ($record) => $record->delivery_course_id)
                //                            ->helperText('このタスクの配送コースを変更します。同じWave内に同じ配送コースのタスクがあれば統合されます。'),
                //                    ])
                //                    ->action(function ($record, array $data) {
                //                        $newCourseId = $data['delivery_course_id'];
                //
                //                        // 現在の配送コースと同じ場合は何もしない
                //                        if ($record->delivery_course_id == $newCourseId) {
                //                            Notification::make()
                //                                ->title('変更なし')
                //                                ->body('現在と同じ配送コースが選択されています')
                //                                ->warning()
                //                                ->send();
                //                            return;
                //                        }
                //
                //                        // ステータスチェック: PENDINGのみ変更可能
                //                        if ($record->status !== 'PENDING') {
                //                            Notification::make()
                //                                ->title('変更できません')
                //                                ->body('配送コースの変更は未着手のタスクのみ可能です')
                //                                ->danger()
                //                                ->send();
                //                            return;
                //                        }
                //
                //                        $newCourse = \App\Models\Sakemaru\DeliveryCourse::find($newCourseId);
                //
                //                        if (!$newCourse) {
                //                            Notification::make()
                //                                ->title('エラー')
                //                                ->body('指定された配送コースが見つかりません')
                //                                ->danger()
                //                                ->send();
                //                            return;
                //                        }
                //
                //                        // 倉庫一致チェック
                //                        if ($newCourse->warehouse_id !== $record->warehouse_id) {
                //                            Notification::make()
                //                                ->title('変更できません')
                //                                ->body('異なる倉庫の配送コースは選択できません')
                //                                ->danger()
                //                                ->send();
                //                            return;
                //                        }
                //
                //                        DB::connection('sakemaru')->transaction(function () use ($record, $newCourse, $newCourseId) {
                //                            // 変更前の配送コース情報を保存
                //                            $oldCourseId = $record->delivery_course_id;
                //                            $oldCourse = $record->deliveryCourse;
                //
                //                            // 1. 同じwave_id内に選択された配送コースのタスクが存在するか確認
                //                            $targetTask = \App\Models\WmsPickingTask::where('wave_id', $record->wave_id)
                //                                ->where('delivery_course_id', $newCourseId)
                //                                ->where('id', '!=', $record->id)
                //                                ->where('status', 'PENDING')
                //                                ->first();
                //
                //                            if ($targetTask) {
                //                                // ケース1: 同じwave内に同じ配送コースのタスクが存在 → 統合
                //                                $itemCount = $record->pickingItemResults()->count();
                //
                //                                // このタスクに関連する伝票IDを先に取得
                //                                $earningIds = $record->pickingItemResults()
                //                                    ->distinct('earning_id')
                //                                    ->whereNotNull('earning_id')
                //                                    ->pluck('earning_id')
                //                                    ->toArray();
                //
                //                                // picking_item_resultsを移動
                //                                DB::connection('sakemaru')
                //                                    ->table('wms_picking_item_results')
                //                                    ->where('picking_task_id', $record->id)
                //                                    ->update([
                //                                        'picking_task_id' => $targetTask->id,
                //                                        'updated_at' => now(),
                //                                    ]);
                //
                //                                // このタスクに関連する伝票の配送コースを変更
                //                                if (!empty($earningIds)) {
                //                                    DB::connection('sakemaru')
                //                                        ->table('earnings')
                //                                        ->whereIn('id', $earningIds)
                //                                        ->update([
                //                                            'delivery_course_id' => $newCourseId,
                //                                            'updated_at' => now(),
                //                                        ]);
                //                                }
                //
                //                                // ログ記録
                //                                WmsAdminOperationLog::log(
                //                                    EWMSLogOperationType::CHANGE_DELIVERY_COURSE,
                //                                    [
                //                                        'target_type' => EWMSLogTargetType::PICKING_TASK,
                //                                        'target_id' => $record->id,
                //                                        'picking_task_id' => $record->id,
                //                                        'wave_id' => $record->wave_id,
                //                                        'delivery_course_id_before' => $oldCourseId,
                //                                        'delivery_course_id_after' => $newCourseId,
                //                                        'affected_count' => $itemCount,
                //                                        'operation_details' => [
                //                                            'action' => 'merge',
                //                                            'source_task_id' => $record->id,
                //                                            'target_task_id' => $targetTask->id,
                //                                            'earning_ids' => $earningIds,
                //                                            'old_course_code' => $oldCourse?->code,
                //                                            'old_course_name' => $oldCourse?->name,
                //                                            'new_course_code' => $newCourse->code,
                //                                            'new_course_name' => $newCourse->name,
                //                                        ],
                //                                    ]
                //                                );
                //
                //                                // 現在のタスクを削除
                //                                $record->delete();
                //
                //                                Notification::make()
                //                                    ->title('配送コースを変更しました（タスク統合）')
                //                                    ->body("タスクID {$record->id} の {$itemCount}件の商品をタスクID {$targetTask->id} に統合しました")
                //                                    ->success()
                //                                    ->send();
                //                            } else {
                //                                // ケース2: 同じwave内に同じ配送コースのタスクが存在しない → 配送コースのみ変更
                //                                $record->update([
                //                                    'delivery_course_id' => $newCourseId,
                //                                    'delivery_course_code' => $newCourse->code,
                //                                    'updated_at' => now(),
                //                                ]);
                //
                //                                // このタスクに関連する伝票の配送コースを変更
                //                                $earningIds = $record->pickingItemResults()
                //                                    ->distinct('earning_id')
                //                                    ->whereNotNull('earning_id')
                //                                    ->pluck('earning_id')
                //                                    ->toArray();
                //
                //                                if (!empty($earningIds)) {
                //                                    DB::connection('sakemaru')
                //                                        ->table('earnings')
                //                                        ->whereIn('id', $earningIds)
                //                                        ->update([
                //                                            'delivery_course_id' => $newCourseId,
                //                                            'updated_at' => now(),
                //                                        ]);
                //                                }
                //
                //                                // ログ記録
                //                                WmsAdminOperationLog::log(
                //                                    EWMSLogOperationType::CHANGE_DELIVERY_COURSE,
                //                                    [
                //                                        'target_type' => EWMSLogTargetType::PICKING_TASK,
                //                                        'target_id' => $record->id,
                //                                        'picking_task_id' => $record->id,
                //                                        'wave_id' => $record->wave_id,
                //                                        'delivery_course_id_before' => $oldCourseId,
                //                                        'delivery_course_id_after' => $newCourseId,
                //                                        'operation_details' => [
                //                                            'action' => 'change',
                //                                            'earning_ids' => $earningIds,
                //                                            'old_course_code' => $oldCourse?->code,
                //                                            'old_course_name' => $oldCourse?->name,
                //                                            'new_course_code' => $newCourse->code,
                //                                            'new_course_name' => $newCourse->name,
                //                                        ],
                //                                    ]
                //                                );
                //
                //                                Notification::make()
                //                                    ->title('配送コースを変更しました')
                //                                    ->body("タスクID {$record->id} の配送コースを「{$newCourse->name}」に変更しました")
                //                                    ->success()
                //                                    ->send();
                //                            }
                //                        });
                //                    })
                //                    ->visible(fn ($record) => $isWaitingView),

                //                Action::make('assign_picker')
                //                    ->label('担当者割当')
                //                    ->icon('heroicon-o-user-plus')
                //                    ->color('primary')
                //                    ->fillForm(fn ($record) => [
                //                        'warehouse_filter' => $record->warehouse_id,
                //                    ])
                //                    ->form(fn ($record) => [
                //                        \Filament\Forms\Components\Select::make('warehouse_filter')
                //                            ->label('倉庫で絞り込み')
                //                            ->options(\App\Models\Sakemaru\Warehouse::where('is_active', true)->pluck('name', 'id'))
                //                            ->searchable()
                //                            ->live()
                //                            ->afterStateUpdated(function ($state, $set) {
                //                                // Reset picker selection when warehouse filter changes
                //                                $set('picker_id', null);
                //                            })
                //                            ->placeholder('全ピッカー表示'),
                //                        \Filament\Forms\Components\Select::make('picker_id')
                //                            ->label('担当者')
                //                            ->options(function ($get) use ($record) {
                //                                $query = \App\Models\WmsPicker::query();
                //
                //                                // Filter by warehouse if selected
                //                                $warehouseFilter = $get('warehouse_filter');
                //                                if ($warehouseFilter) {
                //                                    $query->where('default_warehouse_id', $warehouseFilter);
                //                                }
                //
                //                                return $query->orderBy('code')
                //                                    ->get()
                //                                    ->pluck('display_name', 'id');
                //                            })
                //                            ->searchable()
                //                            ->required()
                //                            ->live(),
                //                    ])
                //                    ->action(function ($record, array $data) {
                //                        $picker = \App\Models\WmsPicker::find($data['picker_id']);
                //
                //                        // Check for restricted area access
                //                        if ($record->is_restricted_area && !$picker->can_access_restricted_area) {
                //                            Notification::make()
                //                                ->title('権限エラー')
                //                                ->body("このタスクは制限エリアです。{$picker->display_name}は制限エリアへのアクセス権限がありません。")
                //                                ->danger()
                //                                ->send();
                //                            return;
                //                        }
                //
                //                        $record->update([
                //                            'picker_id' => $data['picker_id'],
                //                            'status' => 'PICKING',
                //                        ]);
                //
                //                        Notification::make()
                //                            ->title('担当者を割り当てました')
                //                            ->body("{$picker->display_name}を割り当てました")
                //                            ->success()
                //                            ->send();
                //                    })
                //                    ->visible(fn ($record) => $isWaitingView),

                //                Action::make('remove_picker')
                //                    ->label('担当解除')
                //                    ->icon('heroicon-o-user-minus')
                //                    ->color('warning')
                //                    ->requiresConfirmation()
                //                    ->action(function ($record) {
                //                        $record->update([
                //                            'picker_id' => null,
                //                            'status' => 'PENDING',
                //                        ]);
                //
                //                        Notification::make()
                //                            ->title('担当者を解除しました')
                //                            ->success()
                //                            ->send();
                //                    })
                //                    ->visible(fn ($record) => !$isWaitingView && !$isCompletedView),

            ], position: RecordActionsPosition::BeforeColumns)
            ->defaultSort('created_at', 'desc');
        //            ->toolbarActions([
        //                BulkAction::make('assignPicker')
        //                    ->label('担当者を割り当てる')
        //                    ->icon('heroicon-o-user-plus')
        //                    ->color('primary')
        //                    ->fillForm(function (Collection $records) {
        //                        // Use the first record's warehouse_id as default
        //                        $firstWarehouseId = $records->first()?->warehouse_id;
        //                        return [
        //                            'warehouse_filter' => $firstWarehouseId,
        //                        ];
        //                    })
        //                    ->schema([
        //                        Select::make('warehouse_filter')
        //                            ->label('倉庫で絞り込み')
        //                            ->options(\App\Models\Sakemaru\Warehouse::where('is_active', true)->pluck('name', 'id'))
        //                            ->searchable()
        //                            ->live()
        //                            ->afterStateUpdated(function ($state, $set) {
        //                                // Reset picker selection when warehouse filter changes
        //                                $set('picker_id', null);
        //                            })
        //                            ->placeholder('全ピッカー表示'),
        //                        Select::make('picker_id')
        //                            ->label('ピッカー')
        //                            ->required()
        //                            ->searchable()
        //                            ->options(function ($get) {
        //                                $query = \App\Models\WmsPicker::query();
        //
        //                                // Filter by warehouse if selected
        //                                $warehouseFilter = $get('warehouse_filter');
        //                                if ($warehouseFilter) {
        //                                    $query->where('default_warehouse_id', $warehouseFilter);
        //                                }
        //
        //                                return $query->orderBy('code')
        //                                    ->get()
        //                                    ->pluck('display_name', 'id');
        //                            })
        //                            ->helperText('担当するピッカーを選択してください')
        //                            ->live(),
        //                    ])
        //                    ->action(function (Collection $records, array $data) {
        //                        $pickerId = $data['picker_id'];
        //                        $picker = \App\Models\WmsPicker::find($pickerId);
        //
        //                        // Filter out completed tasks
        //                        $validRecords = $records->filter(fn ($task) => $task->status !== 'COMPLETED');
        //
        //                        if ($validRecords->isEmpty()) {
        //                            Notification::make()
        //                                ->title('割り当てできません')
        //                                ->body('完了済みのタスクには担当者を割り当てることができません')
        //                                ->warning()
        //                                ->send();
        //                            return;
        //                        }
        //
        //                        // Check for restricted area access permission
        //                        $restrictedTasks = $validRecords->filter(fn ($task) => $task->is_restricted_area);
        //
        //                        if ($restrictedTasks->isNotEmpty() && !$picker->can_access_restricted_area) {
        //                            Notification::make()
        //                                ->title('権限エラー')
        //                                ->body("選択されたタスクには制限エリアが含まれています。{$picker->display_name}は制限エリアへのアクセス権限がありません。")
        //                                ->danger()
        //                                ->send();
        //                            return;
        //                        }
        //
        //                        $count = 0;
        //
        //                        DB::connection('sakemaru')->transaction(function () use ($validRecords, $pickerId, &$count) {
        //                            foreach ($validRecords as $task) {
        //                                // Only assign if not already assigned
        //                                if ($task->picker_id === null) {
        //                                    $task->update([
        //                                        'picker_id' => $pickerId,
        //                                        'status' => 'PICKING',
        //                                    ]);
        //                                    $count++;
        //                                }
        //                            }
        //                        });
        //
        //                        Notification::make()
        //                            ->title('担当者を割り当てました')
        //                            ->body("{$count}件のタスクを{$picker->display_name}に割り当てました")
        //                            ->success()
        //                            ->send();
        //                    })
        //                    ->deselectRecordsAfterCompletion()
        //                    ->requiresConfirmation(),
        //
        //                BulkAction::make('unassignPicker')
        //                    ->label('担当者割当を解除')
        //                    ->icon('heroicon-o-user-minus')
        //                    ->color('danger')
        //                    ->action(function (Collection $records) {
        //                        // Filter out completed tasks
        //                        $validRecords = $records->filter(fn ($task) => $task->status !== 'COMPLETED');
        //
        //                        if ($validRecords->isEmpty()) {
        //                            Notification::make()
        //                                ->title('解除できません')
        //                                ->body('完了済みのタスクの担当者は解除できません')
        //                                ->warning()
        //                                ->send();
        //                            return;
        //                        }
        //
        //                        $count = 0;
        //
        //                        DB::connection('sakemaru')->transaction(function () use ($validRecords, &$count) {
        //                            foreach ($validRecords as $task) {
        //                                if ($task->picker_id !== null) {
        //                                    $task->update([
        //                                        'picker_id' => null,
        //                                        'status' => 'PENDING',
        //                                    ]);
        //                                    $count++;
        //                                }
        //                            }
        //                        });
        //
        //                        Notification::make()
        //                            ->title('担当者割当を解除しました')
        //                            ->body("{$count}件のタスクの担当者を解除しました")
        //                            ->success()
        //                            ->send();
        //                    })
        //                    ->deselectRecordsAfterCompletion()
        //                    ->requiresConfirmation(),
        //
        //                BulkAction::make('revert_to_picking')
        //                    ->label('完了取消')
        //                    ->icon('heroicon-o-arrow-uturn-left')
        //                    ->color('warning')
        //                    ->action(function (Collection $records) {
        //                        // Filter only completed or shortage tasks
        //                        $validRecords = $records->filter(fn ($task) => in_array($task->status, ['COMPLETED', 'SHORTAGE']));
        //
        //                        if ($validRecords->isEmpty()) {
        //                            Notification::make()
        //                                ->title('取消できません')
        //                                ->body('完了またはタスクのみ取消できます')
        //                                ->warning()
        //                                ->send();
        //                            return;
        //                        }
        //
        //                        $count = 0;
        //
        //                        DB::connection('sakemaru')->transaction(function () use ($validRecords, &$count) {
        //                            foreach ($validRecords as $task) {
        //                                $task->update([
        //                                    'status' => 'PICKING',
        //                                    'completed_at' => null,
        //                                ]);
        //                                $count++;
        //                            }
        //                        });
        //
        //                        Notification::make()
        //                            ->title('完了を取り消しました')
        //                            ->body("{$count}件のタスクのステータスを「ピッキング中」に戻しました")
        //                            ->success()
        //                            ->send();
        //                    })
        //                    ->deselectRecordsAfterCompletion()
        //                    ->requiresConfirmation()
        //                    ->modalHeading('完了取消確認')
        //                    ->modalDescription('選択したタスクのステータスを「ピッキング中」に戻しますか？'),
        //
        //                BulkAction::make('print')
        //                    ->label('印刷')
        //                    ->icon('heroicon-o-printer')
        //                    ->color('gray')
        //                    ->action(function (Collection $records) {
        //                        $count = $records->count();
        //                        Notification::make()
        //                            ->title('印刷機能')
        //                            ->body("{$count}件のタスクを印刷します（今後実装予定）")
        //                            ->info()
        //                            ->send();
        //                    })
        //                    ->deselectRecordsAfterCompletion(),
        //
        //                BulkAction::make('forceShipBulk')
        //                    ->label('一括強制出荷（管理者）')
        //                    ->icon('heroicon-o-truck')
        //                    ->color('warning')
        //                    ->action(function (Collection $records) {
        //                        // Filter out completed tasks
        //                        $validRecords = $records->filter(fn ($task) => $task->status !== 'COMPLETED');
        //
        //                        if ($validRecords->isEmpty()) {
        //                            Notification::make()
        //                                ->title('強制出荷できません')
        //                                ->body('すべて完了済みのタスクです')
        //                                ->warning()
        //                                ->send();
        //                            return;
        //                        }
        //
        //                        $completedCount = 0;
        //                        $totalItems = 0;
        //
        //                        DB::connection('sakemaru')->transaction(function () use ($validRecords, &$completedCount, &$totalItems) {
        //                            foreach ($validRecords as $task) {
        //                                // すべての商品のピッキング数を予定数に自動設定
        //                                $items = $task->pickingItemResults;
        //
        //                                foreach ($items as $item) {
        //                                    $item->update([
        //                                        'picked_qty' => $item->planned_qty,
        //                                        'shortage_qty' => 0,
        //                                        'status' => 'COMPLETED',
        //                                        'picked_at' => now(),
        //                                    ]);
        //                                    $totalItems++;
        //                                }
        //
        //                                // タスクを完了
        //                                $task->update([
        //                                    'status' => 'COMPLETED',
        //                                    'completed_at' => now(),
        //                                ]);
        //
        //                                // このタスクに関連する全ての伝票のピッキングステータスを更新
        //                                // Note: earning_id is now stored in wms_picking_item_results
        //                                $earningIds = $task->pickingItemResults()
        //                                    ->distinct('earning_id')
        //                                    ->whereNotNull('earning_id')
        //                                    ->pluck('earning_id')
        //                                    ->toArray();
        //
        //                                if (!empty($earningIds)) {
        //                                    DB::connection('sakemaru')
        //                                        ->table('earnings')
        //                                        ->whereIn('id', $earningIds)
        //                                        ->update([
        //                                            'picking_status' => 'COMPLETED',
        //                                            'updated_at' => now(),
        //                                        ]);
        //                                }
        //
        //                                $completedCount++;
        //                            }
        //                        });
        //
        //                        Notification::make()
        //                            ->title('一括強制出荷しました')
        //                            ->body("{$completedCount}件のタスク（{$totalItems}商品）を強制出荷しました")
        //                            ->success()
        //                            ->send();
        //                    })
        //                    ->deselectRecordsAfterCompletion()
        //                    ->requiresConfirmation()
        //                    ->modalHeading('一括強制出荷確認')
        //                    ->modalDescription('選択したすべてのタスクを強制出荷します。各タスクのすべての商品のピッキング数が予定数に自動設定され、出荷可能状態になります。この操作は取り消せません。'),
        //            ]);
    }
}
