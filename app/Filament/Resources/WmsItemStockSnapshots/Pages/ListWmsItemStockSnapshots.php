<?php

namespace App\Filament\Resources\WmsItemStockSnapshots\Pages;

use App\Filament\Resources\WmsItemStockSnapshots\WmsItemStockSnapshotResource;
use App\Models\WmsItemStockSnapshot;
use App\Services\AutoOrder\StockSnapshotService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWmsItemStockSnapshots extends ListRecords
{
    protected static string $resource = WmsItemStockSnapshotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerate')
                ->label('スナップショット再生成')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('スナップショット再生成')
                ->modalDescription('現在のスナップショットデータを削除し、最新の在庫データから再生成します。よろしいですか？')
                ->modalSubmitActionLabel('再生成する')
                ->action(function () {
                    try {
                        $service = app(StockSnapshotService::class);
                        $job = $service->generateAll();

                        Notification::make()
                            ->title('スナップショット再生成完了')
                            ->body("処理件数: {$job->processed_count} 件")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('スナップショット再生成失敗')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            //
        ];
    }

    public function getSubheading(): ?string
    {
        $snapshot = WmsItemStockSnapshot::first();
        if ($snapshot) {
            return 'スナップショット日時: '.$snapshot->snapshot_at->format('Y年m月d日 H:i:s');
        }

        return 'スナップショットがありません';
    }
}
