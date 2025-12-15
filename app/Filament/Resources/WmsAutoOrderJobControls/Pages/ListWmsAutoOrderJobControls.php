<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Pages;

use App\Filament\Resources\WmsAutoOrderJobControls\WmsAutoOrderJobControlResource;
use App\Services\AutoOrder\MultiEchelonCalculationService;
use App\Services\AutoOrder\StockSnapshotService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWmsAutoOrderJobControls extends ListRecords
{
    protected static string $resource = WmsAutoOrderJobControlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runSnapshot')
                ->label('スナップショット実行')
                ->icon('heroicon-o-camera')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('在庫スナップショットの実行')
                ->modalDescription('現在の在庫データのスナップショットを生成します。')
                ->action(function (StockSnapshotService $service) {
                    try {
                        $job = $service->generateAll();
                        Notification::make()
                            ->title("スナップショット完了: {$job->processed_records}件")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('エラー: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('runCalculation')
                ->label('発注計算実行')
                ->icon('heroicon-o-calculator')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('多段階発注計算の実行')
                ->modalDescription('全階層の発注候補・移動候補を計算します。')
                ->action(function (MultiEchelonCalculationService $service) {
                    try {
                        $job = $service->calculateAll();
                        Notification::make()
                            ->title("計算完了: {$job->processed_records}件")
                            ->body("バッチコード: {$job->batch_code}")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('エラー: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('runAllCalculation')
                ->label('一括計算実行')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('自動発注計算の一括実行')
                ->modalDescription('スナップショット生成 → 多段階発注計算を順に実行します。')
                ->action(function (
                    StockSnapshotService $snapshotService,
                    MultiEchelonCalculationService $calculationService
                ) {
                    try {
                        // Phase 0: スナップショット
                        $snapshotJob = $snapshotService->generateAll();

                        // Phase 1: 多段階計算
                        $calcJob = $calculationService->calculateAll();

                        Notification::make()
                            ->title('一括計算完了')
                            ->body("バッチコード: {$calcJob->batch_code}\n" .
                                "スナップショット: {$snapshotJob->processed_records}件\n" .
                                "発注計算: {$calcJob->processed_records}件")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('エラー: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
