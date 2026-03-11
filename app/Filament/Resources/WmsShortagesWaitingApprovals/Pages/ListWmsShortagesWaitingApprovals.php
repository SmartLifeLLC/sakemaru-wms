<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals\Pages;

use App\Actions\Wms\ConfirmShortageAllocations;
use App\Filament\Resources\WmsShortagesWaitingApprovals\WmsShortagesWaitingApprovalResource;
use App\Models\WmsShortage;
use App\Services\QuantityUpdate\QuantityUpdateQueueService;
use App\Services\Shortage\ShortageApprovalService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWmsShortagesWaitingApprovals extends ListRecords
{
    protected static string $resource = WmsShortagesWaitingApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmAll')
                ->label('全承認')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('全件承認しますか？')
                ->modalDescription('承認待ちの欠品対応を全て承認します。')
                ->action(function () {
                    $shortages = WmsShortage::where('is_confirmed', false)
                        ->where('status', '!=', WmsShortage::STATUS_BEFORE)
                        ->get();

                    if ($shortages->isEmpty()) {
                        Notification::make()
                            ->title('承認対象なし')
                            ->body('承認待ちのレコードがありません。')
                            ->warning()
                            ->send();

                        return;
                    }

                    $count = 0;
                    $totalAllocationsConfirmed = 0;
                    $queueCreated = 0;

                    $queueService = app(QuantityUpdateQueueService::class);
                    $approvalService = app(ShortageApprovalService::class);

                    foreach ($shortages as $shortage) {
                        try {
                            $shortage->is_confirmed = true;
                            $shortage->confirmed_by = auth()->id();
                            $shortage->confirmed_at = now();
                            $shortage->confirmed_user_id = auth()->id();
                            $shortage->save();
                            $count++;

                            $confirmedAllocationsCount = ConfirmShortageAllocations::execute(
                                wmsShortageId: $shortage->id,
                                confirmedUserId: auth()->id() ?? 0
                            );
                            $totalAllocationsConfirmed += $confirmedAllocationsCount;

                            $queue = $queueService->createQueueForShortageApproval($shortage);
                            if ($queue) {
                                $queueCreated++;
                            }

                            $approvalService->updatePickingTaskStatusAfterApproval($shortage);
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body("欠品ID {$shortage->id} の処理に失敗: {$e->getMessage()}")
                                ->danger()
                                ->send();
                        }
                    }

                    $message = "{$count}件の欠品対応を承認しました";
                    if ($totalAllocationsConfirmed > 0) {
                        $message .= "（代理出荷{$totalAllocationsConfirmed}件承認）";
                    }
                    if ($queueCreated > 0) {
                        $message .= "（在庫更新キュー{$queueCreated}件作成）";
                    }

                    Notification::make()
                        ->title('全承認完了')
                        ->body($message)
                        ->success()
                        ->send();
                }),
        ];
    }
}
