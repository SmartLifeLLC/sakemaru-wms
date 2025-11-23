<?php

namespace App\Filament\Resources\WmsShipmentSlips\Tables;

use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingTask;
use App\Services\Print\PrintRequestService;
use App\Services\Shortage\ShortageApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WmsShipmentSlipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('delivery_course_code')
                    ->label('配送コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('shipment_date')
                    ->label('納品日')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('warehouse_code')
                    ->label('倉庫コード')
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable(),

                TextColumn::make('wave.waveSetting.name')
                    ->label('ウェーブ名')
                    ->sortable(),

                TextColumn::make('wave.wave_no')
                    ->label('ウェーブNo')
                    ->sortable(),

                TextColumn::make('floor.name')
                    ->label('フロア')
                    ->sortable(),

                TextColumn::make('pickingArea.name')
                    ->label('エリア')
                    ->sortable(),

                TextColumn::make('is_restricted_area')
                    ->label('制限エリア')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => $state ? 'あり' : 'なし')
                    ->sortable(),

                TextColumn::make('wave.print_count')
                    ->label('Wave印刷回数')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('print_requested_count')
                    ->label('印刷回数')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'PENDING' => '待機中',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        default => $state ?? '-',
                    }),
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

                SelectFilter::make('floor_id')
                    ->label('フロア')
                    ->relationship('floor', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('wms_picking_area_id')
                    ->label('ピッキングエリア')
                    ->relationship('pickingArea', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('is_restricted_area')
                    ->label('制限エリア')
                    ->options([
                        '1' => 'あり',
                        '0' => 'なし',
                    ]),
            ])
            ->recordAction('')
            ->recordActions([
                Action::make('print')
                    ->label(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? '印刷' : '強制印刷';
                    })
                    ->icon('heroicon-o-printer')
                    ->color(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? 'primary' : 'warning';
                    })
                    ->requiresConfirmation()
                    ->modalHeading(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? '出荷伝票印刷' : '強制印刷';
                    })
                    ->modalDescription(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        if (!$printability['can_print']) {
                            return $printability['error_message'] . "\n\nピッキングや欠品対応が完了していない状態でも、現状のまま印刷します。本当に実行しますか？";
                        }

                        return 'この配送コースの伝票を印刷します。';
                    })
                    ->modalSubmitActionLabel(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? '印刷' : '強制印刷';
                    })
                    ->action(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();

                        // 印刷可能性チェック
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        // 印刷依頼を作成
                        $printService = app(PrintRequestService::class);
                        $result = $printService->createPrintRequest(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->warehouse_id,
                            $record->wave_id
                        );

                        if (!$result['success']) {
                            Notification::make()
                                ->title('エラー')
                                ->body($result['message'])
                                ->danger()
                                ->send();
                            return;
                        }

                        // 同じ配送コース・納品日・Waveのタスクをすべて取得
                        $query = WmsPickingTask::where('shipment_date', $systemDate)
                            ->where('delivery_course_id', $record->delivery_course_id);
                        
                        if ($record->wave_id) {
                            $query->where('wave_id', $record->wave_id);
                        }
                        
                        $tasksToUpdate = $query->get();

                        // print_requested_countをインクリメント
                        foreach ($tasksToUpdate as $task) {
                            $task->increment('print_requested_count');
                        }

                        // Waveの印刷回数をインクリメント
                        if ($record->wave) {
                            $record->wave->increment('print_count');
                        }

                        $title = $printability['can_print'] ? '印刷依頼' : '強制印刷依頼';
                        $notificationType = $printability['can_print'] ? 'success' : 'warning';

                        Notification::make()
                            ->title($title)
                            ->body('伝票印刷を依頼しました。（売上' . $result['earning_count'] . '件、タスク' . $tasksToUpdate->count() . '件）')
                            ->{$notificationType}()
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkAction::make('bulkPrint')
                    ->label('一括印刷')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('一括印刷')
                    ->modalDescription(fn (Collection $records): string =>
                        "選択された {$records->count()} 件の配送コースの伝票を印刷します。"
                    )
                    ->modalSubmitActionLabel('一括印刷')
                    ->action(function (Collection $records): void {
                        $systemDate = ClientSetting::systemDate();
                        $printService = app(PrintRequestService::class);
                        $successCount = 0;
                        $errorCount = 0;
                        $totalEarnings = 0;
                        $totalTasks = 0;

                        foreach ($records as $record) {
                            try {
                                // 印刷依頼を作成
                                $result = $printService->createPrintRequest(
                                    $record->delivery_course_id,
                                    $systemDate->format('Y-m-d'),
                                    $record->warehouse_id,
                                    $record->wave_id
                                );

                                if ($result['success']) {
                                    // 同じ配送コース・納品日・Waveのタスクをすべて取得
                                    $query = WmsPickingTask::where('shipment_date', $systemDate)
                                        ->where('delivery_course_id', $record->delivery_course_id);

                                    if ($record->wave_id) {
                                        $query->where('wave_id', $record->wave_id);
                                    }

                                    $tasksToUpdate = $query->get();

                                    // print_requested_countをインクリメント
                                    foreach ($tasksToUpdate as $task) {
                                        $task->increment('print_requested_count');
                                    }

                                    // Waveの印刷回数をインクリメント
                                    if ($record->wave) {
                                        $record->wave->increment('print_count');
                                    }

                                    $successCount++;
                                    $totalEarnings += $result['earning_count'];
                                    $totalTasks += $tasksToUpdate->count();
                                } else {
                                    $errorCount++;
                                }
                            } catch (\Exception $e) {
                                $errorCount++;
                            }
                        }

                        $message = "印刷依頼完了: {$successCount}件成功";
                        if ($errorCount > 0) {
                            $message .= "、{$errorCount}件失敗";
                        }
                        $message .= "（売上{$totalEarnings}件、タスク{$totalTasks}件）";

                        Notification::make()
                            ->title('一括印刷')
                            ->body($message)
                            ->success()
                            ->send();
                    }),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                // system_dateを取得
                $systemDate = ClientSetting::systemDate();

                // shipment_dateが営業日(system_date)のもののみ
                // 各配送コース・納品日・Waveごとに最初のレコードのみ取得
                $query->where('shipment_date', $systemDate)
                    ->whereIn('id', function ($subQuery) use ($systemDate) {
                        $subQuery->select(DB::raw('MIN(id)'))
                            ->from('wms_picking_tasks')
                            ->where('shipment_date', $systemDate)
                            ->groupBy('delivery_course_id', 'shipment_date', 'wave_id', 'floor_id', 'wms_picking_area_id', 'is_restricted_area');
                    })
                    ->with(['deliveryCourse', 'warehouse', 'wave.waveSetting', 'floor', 'pickingArea']);
            })
            ->defaultSort('delivery_course_code', 'asc');
    }
}
