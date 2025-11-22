<?php

namespace App\Filament\Resources\WmsShipmentSlips\Tables;

use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingTask;
use App\Services\Print\PrintRequestService;
use App\Services\Shortage\ShortageApprovalService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

                TextColumn::make('print_requested_count')
                    ->label('印刷依頼回数')
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
                //
            ])
            ->recordAction('printSlip')
            ->recordActions([
                Action::make('forcePrintSlip')
                    ->label('強制印刷')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('強制印刷')
                    ->modalDescription('ピッキングや欠品対応が完了していない状態でも、現状のまま印刷します。本当に実行しますか？')
                    ->modalSubmitActionLabel('強制印刷')
                    ->action(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();

                        // 印刷依頼を作成
                        $printService = app(PrintRequestService::class);
                        $result = $printService->createPrintRequest(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->warehouse_id
                        );

                        if (!$result['success']) {
                            Notification::make()
                                ->title('エラー')
                                ->body($result['message'])
                                ->danger()
                                ->send();
                            return;
                        }

                        // 同じ配送コース・納品日のタスクをすべて取得
                        $tasksToUpdate = WmsPickingTask::where('shipment_date', $systemDate)
                            ->where('delivery_course_id', $record->delivery_course_id)
                            ->get();

                        // print_requested_countをインクリメント
                        foreach ($tasksToUpdate as $task) {
                            $task->increment('print_requested_count');
                        }

                        Notification::make()
                            ->title('強制印刷依頼')
                            ->body('伝票印刷を強制実行しました。（売上' . $result['earning_count'] . '件、タスク' . $tasksToUpdate->count() . '件）')
                            ->warning()
                            ->send();
                    }),

                Action::make('printSlip')
                    ->label('印刷')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d')
                        );

                        return $printability['can_print'] ? '出荷伝票印刷' : '印刷できません';
                    })
                    ->modalDescription(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d')
                        );

                        if (!$printability['can_print']) {
                            return $printability['error_message'];
                        }

                        return 'この配送コースの伝票を印刷します。';
                    })
                    ->modalSubmitActionLabel(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d')
                        );

                        return $printability['can_print'] ? '印刷' : '閉じる';
                    })
                    ->modalCancelAction(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d')
                        );

                        // エラーの場合はキャンセルボタンを非表示
                        return $printability['can_print'] ? null : false;
                    })
                    ->color(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d')
                        );

                        return $printability['can_print'] ? 'primary' : 'danger';
                    })
                    ->action(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();

                        // 印刷可能性チェック（再度実行）
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d')
                        );

                        if (!$printability['can_print']) {
                            // エラーの場合は何もしない
                            return;
                        }

                        // 印刷依頼を作成
                        $printService = app(PrintRequestService::class);
                        $result = $printService->createPrintRequest(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->warehouse_id
                        );

                        if (!$result['success']) {
                            Notification::make()
                                ->title('エラー')
                                ->body($result['message'])
                                ->danger()
                                ->send();
                            return;
                        }

                        // 同じ配送コース・納品日のタスクをすべて取得
                        $tasksToUpdate = WmsPickingTask::where('shipment_date', $systemDate)
                            ->where('delivery_course_id', $record->delivery_course_id)
                            ->get();

                        // print_requested_countをインクリメント
                        foreach ($tasksToUpdate as $task) {
                            $task->increment('print_requested_count');
                        }

                        Notification::make()
                            ->title('印刷依頼')
                            ->body('伝票印刷を依頼しました。（売上' . $result['earning_count'] . '件、タスク' . $tasksToUpdate->count() . '件）')
                            ->success()
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->modifyQueryUsing(function (Builder $query) {
                // system_dateを取得
                $systemDate = ClientSetting::systemDate();

                // shipment_dateが営業日(system_date)のもののみ
                // 各配送コース・納品日ごとに最初のレコードのみ取得
                $query->where('shipment_date', $systemDate)
                    ->whereIn('id', function ($subQuery) use ($systemDate) {
                        $subQuery->select(DB::raw('MIN(id)'))
                            ->from('wms_picking_tasks')
                            ->where('shipment_date', $systemDate)
                            ->groupBy('delivery_course_id', 'shipment_date');
                    })
                    ->with(['deliveryCourse', 'warehouse']);
            })
            ->defaultSort('delivery_course_code', 'asc');
    }
}
