<?php

namespace App\Filament\Resources\WaveSettings\Pages;

use App\Filament\Resources\WaveSettings\WaveSettingResource;
use App\Services\Warehouse91StockLotSyncService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Throwable;

class ListWaveSettings extends ListRecords
{
    protected static string $resource = WaveSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncWarehouse91StockLots')
                ->label('在庫同期')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->modalHeading('91倉庫 在庫同期')
                ->modalDescription('real_stocksを正として、91倉庫のACTIVEロット数量を同期します。')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn (Action $action) => $action->label('在庫同期を実行')->color('danger'))
                ->modalCancelActionLabel('同期せず閉じる')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalContent(fn () => view('filament.resources.wave-settings.stock-lot-sync-preview', [
                    'preview' => app(Warehouse91StockLotSyncService::class)->preview(),
                ]))
                ->action(function (): void {
                    try {
                        $result = app(Warehouse91StockLotSyncService::class)->sync();
                        $before = $result['before'];
                        $after = $result['after_remaining'];

                        Notification::make()
                            ->title('91倉庫の在庫を同期しました')
                            ->body(
                                "対象: {$before['rows']}件 / 既存更新: {$before['update_lot']}件 / 新規作成: {$before['create_lot']}件\n".
                                "残差分: {$after['rows']}件"
                            )
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('在庫同期に失敗しました')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
