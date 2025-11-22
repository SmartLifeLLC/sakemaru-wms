<?php

namespace App\Filament\Resources\WmsPickingItemResults\Tables;

use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class WmsPickingItemResultsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('pickingTask.id')
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
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('trade.serial_id')
                    ->label('識別ID')
                    ->searchable()
                    ->sortable()
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('earning.buyer.partner.code')
                    ->label('得意先コード')
                    ->searchable()
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('earning.buyer.partner.name')
                    ->label('得意先名')
                    ->searchable()
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                SelectColumn::make('delivery_course_id')
                    ->label('配送コース')
                    ->searchable()
                    ->sortable()
                    ->disabled(fn ($record) => $record->status !== 'PENDING')
                    ->options(function ($record) {
                        if (!$record->earning || !$record->earning->warehouse_id) {
                            return [];
                        }

                        return \App\Models\Sakemaru\DeliveryCourse::where('warehouse_id', $record->earning->warehouse_id)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($course) => [$course->id => "{$course->code} - {$course->name}"])
                            ->toArray();
                    })
                    ->getStateUsing(function ($record) {
                        return $record->earning?->delivery_course_id;
                    })
                    ->updateStateUsing(function ($record, $state) {
                        // ステータスチェック: PENDINGのみ変更可能
                        if ($record->status !== 'PENDING') {
                            Notification::make()
                                ->title('変更できません')
                                ->body('配送コースの変更は未着手の商品のみ可能です')
                                ->danger()
                                ->send();
                            return;
                        }

                        $earning = $record->earning;
                        if (!$earning) {
                            Notification::make()
                                ->title('エラー')
                                ->body('伝票情報が見つかりません')
                                ->danger()
                                ->send();
                            return;
                        }

                        $newCourse = \App\Models\Sakemaru\DeliveryCourse::find($state);

                        // 配送コース存在チェック
                        if (!$newCourse) {
                            Notification::make()
                                ->title('エラー')
                                ->body('指定された配送コースが見つかりません')
                                ->danger()
                                ->send();
                            return;
                        }

                        // 倉庫一致チェック: 同じ倉庫の配送コースのみ
                        if ($newCourse->warehouse_id !== $earning->warehouse_id) {
                            Notification::make()
                                ->title('変更できません')
                                ->body('異なる倉庫の配送コースは選択できません')
                                ->danger()
                                ->send();
                            return;
                        }

                        DB::connection('sakemaru')->transaction(function () use ($earning, $newCourse, $record) {
                            // 元の配送コース情報を取得
                            $oldCourse = \App\Models\Sakemaru\DeliveryCourse::find($earning->delivery_course_id);
                            $oldCourseCode = $oldCourse ? $oldCourse->code : '未設定';

                            // earningの配送コースを更新
                            $earning->update([
                                'delivery_course_id' => $newCourse->id,
                                'updated_at' => now(),
                            ]);

                            // 同じearning_idを持つピッキングタスクの配送コースも更新
                            DB::connection('sakemaru')
                                ->table('wms_picking_tasks')
                                ->whereIn('id', function ($query) use ($earning) {
                                    $query->select('picking_task_id')
                                        ->from('wms_picking_item_results')
                                        ->where('earning_id', $earning->id);
                                })
                                ->update([
                                    'delivery_course_id' => $newCourse->id,
                                    'delivery_course_code' => $newCourse->code,
                                    'updated_at' => now(),
                                ]);

                            // trades.noteに変更履歴を追記
                            if ($record->trade) {
                                $trade = $record->trade;
                                $changeNote = " [配送コース変更]:{$oldCourseCode}";

                                // 現在のnoteに追記
                                $currentNote = $trade->note ?? '';
                                $newNote = $currentNote . $changeNote;

                                $trade->update([
                                    'note' => $newNote,
                                    'updated_at' => now(),
                                ]);
                            }
                        });

                        Notification::make()
                            ->title('配送コースを変更しました')
                            ->body("伝票ID {$earning->id} の配送コースを「{$newCourse->name}」に変更しました")
                            ->success()
                            ->send();
                    })
                    ->selectablePlaceholder(false)
                    ->extraAttributes([
                        'class' => 'p-0',
                        'style' => 'padding: 0 !important;'
                    ])
                    ->extraInputAttributes([
                        'class' => 'text-sm border-0 focus:ring-0',
                        'style' => 'padding: 0.25rem 0.5rem !important; min-height: 0 !important; height: auto !important;'
                    ]),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('location_display')
                    ->label('ロケーション')
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('walking_order')
                    ->label('歩行順序')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ordered_qty')
                    ->label('注文数')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($record) => $record->ordered_qty . ' ' . ($record->ordered_qty_type_display ?? ''))
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('planned_qty')
                    ->label('予定数')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($record) => $record->planned_qty . ' ' . ($record->planned_qty_type_display ?? ''))
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('picked_qty')
                    ->label('実績数')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($record) => ($record->picked_qty ?? '-') . ($record->picked_qty ? ' ' . ($record->picked_qty_type_display ?? '') : ''))
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('shortage_qty')
                    ->label('欠品数')
                    ->numeric()
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('picked_at')
                    ->label('ピッキング日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('picking_task_id')
                    ->label('ピッキングタスク')
                    ->relationship('pickingTask', 'id')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('earning_id')
                    ->label('伝票ID')
                    ->relationship('earning', 'id')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                    ]),

                SelectFilter::make('has_shortage')
                    ->label('欠品状況')
                    ->options([
                        '1' => '欠品あり',
                        '0' => '欠品なし',
                    ])
                    ->query(function ($query, $state) {
                        if ($state['value'] === '1') {
                            return $query->where('shortage_qty', '>', 0);
                        } elseif ($state['value'] === '0') {
                            return $query->where(function ($q) {
                                $q->where('shortage_qty', 0)
                                    ->orWhereNull('shortage_qty');
                            });
                        }
                        return $query;
                    }),
            ])
            ->defaultSort('walking_order', 'asc')
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }
}
